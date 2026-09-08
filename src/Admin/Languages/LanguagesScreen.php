<?php
/**
 * Languages administration screen.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin\Languages;

use AIMultilingual\Admin\SettingsPage;
use AIMultilingual\Language\Languages;
use WP_Error;

/**
 * The Languages list, add and edit screen.
 *
 * Extracted verbatim from SettingsPage so the screen has a focused home before
 * the selection-based redesign. Behaviour is unchanged: the same menu slug and
 * hook suffixes, the same `admin-post.php` handlers, the same nonce and
 * `manage_options` checks, the same rendered markup and redirects.
 *
 * Milestone 1 ships no REST API for this screen (ADR-0002); writes go through
 * `admin-post.php`. Every write checks both a nonce and `manage_options`.
 */
final class LanguagesScreen {

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Builds the languages screen.
	 *
	 * @param Languages $languages Language configuration.
	 */
	public function __construct( Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Registers the form handlers. The menu entry itself is registered by
	 * SettingsPage, which owns the plugin's top-level menu.
	 */
	public function register(): void {
		add_action( 'admin_post_aiml_save_language', array( $this, 'handle_save' ) );
		add_action( 'admin_post_aiml_delete_language', array( $this, 'handle_delete' ) );
	}

	/**
	 * Renders the Languages screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		$languages = $this->languages->all();
		$editing   = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		if ( isset( $_GET['language_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = $this->languages->find( (int) $_GET['language_id'] );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Languages', 'universal-multilingual' ) . '</h1>';

		$this->render_notice();

		echo '<table class="widefat striped" style="margin-bottom:2em;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Code', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Locale', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Default', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'universal-multilingual' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $languages as $language ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $language->code ) . '</code></td>';
			echo '<td>' . esc_html( (string) $language->locale ) . '</td>';
			echo '<td>' . esc_html( (string) $language->name ) . '</td>';
			echo '<td>' . esc_html( $this->status_label( (string) $language->status ) ) . '</td>';
			echo '<td>' . ( $language->is_default ? '&#10003;' : '' ) . '</td>';
			echo '<td>';

			printf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'        => SettingsPage::MENU_SLUG,
							'language_id' => (int) $language->language_id,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Edit', 'universal-multilingual' )
			);

			if ( ! $language->is_default ) {
				echo ' | ';
				printf(
					'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
					esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action'      => 'aiml_delete_language',
									'language_id' => (int) $language->language_id,
								),
								admin_url( 'admin-post.php' )
							),
							'aiml_delete_language_' . (int) $language->language_id
						)
					),
					esc_js( __( 'Delete this language? Its translations are kept.', 'universal-multilingual' ) ),
					esc_html__( 'Delete', 'universal-multilingual' )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$this->render_form( $editing );

		echo '</div>';
	}

	/**
	 * Creates or updates a language from the add/edit form.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_save_language' );

		$language_id = isset( $_POST['language_id'] ) ? (int) $_POST['language_id'] : 0;

		$data = array(
			'code'        => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'locale'      => isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '',
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'native_name' => isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( $_POST['native_name'] ) ) : '',
			'direction'   => isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : 'ltr',
			'status'      => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : Languages::STATUS_PREVIEW,
			'sort_order'  => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
		);

		$result = $language_id > 0
			? $this->languages->update( $language_id, $data )
			: $this->languages->insert( $data );

		$this->redirect_with_result( $result );
	}

	/**
	 * Deletes a language, keeping its translations.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		$language_id = isset( $_GET['language_id'] ) ? (int) $_GET['language_id'] : 0;

		check_admin_referer( 'aiml_delete_language_' . $language_id );

		$this->redirect_with_result( $this->languages->delete( $language_id ) );
	}

	// -- Rendering helpers --

	/**
	 * Renders the add/edit language form.
	 *
	 * @param object|null $editing Language being edited, or null to add.
	 */
	private function render_form( ?object $editing ): void {
		$is_edit    = null !== $editing;
		$is_default = $is_edit && ! empty( $editing->is_default );

		echo '<h2>' . esc_html( $is_edit ? __( 'Edit language', 'universal-multilingual' ) : __( 'Add a language', 'universal-multilingual' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aiml_save_language" />';

		wp_nonce_field( 'aiml_save_language' );

		if ( $is_edit ) {
			echo '<input type="hidden" name="language_id" value="' . esc_attr( (string) (int) $editing->language_id ) . '" />';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'code', __( 'URL code', 'universal-multilingual' ), $is_edit ? (string) $editing->code : '', __( 'Two lowercase letters, optionally with a region: sv, pt-br. Appears in the URL as /sv/.', 'universal-multilingual' ) );
		$this->text_row( 'locale', __( 'Locale', 'universal-multilingual' ), $is_edit ? (string) $editing->locale : '', __( 'WordPress locale, for example sv_SE.', 'universal-multilingual' ) );
		$this->text_row( 'name', __( 'Name', 'universal-multilingual' ), $is_edit ? (string) $editing->name : '', __( 'English name, for example Swedish.', 'universal-multilingual' ) );
		$this->text_row( 'native_name', __( 'Native name', 'universal-multilingual' ), $is_edit ? (string) $editing->native_name : '', __( 'The language in its own words, for example Svenska.', 'universal-multilingual' ) );

		// Direction.
		echo '<tr><th scope="row"><label for="aiml-direction">' . esc_html__( 'Text direction', 'universal-multilingual' ) . '</label></th><td>';
		echo '<select name="direction" id="aiml-direction">';
		foreach ( Languages::DIRECTIONS as $direction ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $direction ),
				selected( $is_edit ? (string) $editing->direction : 'ltr', $direction, false )
			);
		}
		echo '</select></td></tr>';

		// State.
		echo '<tr><th scope="row"><label for="aiml-status">' . esc_html__( 'State', 'universal-multilingual' ) . '</label></th><td>';

		if ( $is_default ) {
			echo '<p><strong>' . esc_html__( 'Published', 'universal-multilingual' ) . '</strong><br />';
			echo '<span class="description">' . esc_html__( 'The default language is the source content, so it is always published and always unprefixed.', 'universal-multilingual' ) . '</span></p>';
		} else {
			echo '<select name="status" id="aiml-status">';
			foreach ( Languages::statuses() as $status ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $status ),
					selected( $is_edit ? (string) $editing->status : Languages::STATUS_PREVIEW, $status, false ),
					esc_html( $this->status_label( $status ) )
				);
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Preview: visible only to users who can translate. Published: visible to everyone. Disabled: not routed at all. A disabled language returns through preview before it can be published again.', 'universal-multilingual' ) . '</p>';
		}

		echo '</td></tr>';

		$this->text_row( 'sort_order', __( 'Sort order', 'universal-multilingual' ), $is_edit ? (string) (int) $editing->sort_order : '0', __( 'Order in the language switcher.', 'universal-multilingual' ) );

		echo '</tbody></table>';

		submit_button( $is_edit ? __( 'Save language', 'universal-multilingual' ) : __( 'Add language', 'universal-multilingual' ) );

		echo '</form>';
	}

	/**
	 * Renders a labelled text input row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $description Help text.
	 */
	private function text_row( string $name, string $label, string $value, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="aiml-%1$s">%2$s</label></th><td>'
			. '<input type="text" class="regular-text" id="aiml-%1$s" name="%1$s" value="%3$s" />'
			. '<p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_html( $description )
		);
	}

	/**
	 * Maps a language status to its display label.
	 *
	 * @param string $status Stored status.
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case Languages::STATUS_PUBLISHED:
				return __( 'Published', 'universal-multilingual' );

			case Languages::STATUS_DISABLED:
				return __( 'Disabled', 'universal-multilingual' );

			case Languages::STATUS_PREVIEW:
			default:
				return __( 'Preview', 'universal-multilingual' );
		}
	}

	/**
	 * Redirects back to the Languages screen carrying the outcome of a write.
	 *
	 * @param true|int|WP_Error $result Outcome from the languages store.
	 */
	private function redirect_with_result( $result ): void {
		$args = array( 'page' => SettingsPage::MENU_SLUG );

		if ( $result instanceof WP_Error ) {
			$args['aiml_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aiml_updated'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Prints the success or error notice carried on the query string.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only feedback.
		if ( isset( $_GET['aiml_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['aiml_error'] ) ) ) )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['aiml_updated'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Saved.', 'universal-multilingual' )
			);
		}
	}
}
