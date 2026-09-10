<?php
/**
 * Languages administration screen.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin\Languages;

use AIMultilingual\Admin\SettingsPage;
use AIMultilingual\Language\DerivedLanguageMetadata;
use AIMultilingual\Language\ExistingLanguage;
use AIMultilingual\Language\LanguageRegistry;
use AIMultilingual\Language\Languages;
use WP_Error;

/**
 * The Languages list, add and edit screen, redesigned around selection
 * (ADR-0028, ADR-0029).
 *
 * Adding a curated language is a choice, not data entry: the administrator
 * picks a language (and a region where the language has more than one locale),
 * a status and a sort order. The server derives the URL code, English name,
 * native name and text direction from the curated registry — it never trusts
 * the browser for those. A gated "Advanced: custom language" disclosure keeps
 * the old raw-field path for locales outside the registry.
 *
 * On editing, `locale` and `code` are immutable for every row; changing either
 * means delete + re-add. Milestone 1 ships no REST API for this screen
 * (ADR-0002); writes go through `admin-post.php` with a nonce and
 * `manage_options`.
 */
final class LanguagesScreen {

	/**
	 * Enqueue handle for the screen's stylesheet and script.
	 */
	public const ASSET_HANDLE = 'aiml-languages-admin';

	/**
	 * Body class the screen's CSS is scoped under.
	 */
	public const BODY_CLASS = 'aiml-languages-page';

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Canonical locale metadata.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Builds the languages screen.
	 *
	 * @param Languages             $languages Language configuration.
	 * @param LanguageRegistry|null $registry  Canonical locale metadata.
	 */
	public function __construct( Languages $languages, ?LanguageRegistry $registry = null ) {
		$this->languages = $languages;
		$this->registry  = $registry ?? new LanguageRegistry();
	}

	/**
	 * Registers the form handlers and screen assets. The menu entry itself is
	 * registered by SettingsPage, which owns the plugin's top-level menu.
	 */
	public function register(): void {
		add_action( 'admin_post_aiml_save_language', array( $this, 'handle_save' ) );
		add_action( 'admin_post_aiml_delete_language', array( $this, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Hook suffixes under which the Languages screen renders.
	 *
	 * @return string[]
	 */
	private function screen_hooks(): array {
		return array(
			'toplevel_page_' . SettingsPage::MENU_SLUG,
			'multilingual_page_' . SettingsPage::MENU_SLUG,
		);
	}

	/**
	 * Enqueues the screen's stylesheet and combobox script, and only there.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->screen_hooks(), true ) ) {
			return;
		}

		$version = defined( 'AIML_VERSION' ) ? AIML_VERSION : '0.1.0';

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'aiml-admin-ui',
			plugins_url( 'assets/admin-ui/aiml-ui.css', AIML_PLUGIN_FILE ),
			array( 'wp-components' ),
			$version
		);
		wp_enqueue_style(
			self::ASSET_HANDLE,
			plugins_url( 'assets/languages-admin/languages-admin.css', AIML_PLUGIN_FILE ),
			array( 'aiml-admin-ui' ),
			$version
		);
		wp_enqueue_script(
			self::ASSET_HANDLE,
			plugins_url( 'assets/languages-admin/languages-admin.js', AIML_PLUGIN_FILE ),
			array( 'wp-element', 'wp-components' ),
			$version,
			true
		);
		wp_localize_script( self::ASSET_HANDLE, 'aimlLanguagesAdmin', $this->js_payload() );
	}

	/**
	 * Adds the scoping body class on the Languages screen.
	 *
	 * @param string $classes Space-separated admin body classes.
	 */
	public function body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && in_array( (string) $screen->id, $this->screen_hooks(), true ) ) {
			$classes .= ' aiml-ui ' . self::BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * The registry / existing-language blob the combobox script reads.
	 *
	 * @return array<string, mixed>
	 */
	private function js_payload(): array {
		$existing = $this->existing_identities();

		$groups = array();
		foreach ( $this->registry->groups() as $group ) {
			$groups[ $group->key ] = array(
				'language_code' => $group->language_code,
				'english_name'  => $group->english_name,
				'native_name'   => $group->native_name,
				'direction'     => $group->direction,
				'locales'       => $group->locales,
			);
		}

		$locales = array();
		foreach ( $this->registry->groups() as $group ) {
			foreach ( $this->registry->locales_for( $group->key ) as $meta ) {
				$derived = DerivedLanguageMetadata::derive( $meta->locale, $group->key, $existing, null, $this->registry );

				$locales[ $meta->locale ] = array(
					'language_code' => $meta->language_code,
					'english_name'  => $meta->english_name,
					'native_name'   => $meta->native_name,
					'direction'     => $meta->direction,
					'region'        => $meta->region,
					'region_label'  => $meta->region_label,
					'preview_code'  => is_wp_error( $derived ) ? $group->language_code : $derived['code'],
				);
			}
		}

		$existing_js = array();
		foreach ( $existing as $row ) {
			$existing_js[] = array(
				'code'       => $row->code,
				'locale'     => $row->locale,
				'group'      => $row->group,
				'is_default' => $row->is_default,
			);
		}

		return array(
			'groups'   => $groups,
			'locales'  => $locales,
			'existing' => $existing_js,
			'i18n'     => array(
				'languageLabel' => __( 'Language', 'universal-multilingual' ),
				'rtl'           => __( 'Right to left', 'universal-multilingual' ),
				'ltr'           => __( 'Left to right', 'universal-multilingual' ),
				'alreadyAdded'  => __( 'already added', 'universal-multilingual' ),
			),
		);
	}

	/**
	 * Identities of the languages already stored, for URL-code derivation.
	 *
	 * @return ExistingLanguage[]
	 */
	private function existing_identities(): array {
		$out = array();
		foreach ( $this->languages->all() as $row ) {
			$out[] = ExistingLanguage::from_row( $row, $this->registry );
		}

		return $out;
	}

	/**
	 * Renders the Languages screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		$editing = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		if ( isset( $_GET['language_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = $this->languages->find( (int) $_GET['language_id'] );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Languages', 'universal-multilingual' ) . '</h1>';
		echo '<div class="aiml-ui-layout">';

		$this->render_hero();
		\AIMultilingual\Admin\AdminNavigation::render( SettingsPage::MENU_SLUG );
		$this->render_notice();
		$this->render_list();

		if ( null !== $editing ) {
			$this->render_edit_card( $editing );
		} else {
			$this->render_add_card();
			$this->render_custom_form();
		}

		echo '</div></div>';
	}

	/**
	 * The header hero.
	 */
	private function render_hero(): void {
		echo '<header class="aiml-ui-hero">';
		echo '<span class="aiml-ui-hero__mark"><span class="dashicons dashicons-translation" aria-hidden="true"></span></span>';
		echo '<span class="aiml-ui-hero__titles">';
		echo '<h2 class="aiml-ui-hero__title">' . esc_html__( 'Languages', 'universal-multilingual' ) . '</h2>';
		echo '<p class="aiml-ui-hero__subtitle">' . esc_html__( 'The languages this site is translated into, and the order they appear in the switcher.', 'universal-multilingual' ) . '</p>';
		echo '</span></header>';
	}

	/**
	 * The list of configured languages.
	 */
	private function render_list(): void {
		$languages = $this->languages->all();

		echo '<div class="aiml-ui-card"><div class="aiml-ui-card__body">';

		if ( count( $languages ) < 2 ) {
			echo '<p class="aiml-ui-empty">' . esc_html__( 'Only the site language is configured. Add a language below to start translating.', 'universal-multilingual' ) . '</p>';
		}

		echo '<table class="aiml-ui-list"><thead><tr>';
		foreach ( array(
			__( 'Code', 'universal-multilingual' ),
			__( 'Locale', 'universal-multilingual' ),
			__( 'Name', 'universal-multilingual' ),
			__( 'State', 'universal-multilingual' ),
			__( 'Default', 'universal-multilingual' ),
			__( 'Actions', 'universal-multilingual' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $languages as $language ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $language->code ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $language->locale ) . '</code></td>';
			echo '<td>' . esc_html( (string) $language->name ) . '</td>';
			echo '<td>' . $this->status_badge( (string) $language->status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html.
			echo '<td>' . ( $language->is_default ? '<span aria-hidden="true">&#10003;</span><span class="screen-reader-text">' . esc_html__( 'Site language', 'universal-multilingual' ) . '</span>' : '' ) . '</td>';
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

		echo '</tbody></table></div></div>';
	}

	/**
	 * The selection-based "Add a language" card.
	 */
	private function render_add_card(): void {
		$used_locales = array();
		foreach ( $this->languages->all() as $row ) {
			$used_locales[] = (string) $row->locale;
		}

		$this->card_open(
			__( 'Add a language', 'universal-multilingual' ),
			__( 'Choose a language. The URL, locale, name and text direction are filled in for you.', 'universal-multilingual' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aiml_save_language" />';
		wp_nonce_field( 'aiml_save_language' );

		echo '<div class="aiml-ui-field">';
		echo '<label class="aiml-ui-field__label" for="aiml-language-select">' . esc_html__( 'Language', 'universal-multilingual' ) . '</label>';
		echo '<span class="aiml-ui-field__description">' . esc_html__( 'Search by English or native name.', 'universal-multilingual' ) . '</span>';
		echo '<div class="aiml-ui-field__control"><div class="aiml-language-combobox"></div>';
		echo '<select id="aiml-language-select" name="registry_group" class="aiml-language-select-fallback">';
		foreach ( $this->registry->groups() as $group ) {
			if ( array() === array_diff( $group->locales, $used_locales ) ) {
				continue; // Every locale in this group is already registered.
			}
			printf(
				'<option value="%1$s">%2$s &mdash; %3$s</option>',
				esc_attr( $group->key ),
				esc_html( $group->english_name ),
				esc_html( $group->native_name )
			);
		}
		echo '</select></div></div>';

		echo '<div class="aiml-ui-field aiml-ui-field--hidden" data-aiml-region-field>';
		echo '<label class="aiml-ui-field__label" for="aiml-region-select">' . esc_html__( 'Regional variant', 'universal-multilingual' ) . '</label>';
		echo '<div class="aiml-ui-field__control"><select id="aiml-region-select" name="locale"></select></div>';
		echo '</div>';

		echo '<div class="aiml-ui-summary" data-aiml-summary hidden>';
		echo '<p class="aiml-ui-summary__title">' . esc_html__( 'This language will be added as', 'universal-multilingual' ) . '</p>';
		echo '<dl class="aiml-ui-summary__grid">';
		echo '<dt>' . esc_html__( 'URL prefix', 'universal-multilingual' ) . '</dt><dd><code data-aiml-summary-url></code></dd>';
		echo '<dt>' . esc_html__( 'Locale', 'universal-multilingual' ) . '</dt><dd><code data-aiml-summary-locale></code></dd>';
		echo '<dt>' . esc_html__( 'Native name', 'universal-multilingual' ) . '</dt><dd data-aiml-summary-native></dd>';
		echo '<dt>' . esc_html__( 'Direction', 'universal-multilingual' ) . '</dt><dd data-aiml-summary-direction></dd>';
		echo '</dl></div>';

		$this->status_field( Languages::STATUS_PREVIEW );
		$this->sort_order_field( $this->next_sort_order() );

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Add language', 'universal-multilingual' ) . '</button></p>';
		echo '</form>';

		$this->card_close();
	}

	/**
	 * The edit card for one language.
	 *
	 * @param object $editing Language row.
	 */
	private function render_edit_card( object $editing ): void {
		$is_default   = ! empty( $editing->is_default );
		$stored_group = $this->registry->group_for_locale( (string) $editing->locale );
		$is_curated   = null !== $stored_group;

		$this->card_open(
			/* translators: %s: language name. */
			sprintf( __( 'Edit %s', 'universal-multilingual' ), (string) $editing->name ),
			__( 'The locale and URL code are fixed once a language exists — changing them would break every translated URL. To change them, delete this language and add it again.', 'universal-multilingual' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aiml_save_language" />';
		echo '<input type="hidden" name="language_id" value="' . esc_attr( (string) (int) $editing->language_id ) . '" />';
		if ( ! $is_curated ) {
			echo '<input type="hidden" name="aiml_custom" value="1" />';
		}
		wp_nonce_field( 'aiml_save_language' );

		echo '<div class="aiml-ui-summary">';
		echo '<dl class="aiml-ui-summary__grid">';
		echo '<dt>' . esc_html__( 'URL code', 'universal-multilingual' ) . '</dt><dd><code>' . esc_html( (string) $editing->code ) . '</code></dd>';
		echo '<dt>' . esc_html__( 'Locale', 'universal-multilingual' ) . '</dt><dd><code>' . esc_html( (string) $editing->locale ) . '</code></dd>';
		echo '</dl></div>';

		if ( $is_curated ) {
			$this->text_field( 'native_name', __( 'Native name', 'universal-multilingual' ), (string) $editing->native_name, __( 'Shown in the language switcher. Leave blank to use the standard name.', 'universal-multilingual' ) );
		} else {
			$this->text_field( 'name', __( 'Name', 'universal-multilingual' ), (string) $editing->name, __( 'English name.', 'universal-multilingual' ) );
			$this->text_field( 'native_name', __( 'Native name', 'universal-multilingual' ), (string) $editing->native_name, __( 'The language in its own words.', 'universal-multilingual' ) );
			$this->direction_field( (string) $editing->direction );
		}

		if ( $is_default ) {
			echo '<div class="aiml-ui-field"><span class="aiml-ui-field__label">' . esc_html__( 'Status', 'universal-multilingual' ) . '</span>';
			echo '<span class="aiml-ui-field__description">' . esc_html__( 'The site language is always published and always unprefixed.', 'universal-multilingual' ) . '</span></div>';
		} else {
			$this->status_field( (string) $editing->status );
		}

		$this->sort_order_field( (int) $editing->sort_order );

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'universal-multilingual' ) . '</button></p>';
		echo '</form>';

		$this->card_close();
	}

	/**
	 * The gated raw-field form for locales outside the registry.
	 */
	private function render_custom_form(): void {
		/**
		 * Filters whether the "Advanced: custom language" path is available.
		 *
		 * @since 1.13.0
		 *
		 * @param bool $allowed Default true.
		 */
		if ( ! (bool) apply_filters( 'aiml_allow_custom_language', true ) ) {
			return;
		}

		echo '<details class="aiml-ui-advanced">';
		echo '<summary>' . esc_html__( 'Advanced: add a custom language', 'universal-multilingual' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'For locales not in the standard list. You provide every value; nothing is derived. URL codes must be lowercase, like sv or pt-br.', 'universal-multilingual' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aiml_save_language" />';
		echo '<input type="hidden" name="aiml_custom" value="1" />';
		wp_nonce_field( 'aiml_save_language' );

		$this->text_field( 'code', __( 'URL code', 'universal-multilingual' ), '', __( 'For example sv, pt-br or de-de-formal.', 'universal-multilingual' ) );
		$this->text_field( 'locale', __( 'Locale', 'universal-multilingual' ), '', __( 'WordPress locale, for example sv_SE.', 'universal-multilingual' ) );
		$this->text_field( 'name', __( 'Name', 'universal-multilingual' ), '', __( 'English name.', 'universal-multilingual' ) );
		$this->text_field( 'native_name', __( 'Native name', 'universal-multilingual' ), '', __( 'The language in its own words.', 'universal-multilingual' ) );
		$this->direction_field( 'ltr' );
		$this->status_field( Languages::STATUS_PREVIEW );
		$this->sort_order_field( $this->next_sort_order() );

		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Add custom language', 'universal-multilingual' ) . '</button></p>';
		echo '</form></details>';
	}

	// -- Field partials --

	/**
	 * Opens a settings card.
	 *
	 * @param string $title       Card title.
	 * @param string $description Card description.
	 */
	private function card_open( string $title, string $description ): void {
		echo '<div class="aiml-ui-card"><div class="aiml-ui-card__header">';
		echo '<h2 class="aiml-ui-card__title">' . esc_html( $title ) . '</h2>';
		echo '<p class="aiml-ui-card__description">' . esc_html( $description ) . '</p>';
		echo '</div><div class="aiml-ui-card__divider"></div><div class="aiml-ui-card__body">';
	}

	/**
	 * Closes a settings card.
	 */
	private function card_close(): void {
		echo '</div></div>';
	}

	/**
	 * A labelled text input field.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $description Help text.
	 */
	private function text_field( string $name, string $label, string $value, string $description ): void {
		printf(
			'<div class="aiml-ui-field"><label class="aiml-ui-field__label" for="aiml-%1$s">%2$s</label>'
			. '<span class="aiml-ui-field__description">%4$s</span>'
			. '<div class="aiml-ui-field__control"><input type="text" id="aiml-%1$s" name="%1$s" value="%3$s" class="regular-text" /></div></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_html( $description )
		);
	}

	/**
	 * The status select.
	 *
	 * @param string $current Selected status.
	 */
	private function status_field( string $current ): void {
		echo '<div class="aiml-ui-field"><label class="aiml-ui-field__label" for="aiml-status">' . esc_html__( 'Status', 'universal-multilingual' ) . '</label>';
		echo '<span class="aiml-ui-field__description">' . esc_html__( 'Preview: visible only to translators. Published: visible to everyone. Disabled: not routed at all.', 'universal-multilingual' ) . '</span>';
		echo '<div class="aiml-ui-field__control"><select id="aiml-status" name="status">';
		foreach ( Languages::statuses() as $status ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $current, $status, false ),
				esc_html( $this->status_label( $status ) )
			);
		}
		echo '</select></div></div>';
	}

	/**
	 * The text-direction select (custom mode only).
	 *
	 * @param string $current Selected direction.
	 */
	private function direction_field( string $current ): void {
		echo '<div class="aiml-ui-field"><label class="aiml-ui-field__label" for="aiml-direction">' . esc_html__( 'Text direction', 'universal-multilingual' ) . '</label>';
		echo '<div class="aiml-ui-field__control"><select id="aiml-direction" name="direction">';
		foreach ( Languages::DIRECTIONS as $direction ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $direction ),
				selected( $current, $direction, false )
			);
		}
		echo '</select></div></div>';
	}

	/**
	 * The sort-order number field.
	 *
	 * @param int $current Current value.
	 */
	private function sort_order_field( int $current ): void {
		printf(
			'<div class="aiml-ui-field"><label class="aiml-ui-field__label" for="aiml-sort-order">%1$s</label>'
			. '<span class="aiml-ui-field__description">%2$s</span>'
			. '<div class="aiml-ui-field__control"><input type="number" id="aiml-sort-order" name="sort_order" value="%3$s" min="0" step="1" /></div></div>',
			esc_html__( 'Sort order', 'universal-multilingual' ),
			esc_html__( 'Position in the language switcher.', 'universal-multilingual' ),
			absint( $current )
		);
	}

	/**
	 * The next unused sort order (max + 10, rounded), for new languages.
	 */
	private function next_sort_order(): int {
		$max = 0;
		foreach ( $this->languages->all() as $row ) {
			$max = max( $max, (int) $row->sort_order );
		}

		return $max + 10;
	}

	// -- Write handlers --

	/**
	 * Creates or updates a language from the add/edit form.
	 *
	 * The nonce and capability are checked here; the whole submission is read
	 * and sanitised once, then dispatched to the branch that owns it. Canonical
	 * fields (`code`, `locale`, `name`, `direction`) submitted for a curated
	 * language are ignored — the server derives them.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_save_language' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified immediately above.
		$post = array(
			'language_id'    => isset( $_POST['language_id'] ) ? (int) $_POST['language_id'] : 0,
			'is_custom'      => ! empty( $_POST['aiml_custom'] ),
			'registry_group' => isset( $_POST['registry_group'] ) ? sanitize_text_field( wp_unslash( $_POST['registry_group'] ) ) : '',
			'locale'         => isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '',
			'code'           => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'has_name'       => isset( $_POST['name'] ),
			'name'           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'native_name'    => isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( $_POST['native_name'] ) ) : '',
			'has_direction'  => isset( $_POST['direction'] ),
			'direction'      => isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : 'ltr',
			'status'         => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : Languages::STATUS_PREVIEW,
			'sort_order'     => isset( $_POST['sort_order'] ) ? max( 0, (int) $_POST['sort_order'] ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $post['language_id'] > 0 ) {
			$this->handle_edit( $post );
			return;
		}

		if ( $post['is_custom'] ) {
			$this->handle_custom_add( $post );
			return;
		}

		$this->handle_curated_add( $post );
	}

	/**
	 * Curated add: the server derives every canonical field from the selection.
	 *
	 * @param array<string, mixed> $post Sanitised submission.
	 */
	private function handle_curated_add( array $post ): void {
		$group  = (string) $post['registry_group'];
		$locale = (string) $post['locale'];

		$group_meta = $this->registry->group( $group );
		if ( null === $group_meta ) {
			$this->redirect_with_result(
				new WP_Error( 'aiml_unknown_locale', __( 'Choose a language from the list.', 'universal-multilingual' ) )
			);
			return;
		}

		if ( '' === $locale || $this->registry->group_for_locale( $locale ) !== $group ) {
			$locale = $group_meta->locales[0] ?? '';
		}

		$derived = DerivedLanguageMetadata::derive( $locale, $group, $this->existing_identities(), null, $this->registry );
		if ( is_wp_error( $derived ) ) {
			$this->redirect_with_result( $derived );
			return;
		}

		$this->redirect_with_result(
			$this->languages->insert(
				array(
					'code'        => $derived['code'],
					'locale'      => $locale,
					'name'        => $derived['name'],
					'native_name' => $derived['native_name'],
					'direction'   => $derived['direction'],
					'status'      => (string) $post['status'],
					'sort_order'  => (int) $post['sort_order'],
				)
			)
		);
	}

	/**
	 * Custom add: the raw-field path, gated behind `aiml_allow_custom_language`.
	 *
	 * @param array<string, mixed> $post Sanitised submission.
	 */
	private function handle_custom_add( array $post ): void {
		/**
		 * Filters whether the "Advanced: custom language" path is available.
		 *
		 * @since 1.13.0
		 *
		 * @param bool $allowed Default true.
		 */
		if ( ! (bool) apply_filters( 'aiml_allow_custom_language', true ) ) {
			wp_die( esc_html__( 'Custom languages are disabled on this site.', 'universal-multilingual' ) );
		}

		$this->redirect_with_result(
			$this->languages->insert(
				array(
					'code'        => (string) $post['code'],
					'locale'      => (string) $post['locale'],
					'name'        => (string) $post['name'],
					'native_name' => (string) $post['native_name'],
					'direction'   => (string) $post['direction'],
					'status'      => (string) $post['status'],
					'sort_order'  => (int) $post['sort_order'],
				)
			)
		);
	}

	/**
	 * Edit: `code` and `locale` are pinned to the stored row for every language.
	 *
	 * @param array<string, mixed> $post Sanitised submission.
	 */
	private function handle_edit( array $post ): void {
		$language_id = (int) $post['language_id'];

		$row = $this->languages->find( $language_id );
		if ( null === $row ) {
			$this->redirect_with_result(
				new WP_Error( 'aiml_unknown_language', __( 'That language does not exist.', 'universal-multilingual' ) )
			);
			return;
		}

		$stored_group = $this->registry->group_for_locale( (string) $row->locale );
		$override     = (string) $post['native_name'];

		$data = array(
			'status'     => (string) $post['status'],
			'sort_order' => (int) $post['sort_order'],
		);

		if ( null !== $stored_group ) {
			// Curated row: name and direction are re-derived from the registry;
			// code and locale are left out of $data so update() keeps them.
			$derived = DerivedLanguageMetadata::derive( (string) $row->locale, $stored_group, $this->existing_identities(), $language_id, $this->registry );
			if ( is_wp_error( $derived ) ) {
				$this->redirect_with_result( $derived );
				return;
			}

			$data['name']        = $derived['name'];
			$data['direction']   = $derived['direction'];
			$data['native_name'] = '' !== $override ? $override : $derived['native_name'];
		} else {
			// Custom row: name, native name and direction are editable; code and
			// locale still stay pinned (never read from the submission).
			if ( $post['has_name'] ) {
				$data['name'] = (string) $post['name'];
			}
			if ( $post['has_direction'] ) {
				$data['direction'] = (string) $post['direction'];
			}
			$data['native_name'] = $override;
		}

		$this->redirect_with_result( $this->languages->update( $language_id, $data ) );
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

	// -- Shared helpers --

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
	 * A status pill for the list.
	 *
	 * @param string $status Stored status.
	 */
	private function status_badge( string $status ): string {
		$variant = in_array( $status, Languages::statuses(), true ) ? $status : Languages::STATUS_PREVIEW;

		return sprintf(
			'<span class="aiml-ui-badge aiml-ui-badge--%1$s"><span class="aiml-ui-badge__dot"></span>%2$s</span>',
			esc_attr( $variant ),
			esc_html( $this->status_label( $status ) )
		);
	}

	/**
	 * Redirects back to the Languages screen carrying the outcome of a write.
	 *
	 * @param true|int|WP_Error $result Outcome from the languages store.
	 */
	private function redirect_with_result( $result ): void {
		$args = array( 'page' => SettingsPage::MENU_SLUG );

		if ( is_wp_error( $result ) ) {
			$args['aiml_error']      = rawurlencode( $result->get_error_message() );
			$args['aiml_error_code'] = $result->get_error_code();
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
				'<div class="aiml-ui-panel aiml-ui-panel--error"><p class="aiml-ui-panel__message">%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['aiml_error'] ) ) ) )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['aiml_updated'] ) ) {
			printf(
				'<div class="aiml-ui-panel aiml-ui-panel--success"><p class="aiml-ui-panel__message">%s</p></div>',
				esc_html__( 'Saved.', 'universal-multilingual' )
			);
		}
	}
}
