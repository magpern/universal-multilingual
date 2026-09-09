<?php
/**
 * Minimal translation editor.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\Surface\AdmittedPostTypes;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use WP_Error;
use WP_Post;

/**
 * One screen: pick a post, pick a language, translate its fields.
 *
 * Deliberately plain. Milestone 1 exists to prove the spine — a translation
 * stored against the canonical object renders in its language and nowhere else
 * — so the editor is a form, not an application. The side-by-side segment
 * editor, bulk selection and AI actions belong to the milestones that
 * introduce segments and providers.
 *
 * The body field refuses block and Elementor content. That refusal comes from
 * Extractor rather than from this screen, so the same rule applies to the
 * WP-CLI path; here it only decides whether to render the field as disabled and
 * explain why.
 */
final class Editor {

	/**
	 * Submenu slug.
	 */
	public const MENU_SLUG = 'aiml-translate';

	/**
	 * Post types the editor offers in Milestone 1.
	 */
	private const POST_TYPES = AdmittedPostTypes::LEGACY_ADMIN_EDIT_TYPES;

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Segment store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Source extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	/**
	 * Builds the editor screen.
	 *
	 * @param Languages $languages Language configuration.
	 * @param Store     $store     Segment store.
	 * @param Extractor $extractor Source extractor.
	 */
	public function __construct( Languages $languages, Store $store, Extractor $extractor ) {
		$this->languages = $languages;
		$this->store     = $store;
		$this->extractor = $extractor;
	}

	/**
	 * Registers the menu and the save handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_aiml_save_translation', array( $this, 'handle_save' ) );
	}

	/**
	 * Adds the Translate submenu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Translate', 'universal-multilingual' ),
			__( 'Translate', 'universal-multilingual' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the editor.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to translate content.', 'universal-multilingual' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		$post_id     = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$language_id = isset( $_GET['language_id'] ) ? (int) $_GET['language_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$targets = $this->target_languages();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Translate', 'universal-multilingual' ) . '</h1>';

		$this->render_notice();

		if ( array() === $targets ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to the Languages screen. */
				esc_html__( 'No target languages yet. Add one on the %s screen.', 'universal-multilingual' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) . '">'
					. esc_html__( 'Languages', 'universal-multilingual' ) . '</a>'
			);
			echo '</p></div></div>';

			return;
		}

		$this->render_picker( $post_id, $language_id, $targets );

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof WP_Post && $language_id > 0 ) {
			$this->render_form( $post, $language_id );
		}

		echo '</div>';
	}

	/**
	 * Stores a submitted translation.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to translate content.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_save_translation' );

		$post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$language_id = isset( $_POST['language_id'] ) ? (int) $_POST['language_id'] : 0;

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || null === $this->languages->find( $language_id ) ) {
			$this->redirect( $post_id, $language_id, new WP_Error( 'aiml_bad_request', __( 'Unknown post or language.', 'universal-multilingual' ) ) );
		}

		$sources    = $this->extractor->extract( $post );
		$body_state = $this->extractor->body_status( $post );
		$saved      = 0;

		foreach ( Extractor::fields() as $field_key => $spec ) {
			if ( ! isset( $_POST[ $field_key ] ) ) {
				continue;
			}

			// The body is only writable when the source can safely be replaced
			// as a single segment; enforced again here so a crafted POST cannot
			// slip past the disabled form field.
			if ( Extractor::FIELD_CONTENT === $field_key && Extractor::BODY_OK !== $body_state ) {
				continue;
			}

			if ( ! isset( $sources[ $field_key ] ) ) {
				continue;
			}

			$raw = wp_unslash( $_POST[ $field_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per format below.

			$value = Store::FORMAT_HTML === $spec['format']
				? wp_kses_post( (string) $raw )
				: sanitize_text_field( (string) $raw );

			$result = $this->store->save_translation(
				array(
					'source_type'     => Store::SOURCE_POST,
					'source_id'       => (int) $post->ID,
					'source_subtype'  => (string) $post->post_type,
					'language_id'     => $language_id,
					'field_key'       => $field_key,
					'segment_key'     => $field_key,
					'segment_order'   => (int) $spec['order'],
					'text_format'     => (string) $spec['format'],
					'source_text'     => (string) $sources[ $field_key ]['source_text'],
					'translated_text' => $value,
					'status'          => Store::STATUS_MANUALLY_EDITED,
				)
			);

			if ( $result instanceof WP_Error ) {
				$this->redirect( $post_id, $language_id, $result );
			}

			++$saved;
		}

		$this->redirect( $post_id, $language_id, $saved > 0 ? true : new WP_Error( 'aiml_nothing_saved', __( 'Nothing to save.', 'universal-multilingual' ) ) );
	}

	// -- Rendering --

	/**
	 * Renders the post and language pickers.
	 *
	 * @param int      $post_id     Selected post id.
	 * @param int      $language_id Selected language id.
	 * @param object[] $targets     Available target languages.
	 */
	private function render_picker( int $post_id, int $language_id, array $targets ): void {
		// A flat dropdown of every page and post. This is the Milestone 1
		// editor: the searchable, paginated content list arrives with the
		// segment editor, and until then a bounded fetch keeps the screen
		// usable without pretending to scale.
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPES,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Admin-only picker, deliberately capped.
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="aiml-post">' . esc_html__( 'Content', 'universal-multilingual' ) . '</label></th><td>';
		echo '<select name="post_id" id="aiml-post">';
		echo '<option value="0">' . esc_html__( '— Select —', 'universal-multilingual' ) . '</option>';
		foreach ( $posts as $candidate ) {
			printf(
				'<option value="%1$d"%2$s>%3$s (%4$s)</option>',
				(int) $candidate->ID,
				selected( $post_id, (int) $candidate->ID, false ),
				esc_html( get_the_title( $candidate ) ),
				esc_html( (string) $candidate->post_type )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="aiml-language">' . esc_html__( 'Language', 'universal-multilingual' ) . '</label></th><td>';
		echo '<select name="language_id" id="aiml-language">';
		echo '<option value="0">' . esc_html__( '— Select —', 'universal-multilingual' ) . '</option>';
		foreach ( $targets as $language ) {
			printf(
				'<option value="%1$d"%2$s>%3$s (%4$s)</option>',
				(int) $language->language_id,
				selected( $language_id, (int) $language->language_id, false ),
				esc_html( (string) $language->name ),
				esc_html( (string) $language->code )
			);
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Load', 'universal-multilingual' ), 'secondary', '', false );

		echo '</form><hr />';
	}

	/**
	 * Renders the translation form for one post and language.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 */
	private function render_form( WP_Post $post, int $language_id ): void {
		$sources    = $this->extractor->extract( $post );
		$segments   = $this->store->load_object( Store::SOURCE_POST, (int) $post->ID, $language_id );
		$body_state = $this->extractor->body_status( $post );

		echo '<h2>' . esc_html( get_the_title( $post ) ) . '</h2>';

		$this->render_ai_bridge( $post, $language_id );

		if ( $this->extractor->uses_block_workspace( $post ) ) {
			$this->render_workspace_deferral_notice( $post, $language_id );
		} elseif ( Extractor::BODY_OK !== $body_state ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html( Extractor::body_notice( $body_state ) )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aiml_save_translation" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) (int) $post->ID ) . '" />';
		echo '<input type="hidden" name="language_id" value="' . esc_attr( (string) $language_id ) . '" />';

		wp_nonce_field( 'aiml_save_translation' );

		foreach ( Extractor::fields() as $field_key => $spec ) {
			$is_body   = Extractor::FIELD_CONTENT === $field_key;
			$available = isset( $sources[ $field_key ] );
			$segment   = $segments[ $field_key ] ?? null;

			if ( ! $available && ! $is_body ) {
				continue;
			}

			$source      = $available ? (string) $sources[ $field_key ]['source_text'] : (string) $post->{$field_key};
			$translation = null === $segment ? '' : (string) ( $segment->translated_text ?? '' );

			echo '<h3>' . esc_html( $this->field_label( $field_key ) ) . ' ' . $this->status_badge( $segment ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge is escaped at construction.

			echo '<table class="form-table" role="presentation"><tbody><tr>';
			echo '<th scope="row">' . esc_html__( 'Source', 'universal-multilingual' ) . '</th>';
			echo '<td><textarea readonly rows="' . ( $is_body ? 10 : 2 ) . '" class="large-text code">' . esc_textarea( $source ) . '</textarea></td>';
			echo '</tr><tr>';
			echo '<th scope="row"><label for="aiml-field-' . esc_attr( $field_key ) . '">' . esc_html__( 'Translation', 'universal-multilingual' ) . '</label></th>';
			echo '<td>';

			if ( $is_body && Extractor::BODY_OK !== $body_state ) {
				echo '<textarea rows="10" class="large-text" disabled></textarea>';
				echo '<p class="description">' . esc_html__( 'Body translation is unavailable for this content type in this version.', 'universal-multilingual' ) . '</p>';
			} else {
				printf(
					'<textarea id="aiml-field-%1$s" name="%1$s" rows="%2$d" class="large-text">%3$s</textarea>',
					esc_attr( $field_key ),
					$is_body ? 10 : 2,
					esc_textarea( $translation )
				);
			}

			echo '</td></tr></tbody></table>';
		}

		submit_button( __( 'Save translation', 'universal-multilingual' ) );

		echo '</form>';
	}

	/**
	 * Target languages, i.e. everything except the default.
	 *
	 * @return object[]
	 */
	private function target_languages(): array {
		return array_values(
			array_filter(
				$this->languages->all(),
				static function ( object $language ): bool {
					return empty( $language->is_default );
				}
			)
		);
	}

	/**
	 * Escaped status badge for a segment.
	 *
	 * @param object|null $segment Stored segment, or null when untranslated.
	 */
	private function status_badge( ?object $segment ): string {
		if ( null === $segment || Store::STATUS_MISSING === $segment->status ) {
			return '<span class="dashicons dashicons-minus" title="' . esc_attr__( 'Not translated', 'universal-multilingual' ) . '"></span>';
		}

		$label = Store::STATUS_REVIEWED === $segment->status
			? __( 'Reviewed', 'universal-multilingual' )
			: __( 'Translated', 'universal-multilingual' );

		if ( ! empty( $segment->is_stale ) ) {
			$label .= ' · ' . __( 'source changed', 'universal-multilingual' );
		}

		return '<em style="font-weight:normal;font-size:13px;">(' . esc_html( $label ) . ')</em>';
	}

	/**
	 * Human-readable label for a field.
	 *
	 * @param string $field_key Field key.
	 */
	private function field_label( string $field_key ): string {
		switch ( $field_key ) {
			case Extractor::FIELD_TITLE:
				return __( 'Title', 'universal-multilingual' );

			case Extractor::FIELD_EXCERPT:
				return __( 'Excerpt', 'universal-multilingual' );

			case Extractor::FIELD_CONTENT:
			default:
				return __( 'Body', 'universal-multilingual' );
		}
	}

	/**
	 * Redirects back to the editor carrying the outcome.
	 *
	 * @param int           $post_id     Post id.
	 * @param int           $language_id Language id.
	 * @param true|WP_Error $result      Outcome.
	 */
	private function redirect( int $post_id, int $language_id, $result ): void {
		$args = array(
			'page'        => self::MENU_SLUG,
			'post_id'     => $post_id,
			'language_id' => $language_id,
		);

		if ( $result instanceof WP_Error ) {
			$args['aiml_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aiml_updated'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Bridges the legacy screen into the Translator Workspace AI flow so it is
	 * never an AI dead end (AIT1 / ADR-0031). The AI action is orchestrated in
	 * one place — the Workspace + Jobs — so this deep-links there with the post
	 * and target language preselected rather than creating a job here.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 */
	private function render_ai_bridge( WP_Post $post, int $language_id ): void {
		$language = $this->languages->find( $language_id );
		$code     = null !== $language ? (string) $language->code : '';

		$workspace_url = add_query_arg(
			array(
				'page'     => TranslatorWorkspace::MENU_SLUG,
				'post_id'  => (int) $post->ID,
				'language' => $code,
			),
			admin_url( 'admin.php' )
		);

		echo '<p class="aiml-editor-ai-bridge">';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a> ',
			esc_url( add_query_arg( 'aiml_ai', '1', $workspace_url ) ),
			esc_html__( 'Translate with AI', 'universal-multilingual' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( $workspace_url ),
			esc_html__( 'Open in Translator Workspace', 'universal-multilingual' )
		);
		echo '</p>';
		echo '<p class="description">';
		esc_html_e(
			'AI translation and the segment editor live in the Translator Workspace. The fields below remain for quick manual edits.',
			'universal-multilingual'
		);
		echo '</p>';
	}

	/**
	 * Directs translators to the workspace for block-managed posts.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 */
	private function render_workspace_deferral_notice( WP_Post $post, int $language_id ): void {
		$language = $this->languages->find( $language_id );
		$code     = null !== $language ? (string) $language->code : '';

		$workspace_url = admin_url(
			add_query_arg(
				array(
					'page'     => TranslatorWorkspace::MENU_SLUG,
					'post_id'  => (int) $post->ID,
					'language' => $code,
				),
				'admin.php'
			)
		);

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'Block translations for this post are managed in the Translator Workspace. Use the workspace to edit block segments so changes stay aligned with Strategy F extraction.',
			'universal-multilingual'
		);
		echo '</p><p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( $workspace_url ),
			esc_html__( 'Open Translator Workspace', 'universal-multilingual' )
		);
		echo '</p></div>';
	}

	/**
	 * Prints the success or error notice carried on the query string.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only feedback.
		if ( isset( $_GET['aiml_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['aiml_error'] ) ) ) )
			);

			return;
		}

		if ( isset( $_GET['aiml_updated'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Translation saved.', 'universal-multilingual' )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
