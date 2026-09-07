<?php
/**
 * Settings and language administration screens.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Block\FeatureFlags;
use AIMultilingual\Language\Languages;
use AIMultilingual\Routing\LocalizedUrlsActivationService;
use AIMultilingual\Routing\ReindexFrontierRepository;
use AIMultilingual\Routing\RoutingCapabilityAdmission;
use AIMultilingual\Routing\WooProductPermalinkFingerprint;
use AIMultilingual\Settings;
use AIMultilingual\Translation\AI\CredentialVault;
use AIMultilingual\Translation\Publication\PublicationMode;
use WP_Error;

/**
 * The Languages and Settings screens.
 *
 * Conventional WordPress admin: the Settings API for the options form, and
 * `admin-post.php` handlers for language create, update and delete. Milestone 1
 * ships no REST API, because nothing here needs one — a REST layer would exist
 * only to be called by JavaScript this milestone does not ship (ADR-0002 scope
 * note). The segment editor in the next milestone is what actually requires
 * incremental per-segment saves, and REST arrives with it.
 *
 * Every write checks both a nonce and `manage_options`.
 */
final class SettingsPage {

	/**
	 * Top-level menu slug.
	 */
	public const MENU_SLUG = 'ai-multilingual';

	/**
	 * Settings submenu slug.
	 */
	public const SETTINGS_SLUG = 'aiml-settings';

	/**
	 * Settings API group.
	 */
	private const OPTION_GROUP = 'aiml_settings_group';

	/**
	 * Transient key prefix for Strategy F dependency rejection notices.
	 */
	public const FLAG_NOTICE_TRANSIENT = 'aiml_strategy_f_flag_combo_rejected';

	/**
	 * Admin notice identifier for rejected flag combinations.
	 */
	public const FLAG_NOTICE_ID = 'aiml_strategy_f_flag_combo_rejected';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Credential vault for AI API keys.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Localized URL activation control.
	 *
	 * @var LocalizedUrlsActivationService|null
	 */
	private ?LocalizedUrlsActivationService $localized_urls;

	/**
	 * Capability admission (optional; Settings honesty).
	 *
	 * @var RoutingCapabilityAdmission|null
	 */
	private ?RoutingCapabilityAdmission $routing_admission;

	/**
	 * Reindex frontier repository (optional; Settings honesty).
	 *
	 * @var ReindexFrontierRepository|null
	 */
	private ?ReindexFrontierRepository $frontier;

	/**
	 * Builds the settings and language screens.
	 *
	 * @param Settings                            $settings          Plugin settings.
	 * @param Languages                           $languages         Language configuration.
	 * @param CredentialVault|null                $vault             Credential vault.
	 * @param LocalizedUrlsActivationService|null $localized_urls    Localized URL activation.
	 * @param RoutingCapabilityAdmission|null     $routing_admission  Admission authority.
	 * @param ReindexFrontierRepository|null      $frontier          Frontier repository.
	 */
	public function __construct(
		Settings $settings,
		Languages $languages,
		?CredentialVault $vault = null,
		?LocalizedUrlsActivationService $localized_urls = null,
		?RoutingCapabilityAdmission $routing_admission = null,
		?ReindexFrontierRepository $frontier = null
	) {
		$this->settings          = $settings;
		$this->languages         = $languages;
		$this->vault             = $vault ?? new CredentialVault();
		$this->localized_urls    = $localized_urls;
		$this->routing_admission = $routing_admission;
		$this->frontier          = $frontier;
	}

	/**
	 * Registers menus, settings and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_strategy_f_admin_notices' ) );

		add_action( 'admin_post_aiml_save_language', array( $this, 'handle_save_language' ) );
		add_action( 'admin_post_aiml_delete_language', array( $this, 'handle_delete_language' ) );
		add_action( 'admin_post_aiml_localized_urls_enable', array( $this, 'handle_localized_urls_enable' ) );
		add_action( 'admin_post_aiml_localized_urls_disable', array( $this, 'handle_localized_urls_disable' ) );
		add_action( 'admin_post_aiml_localized_urls_retry', array( $this, 'handle_localized_urls_retry' ) );
	}

	/**
	 * Adds the top-level menu and its Settings submenu.
	 */
	public function add_menus(): void {
		add_menu_page(
			__( 'Universal Multilingual', 'universal-multilingual' ),
			__( 'Multilingual', 'universal-multilingual' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_languages' ),
			'dashicons-translation',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Languages', 'universal-multilingual' ),
			__( 'Languages', 'universal-multilingual' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_languages' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'universal-multilingual' ),
			__( 'Settings', 'universal-multilingual' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Registers the options form with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitizes settings submitted from the admin form and records Strategy F audit events.
	 *
	 * @param mixed $input Raw option value from the Settings API.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		$raw      = is_array( $input ) ? $input : array();
		$previous = Settings::sanitize( get_option( Settings::OPTION, Settings::defaults() ) );

		// Map plaintext per-provider API key fields into encrypted storage before sanitize.
		$raw_providers  = isset( $raw['ai_providers'] ) && is_array( $raw['ai_providers'] )
			? $raw['ai_providers']
			: array();
		$prev_providers = isset( $previous['ai_providers'] ) && is_array( $previous['ai_providers'] )
			? $previous['ai_providers']
			: array();

		foreach ( array( 'openai', 'deepseek' ) as $provider_id ) {
			$row      = isset( $raw_providers[ $provider_id ] ) && is_array( $raw_providers[ $provider_id ] )
				? $raw_providers[ $provider_id ]
				: array();
			$prev_row = isset( $prev_providers[ $provider_id ] ) && is_array( $prev_providers[ $provider_id ] )
				? $prev_providers[ $provider_id ]
				: array();

			if ( isset( $row['api_key'] ) ) {
				$submitted = trim( (string) wp_unslash( $row['api_key'] ) );
				if ( '********' === $submitted ) {
					$row['api_key_encrypted'] = (string) ( $prev_row['api_key_encrypted'] ?? '' );
				} elseif ( '' === $submitted ) {
					$row['api_key_encrypted'] = '';
				} else {
					$row['api_key_encrypted'] = $this->vault->encrypt( $submitted );
				}
				unset( $row['api_key'] );
			} elseif ( ! isset( $row['api_key_encrypted'] ) ) {
				$row['api_key_encrypted'] = (string) ( $prev_row['api_key_encrypted'] ?? '' );
			}

			$raw_providers[ $provider_id ] = $row;
		}
		$raw['ai_providers'] = $raw_providers;

		// Legacy single-key field (pre-1.10.0 forms / options): keep encrypt path for migration.
		if ( isset( $raw['ai_api_key'] ) ) {
			$submitted = trim( (string) wp_unslash( $raw['ai_api_key'] ) );
			if ( '********' === $submitted ) {
				$raw['ai_api_key_encrypted'] = (string) ( $previous['ai_api_key_encrypted'] ?? '' );
			} elseif ( '' === $submitted ) {
				$raw['ai_api_key_encrypted'] = '';
			} else {
				$raw['ai_api_key_encrypted'] = $this->vault->encrypt( $submitted );
			}
			unset( $raw['ai_api_key'] );
		} elseif ( ! isset( $raw['ai_api_key_encrypted'] ) ) {
			$raw['ai_api_key_encrypted'] = (string) ( $previous['ai_api_key_encrypted'] ?? '' );
		}

		$clean = Settings::sanitize( $raw );

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		Settings::emit_flag_change_audit( $previous, $clean, 'admin_settings' );

		$this->handle_strategy_f_submission( $raw, $clean, $previous );

		// Localized URL state machine / capability admission are machine-owned.
		$clean['localized_urls_state']                     = $previous['localized_urls_state'] ?? 'off';
		$clean['localized_urls_activation_checkpoint']     = $previous['localized_urls_activation_checkpoint'] ?? null;
		$clean['localized_urls_activation_error']          = $previous['localized_urls_activation_error'] ?? '';
		$clean['localized_urls_verified_capability_epoch'] = $previous['localized_urls_verified_capability_epoch'] ?? 0;
		$clean['localized_urls_admitted_capabilities']     = $previous['localized_urls_admitted_capabilities'] ?? array();
		$clean['localized_urls_capability_checkpoint']     = $previous['localized_urls_capability_checkpoint'] ?? null;
		$clean['localized_urls_woo_product_fingerprint']   = $previous['localized_urls_woo_product_fingerprint'] ?? '';

		return $clean;
	}

	/**
	 * Records rejected Strategy F flag combinations and queues the admin notice.
	 *
	 * @param array<string, mixed> $raw      Raw submitted settings.
	 * @param array<string, mixed> $clean    Sanitized settings.
	 * @param array<string, mixed> $previous Sanitized settings before save.
	 */
	private function handle_strategy_f_submission( array $raw, array $clean, array $previous ): void {
		if ( ! FeatureFlags::production_flags_submission_changed( $raw, $previous ) ) {
			$this->clear_flag_rejection_notice();

			return;
		}

		$payload = FeatureFlags::flag_rejection_payload( $raw, $clean );
		if ( null === $payload ) {
			$this->clear_flag_rejection_notice();

			return;
		}

		Settings::emit_flag_combo_rejected( $payload );
		$this->queue_flag_rejection_notice( $payload );
	}

	// -- Screens --

	/**
	 * Renders the Languages screen.
	 */
	public function render_languages(): void {
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
							'page'        => self::MENU_SLUG,
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

		$this->render_language_form( $editing );

		echo '</div>';
	}

	/**
	 * Renders the Settings screen.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'universal-multilingual' ) );
		}

		$current = $this->settings->get();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Universal Multilingual Settings', 'universal-multilingual' ) . '</h1>';
		$this->render_notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';

		settings_fields( self::OPTION_GROUP );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row(
			'switcher_show_native_name',
			__( 'Native language names', 'universal-multilingual' ),
			__( 'Show each language in its own name (Svenska) rather than in English (Swedish).', 'universal-multilingual' ),
			(bool) $current['switcher_show_native_name']
		);

		$this->checkbox_row(
			'switcher_hide_current',
			__( 'Hide current language', 'universal-multilingual' ),
			__( 'Omit the language being viewed from the switcher.', 'universal-multilingual' ),
			(bool) $current['switcher_hide_current']
		);

		$this->checkbox_row(
			'remove_data_on_uninstall',
			__( 'Delete all data on uninstall', 'universal-multilingual' ),
			__( 'When the plugin is deleted, drop its tables and remove every translation. Off by default: deactivating or deleting the plugin keeps all translation work so a reinstall resumes where it left off.', 'universal-multilingual' ),
			(bool) $current['remove_data_on_uninstall']
		);

		$this->checkbox_row(
			'qa_block_on_error',
			__( 'Block saves on QA errors', 'universal-multilingual' ),
			__( 'When enabled, workspace saves that fail structural quality checks (placeholders, empty translation, HTML tags) are rejected. Warnings never block saves.', 'universal-multilingual' ),
			(bool) ( $current['qa_block_on_error'] ?? true )
		);

		echo '</tbody></table>';

		$this->render_floating_selector_settings( $current );

		$this->render_strategy_f_settings( $current );

		if ( null !== $this->localized_urls ) {
			$this->render_localized_urls_settings( $current );
		}

		$this->render_ai_provider_settings( $current );

		submit_button();

		echo '</form></div>';
	}

	/**
	 * Renders AI provider configuration (server-side secrets only).
	 *
	 * @param array<string, mixed> $current Current settings.
	 */
	private function render_ai_provider_settings( array $current ): void {
		$providers = isset( $current['ai_providers'] ) && is_array( $current['ai_providers'] )
			? $current['ai_providers']
			: array();

		echo '<h2>' . esc_html__( 'Automatic translation', 'universal-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Configure a provider for automatic translation and AI suggestions. API keys are encrypted at rest and never sent to the browser or translator workspace JavaScript. Each provider keeps its own key, model, temperature, and max tokens.', 'universal-multilingual' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row(
			'ai_enabled',
			__( 'Enable AI provider', 'universal-multilingual' ),
			__( 'When enabled and a provider is selected with a valid API key, workspace auto-translate and AI suggest use that provider.', 'universal-multilingual' ),
			(bool) ( $current['ai_enabled'] ?? false )
		);

		$provider = (string) ( $current['ai_provider'] ?? '' );
		echo '<tr><th scope="row"><label for="aiml_ai_provider">' . esc_html__( 'Provider', 'universal-multilingual' ) . '</label></th><td>';
		echo '<select name="' . esc_attr( Settings::OPTION . '[ai_provider]' ) . '" id="aiml_ai_provider">';
		echo '<option value=""' . selected( $provider, '', false ) . '>' . esc_html__( 'None', 'universal-multilingual' ) . '</option>';
		echo '<option value="openai"' . selected( $provider, 'openai', false ) . '>' . esc_html__( 'OpenAI', 'universal-multilingual' ) . '</option>';
		echo '<option value="deepseek"' . selected( $provider, 'deepseek', false ) . '>' . esc_html__( 'DeepSeek', 'universal-multilingual' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Only the selected provider is used for translation. Settings for other providers are kept when you switch.', 'universal-multilingual' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$this->render_one_ai_provider_fieldset(
			'openai',
			__( 'OpenAI', 'universal-multilingual' ),
			__( 'Optional model id (for example gpt-4o-mini). Leave blank for gpt-4o-mini. Some models (gpt-5*, o*) ignore custom temperature.', 'universal-multilingual' ),
			isset( $providers['openai'] ) && is_array( $providers['openai'] ) ? $providers['openai'] : Settings::default_provider_settings( 'openai' )
		);

		$this->render_one_ai_provider_fieldset(
			'deepseek',
			__( 'DeepSeek', 'universal-multilingual' ),
			__( 'Optional model id (for example deepseek-chat or deepseek-v4-flash). Leave blank for deepseek-chat. Translation requests disable thinking mode so temperature applies.', 'universal-multilingual' ),
			isset( $providers['deepseek'] ) && is_array( $providers['deepseek'] ) ? $providers['deepseek'] : Settings::default_provider_settings( 'deepseek' )
		);
	}

	/**
	 * Renders one provider's model / key / temperature / max_tokens fields.
	 *
	 * @param string               $provider_id Provider id.
	 * @param string               $title       Fieldset title.
	 * @param string               $model_help  Model field description.
	 * @param array<string, mixed> $row         Provider settings row.
	 */
	private function render_one_ai_provider_fieldset( string $provider_id, string $title, string $model_help, array $row ): void {
		$prefix  = Settings::OPTION . '[ai_providers][' . $provider_id . ']';
		$has_key = '' !== (string) ( $row['api_key_encrypted'] ?? '' );
		$temp    = isset( $row['temperature'] ) && is_numeric( $row['temperature'] )
			? (float) $row['temperature']
			: (float) Settings::default_provider_settings( $provider_id )['temperature'];
		$max     = isset( $row['max_tokens'] ) ? (int) $row['max_tokens'] : 0;

		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="aiml_ai_' . esc_attr( $provider_id ) . '_model">' . esc_html__( 'Model', 'universal-multilingual' ) . '</label></th><td>';
		echo '<input name="' . esc_attr( $prefix . '[model]' ) . '" type="text" id="aiml_ai_' . esc_attr( $provider_id ) . '_model" value="' . esc_attr( (string) ( $row['model'] ?? '' ) ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html( $model_help ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="aiml_ai_' . esc_attr( $provider_id ) . '_api_key">' . esc_html__( 'API key', 'universal-multilingual' ) . '</label></th><td>';
		echo '<input name="' . esc_attr( $prefix . '[api_key]' ) . '" type="password" id="aiml_ai_' . esc_attr( $provider_id ) . '_api_key" value="' . esc_attr( $has_key ? '********' : '' ) . '" class="regular-text" autocomplete="new-password" />';
		echo '<p class="description">' . esc_html__( 'Leave as dots to keep the existing key. Clear and save to remove. Never exposed to JavaScript.', 'universal-multilingual' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="aiml_ai_' . esc_attr( $provider_id ) . '_temperature">' . esc_html__( 'Temperature', 'universal-multilingual' ) . '</label></th><td>';
		echo '<input name="' . esc_attr( $prefix . '[temperature]' ) . '" type="number" id="aiml_ai_' . esc_attr( $provider_id ) . '_temperature" value="' . esc_attr( (string) $temp ) . '" class="small-text" min="0" max="2" step="0.1" />';
		echo '<p class="description">' . esc_html__( 'Sampling temperature from 0 (focused) to 2 (creative).', 'universal-multilingual' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="aiml_ai_' . esc_attr( $provider_id ) . '_max_tokens">' . esc_html__( 'Max tokens', 'universal-multilingual' ) . '</label></th><td>';
		echo '<input name="' . esc_attr( $prefix . '[max_tokens]' ) . '" type="number" id="aiml_ai_' . esc_attr( $provider_id ) . '_max_tokens" value="' . esc_attr( (string) $max ) . '" class="small-text" min="0" max="393216" step="1" />';
		echo '<p class="description">' . esc_html__( 'Maximum completion tokens. Use 0 to omit and let the provider use its default.', 'universal-multilingual' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	// -- Form handlers --

	/**
	 * Creates or updates a language.
	 */
	public function handle_save_language(): void {
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

		$this->redirect_with_result( $result, self::MENU_SLUG );
	}

	/**
	 * Deletes a language, keeping its translations.
	 */
	public function handle_delete_language(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage languages.', 'universal-multilingual' ) );
		}

		$language_id = isset( $_GET['language_id'] ) ? (int) $_GET['language_id'] : 0;

		check_admin_referer( 'aiml_delete_language_' . $language_id );

		$this->redirect_with_result( $this->languages->delete( $language_id ), self::MENU_SLUG );
	}

	/**
	 * Enables localized public URLs (activating → verification job).
	 */
	public function handle_localized_urls_enable(): void {
		if ( null === $this->localized_urls || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_localized_urls_enable' );

		$this->localized_urls->request_enable();
		$this->redirect_localized_urls_settings();
	}

	/**
	 * Disables localized public URLs.
	 */
	public function handle_localized_urls_disable(): void {
		if ( null === $this->localized_urls || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_localized_urls_disable' );

		$this->localized_urls->request_disable();
		$this->redirect_localized_urls_settings();
	}

	/**
	 * Retries activation after failure.
	 */
	public function handle_localized_urls_retry(): void {
		if ( null === $this->localized_urls || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'universal-multilingual' ) );
		}

		check_admin_referer( 'aiml_localized_urls_retry' );

		$this->localized_urls->request_retry();
		$this->redirect_localized_urls_settings();
	}

	// -- Rendering helpers --

	/**
	 * Renders the add/edit language form.
	 *
	 * @param object|null $editing Language being edited, or null to add.
	 */
	private function render_language_form( ?object $editing ): void {
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
	 * Renders a settings checkbox row.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param bool   $checked     Current value.
	 */
	private function checkbox_row( string $key, string $label, string $description, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label>'
			. '<input type="checkbox" name="%2$s[%3$s]" value="1"%4$s /> %5$s'
			. '</label></td></tr>',
			esc_html( $label ),
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $description )
		);
	}

	/**
	 * Checkbox that can persist an explicit false (hidden 0 + checkbox 1).
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param bool   $checked     Current value.
	 */
	private function checkbox_row_explicit( string $key, string $label, string $description, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>'
			. '<input type="hidden" name="%2$s[%3$s]" value="0" />'
			. '<label><input type="checkbox" name="%2$s[%3$s]" value="1"%4$s /> %5$s</label>'
			. '</td></tr>',
			esc_html( $label ),
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $description )
		);
	}

	/**
	 * Allowlisted select row.
	 *
	 * @param string                $key         Settings key.
	 * @param string                $label       Field label.
	 * @param string                $description Help text.
	 * @param string                $current     Current value.
	 * @param array<string, string> $choices     Value => label.
	 */
	private function select_row( string $key, string $label, string $description, string $current, array $choices ): void {
		echo '<tr><th scope="row"><label for="aiml_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select name="' . esc_attr( Settings::OPTION . '[' . $key . ']' ) . '" id="aiml_' . esc_attr( $key ) . '">';
		foreach ( $choices as $value => $choice_label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $choice_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Floating language selector settings (default off; presentation only).
	 *
	 * @param array<string, mixed> $current Current settings.
	 */
	private function render_floating_selector_settings( array $current ): void {
		echo '<h2>' . esc_html__( 'Floating language selector', 'universal-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Optional visitor control on the page edge. It uses the same language URLs as the existing switcher and stays off until you enable it. No flags. Hide current language does not apply here — the current page language stays visible.', 'universal-multilingual' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Layout: a compact control on the chosen edge (right by default), vertically centered, expanding inward as a short list of language links.', 'universal-multilingual' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row_explicit(
			'floating_selector_enabled',
			__( 'Enable floating selector', 'universal-multilingual' ),
			__( 'Show the edge language selector on the public site. Default off.', 'universal-multilingual' ),
			(bool) ( $current['floating_selector_enabled'] ?? false )
		);

		$this->select_row(
			'floating_selector_side',
			__( 'Edge', 'universal-multilingual' ),
			__( 'Physical left or right edge of the viewport.', 'universal-multilingual' ),
			(string) ( $current['floating_selector_side'] ?? 'right' ),
			array(
				'right' => __( 'Right', 'universal-multilingual' ),
				'left'  => __( 'Left', 'universal-multilingual' ),
			)
		);

		$this->select_row(
			'floating_selector_vertical',
			__( 'Vertical position', 'universal-multilingual' ),
			__( 'Top, center, or bottom of the chosen edge.', 'universal-multilingual' ),
			(string) ( $current['floating_selector_vertical'] ?? 'center' ),
			array(
				'top'    => __( 'Top', 'universal-multilingual' ),
				'center' => __( 'Center', 'universal-multilingual' ),
				'bottom' => __( 'Bottom', 'universal-multilingual' ),
			)
		);

		$this->select_row(
			'floating_selector_collapsed',
			__( 'Collapsed appearance', 'universal-multilingual' ),
			__( 'What the closed control shows: language code, name, or a globe icon with accessible text.', 'universal-multilingual' ),
			(string) ( $current['floating_selector_collapsed'] ?? 'code' ),
			array(
				'code'  => __( 'Language code', 'universal-multilingual' ),
				'name'  => __( 'Language name', 'universal-multilingual' ),
				'globe' => __( 'Globe icon', 'universal-multilingual' ),
			)
		);

		$this->select_row(
			'floating_selector_preset',
			__( 'Preset', 'universal-multilingual' ),
			__( 'Visual style of the edge control.', 'universal-multilingual' ),
			(string) ( $current['floating_selector_preset'] ?? 'edge_pill' ),
			array(
				'edge_pill' => __( 'Edge pill', 'universal-multilingual' ),
				'minimal'   => __( 'Minimal', 'universal-multilingual' ),
				'tab'       => __( 'Tab', 'universal-multilingual' ),
			)
		);

		$this->checkbox_row_explicit(
			'floating_selector_show_desktop',
			__( 'Show on desktop', 'universal-multilingual' ),
			__( 'Visible at viewport widths of 782px and up. Markup stays the same; CSS hides it when off.', 'universal-multilingual' ),
			(bool) ( $current['floating_selector_show_desktop'] ?? true )
		);

		$this->checkbox_row_explicit(
			'floating_selector_show_mobile',
			__( 'Show on mobile', 'universal-multilingual' ),
			__( 'Visible below 782px. Markup stays the same; CSS hides it when off.', 'universal-multilingual' ),
			(bool) ( $current['floating_selector_show_mobile'] ?? true )
		);

		$this->checkbox_row_explicit(
			'floating_selector_persist_preference',
			__( 'Remember language for signed-in users', 'universal-multilingual' ),
			__( 'Best-effort: selecting a language may save the existing preferred-language setting while the browser navigates. Navigation never waits on this save.', 'universal-multilingual' ),
			(bool) ( $current['floating_selector_persist_preference'] ?? true )
		);

		echo '</tbody></table>';
	}

	/**
	 * Renders the Strategy F production flag controls and settings-state diagnostics.
	 *
	 * @param array<string, mixed> $current Saved settings.
	 */
	private function render_strategy_f_settings( array $current ): void {
		$effective = FeatureFlags::validate_dependencies( $current );
		$valid     = ! FeatureFlags::has_prohibited_combination( $current );

		echo '<h2>' . esc_html__( 'Strategy F — Gutenberg block translation', 'universal-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Pre-rollout controls for persistent block identity and gated frontend rendering. All flags default to off.', 'universal-multilingual' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->strategy_f_checkbox_row(
			FeatureFlags::REGISTRATION,
			__( 'Attribute registration', 'universal-multilingual' ),
			__( 'Registers aimlBlockId in block metadata so Gutenberg preserves UUIDs on edit. Required before any other Strategy F behavior.', 'universal-multilingual' ),
			(bool) $current[ FeatureFlags::REGISTRATION ],
			true,
			''
		);

		$this->strategy_f_checkbox_row(
			FeatureFlags::INJECTION,
			__( 'UUID injection', 'universal-multilingual' ),
			__( 'Assigns and repairs block UUIDs on canonical post saves.', 'universal-multilingual' ),
			(bool) $current[ FeatureFlags::INJECTION ],
			! empty( $effective[ FeatureFlags::REGISTRATION ] ),
			FeatureFlags::REGISTRATION
		);

		$this->strategy_f_checkbox_row(
			FeatureFlags::EXTRACTION,
			__( 'Block extraction', 'universal-multilingual' ),
			__( 'Extracts block segments and reconciles the translation store on canonical saves.', 'universal-multilingual' ),
			(bool) $current[ FeatureFlags::EXTRACTION ],
			! empty( $effective[ FeatureFlags::REGISTRATION ] ) && ! empty( $effective[ FeatureFlags::INJECTION ] ),
			FeatureFlags::INJECTION
		);

		$this->strategy_f_checkbox_row(
			FeatureFlags::FRONTEND_RENDER,
			__( 'Frontend rendering', 'universal-multilingual' ),
			__( 'Overlays translated block content on public pages. Disabling this flag is the immediate kill switch.', 'universal-multilingual' ),
			(bool) $current[ FeatureFlags::FRONTEND_RENDER ],
			! empty( $effective[ FeatureFlags::REGISTRATION ] )
				&& ! empty( $effective[ FeatureFlags::INJECTION ] )
				&& ! empty( $effective[ FeatureFlags::EXTRACTION ] ),
			FeatureFlags::EXTRACTION,
			true
		);

		echo '</tbody></table>';

		$this->render_strategy_f_diagnostics( $current, $effective, $valid );

		echo '<h2>' . esc_html__( 'Segment publication', 'universal-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Controls whether published status gates frontend overlay eligibility, and whether successful auto-translate may attempt auto-publication.',
			'universal-multilingual'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row(
			'segment_publication_gate_enabled',
			__( 'Segment publication gate', 'universal-multilingual' ),
			__( 'When enabled, only segments with publish_status published are overlay-eligible. Enabling does not delete data or mass-unpublish.', 'universal-multilingual' ),
			! empty( $current['segment_publication_gate_enabled'] )
		);

		$mode = (string) ( $current['auto_publication_mode'] ?? PublicationMode::MANUAL );
		if ( ! PublicationMode::is_valid( $mode ) ) {
			$mode = PublicationMode::MANUAL;
		}

		echo '<tr><th scope="row"><label for="aiml-auto_publication_mode">' . esc_html__( 'Auto-publication mode', 'universal-multilingual' ) . '</label></th><td>';
		printf(
			'<select id="aiml-auto_publication_mode" name="%1$s[auto_publication_mode]" data-aiml-publication-mode-confirm="1">',
			esc_attr( Settings::OPTION )
		);
		$mode_labels = array(
			PublicationMode::MANUAL          => __( 'Manual', 'universal-multilingual' ),
			PublicationMode::APPROVED_ONLY   => __( 'Approved only', 'universal-multilingual' ),
			PublicationMode::CONTROLLED_AUTO => __( 'Controlled auto', 'universal-multilingual' ),
		);
		foreach ( $mode_labels as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $mode, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__(
			'Affects future maybe_auto_publish after auto-translate only. Changing mode does not reconcile existing rows; manual does not mass-unpublish.',
			'universal-multilingual'
		) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		$this->render_publication_diagnostics( $current );
		$this->render_publication_confirmation_script();

		$this->render_strategy_f_confirmation_script();
	}

	/**
	 * Renders localized URL activation controls (MSEO.2.5).
	 *
	 * @param array<string, mixed> $current Saved settings.
	 */
	private function render_localized_urls_settings( array $current ): void {
		$state = (string) ( $current['localized_urls_state'] ?? LocalizedUrlsActivationService::STATE_OFF );
		$error = (string) ( $current['localized_urls_activation_error'] ?? '' );

		echo '<h2>' . esc_html__( 'Localized URLs', 'universal-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Enabling verifies prepared active routes in the background before public localized URLs are advertised. Disabling is immediate.',
			'universal-multilingual'
		) . '</p>';

		echo '<div class="aiml-localized-urls-diagnostics" style="margin:1.5em 0;padding:1em;border:1px solid #ccd0d4;background:#fff;">';
		echo '<p><strong>' . esc_html__( 'State:', 'universal-multilingual' ) . '</strong> ';
		echo esc_html( $this->localized_urls_state_label( $state ) ) . '</p>';

		if ( LocalizedUrlsActivationService::STATE_FAILED === $state && '' !== $error ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Error:', 'universal-multilingual' ),
				esc_html( $error )
			);
		}

		echo '<p class="description">' . esc_html__(
			'While Activating, inbound localized paths are recognized but visitors are redirected to source-slug URLs until verification completes.',
			'universal-multilingual'
		) . '</p>';

		$this->render_localized_urls_honesty( $current );

		echo '</div>';

		if ( in_array( $state, array( LocalizedUrlsActivationService::STATE_OFF, LocalizedUrlsActivationService::STATE_FAILED ), true ) ) {
			$enable_label  = LocalizedUrlsActivationService::STATE_FAILED === $state
				? __( 'Retry activation', 'universal-multilingual' )
				: __( 'Enable localized URLs', 'universal-multilingual' );
			$enable_action = LocalizedUrlsActivationService::STATE_FAILED === $state
				? 'aiml_localized_urls_retry'
				: 'aiml_localized_urls_enable';
			$enable_nonce  = LocalizedUrlsActivationService::STATE_FAILED === $state
				? 'aiml_localized_urls_retry'
				: 'aiml_localized_urls_enable';

			printf(
				'<p><a class="button button-primary" href="%1$s" data-aiml-localized-urls-enable="1">%2$s</a></p>',
				esc_url(
					wp_nonce_url(
						add_query_arg( 'action', $enable_action, admin_url( 'admin-post.php' ) ),
						$enable_nonce
					)
				),
				esc_html( $enable_label )
			);
		}

		if ( in_array(
			$state,
			array(
				LocalizedUrlsActivationService::STATE_ON,
				LocalizedUrlsActivationService::STATE_ACTIVATING,
				LocalizedUrlsActivationService::STATE_FAILED,
			),
			true
		) ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url(
					wp_nonce_url(
						add_query_arg( 'action', 'aiml_localized_urls_disable', admin_url( 'admin-post.php' ) ),
						'aiml_localized_urls_disable'
					)
				),
				esc_html__( 'Disable localized URLs', 'universal-multilingual' )
			);
		}

		$this->render_localized_urls_confirmation_script();

		echo '<hr />';
	}

	/**
	 * Operator-readable admission + frontier honesty (P0 OC5–OC8).
	 *
	 * @param array<string, mixed> $current Settings.
	 */
	private function render_localized_urls_honesty( array $current ): void {
		echo '<hr style="margin:1em 0;" />';
		echo '<h3>' . esc_html__( 'Capability admission', 'universal-multilingual' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Implemented shapes are built into the plugin. Admitted shapes may be used for public localized URLs when Localized URLs are On. Unsupported means this site does not expose that shape.',
			'universal-multilingual'
		) . '</p>';

		$labels = array(
			RoutingCapabilityAdmission::SHAPE_TERM_ARCHIVE => __( 'Term archives', 'universal-multilingual' ),
			RoutingCapabilityAdmission::SHAPE_PAGE_HIERARCHICAL => __( 'Hierarchical pages', 'universal-multilingual' ),
			RoutingCapabilityAdmission::SHAPE_PRODUCT_CATEGORY_PERMALINK => __( 'Product category permalinks', 'universal-multilingual' ),
		);

		$code_epoch     = RoutingCapabilityAdmission::CODE_CAPABILITY_EPOCH;
		$verified_epoch = (int) ( $current['localized_urls_verified_capability_epoch'] ?? 0 );
		printf(
			'<p><strong>%1$s</strong> %2$s · <strong>%3$s</strong> %4$s</p>',
			esc_html__( 'Code capability epoch:', 'universal-multilingual' ),
			esc_html( (string) (int) $code_epoch ),
			esc_html__( 'Verified epoch:', 'universal-multilingual' ),
			esc_html( (string) (int) $verified_epoch )
		);

		echo '<ul class="aiml-lu-capability-list">';
		foreach ( RoutingCapabilityAdmission::CODE_SHAPES as $shape ) {
			$label    = $labels[ $shape ] ?? $shape;
			$admitted = false;
			if ( null !== $this->routing_admission ) {
				$admitted = $this->routing_admission->is_publicly_admitted( $shape );
			} else {
				$stored   = $current['localized_urls_admitted_capabilities'] ?? array();
				$admitted = is_array( $stored ) && in_array( $shape, $stored, true );
			}
			$status = $admitted
				? __( 'Implemented · Admitted', 'universal-multilingual' )
				: __( 'Implemented · Not admitted yet', 'universal-multilingual' );
			printf(
				'<li><strong>%1$s</strong> — %2$s</li>',
				esc_html( $label ),
				esc_html( $status )
			);
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__(
			'“Not admitted yet” usually means verification has not finished (not yet processed), not that the shape is unsupported.',
			'universal-multilingual'
		) . '</p>';

		$stored_fp = (string) ( $current['localized_urls_woo_product_fingerprint'] ?? '' );
		if ( '' !== $stored_fp ) {
			$current_fp = ( new WooProductPermalinkFingerprint() )->hash();
			if ( ! hash_equals( $stored_fp, $current_fp ) ) {
				echo '<p class="notice notice-warning" style="padding:8px;"><strong>' . esc_html__( 'Woo product permalink fingerprint mismatch.', 'universal-multilingual' ) . '</strong> ';
				echo esc_html__( 'Product category permalink capability may need re-verification after permalink setting changes.', 'universal-multilingual' ) . '</p>';
			}
		}

		echo '<h3>' . esc_html__( 'Hierarchy reindex / frontier', 'universal-multilingual' ) . '</h3>';
		if ( null === $this->frontier ) {
			echo '<p class="description">' . esc_html__( 'Frontier status is unavailable in this context.', 'universal-multilingual' ) . '</p>';
			return;
		}

		$recent = $this->frontier->list_recent( 50 );
		$counts = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'degraded'  => 0,
			'failed'    => 0,
		);
		foreach ( $recent as $row ) {
			$status = is_object( $row ) ? (string) ( $row->status ?? '' ) : '';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		$overall = __( 'Complete — no open frontier work in recent history', 'universal-multilingual' );
		if ( $counts['failed'] > 0 ) {
			$overall = __( 'Blocked / failed — review frontier failures', 'universal-multilingual' );
		} elseif ( $counts['degraded'] > 0 ) {
			$overall = __( 'Degraded — some children could not rematerialize (collision)', 'universal-multilingual' );
		} elseif ( $counts['pending'] > 0 || $counts['running'] > 0 ) {
			$overall = __( 'Pending — hierarchy rematerialization still processing', 'universal-multilingual' );
		}

		echo '<p><strong>' . esc_html__( 'Summary:', 'universal-multilingual' ) . '</strong> ' . esc_html( $overall ) . '</p>';
		printf(
			'<p class="description">%1$s %2$d · %3$s %4$d · %5$s %6$d · %7$s %8$d · %9$s %10$d</p>',
			esc_html__( 'Pending:', 'universal-multilingual' ),
			(int) $counts['pending'],
			esc_html__( 'Running:', 'universal-multilingual' ),
			(int) $counts['running'],
			esc_html__( 'Completed:', 'universal-multilingual' ),
			(int) $counts['completed'],
			esc_html__( 'Degraded:', 'universal-multilingual' ),
			(int) $counts['degraded'],
			esc_html__( 'Failed:', 'universal-multilingual' ),
			(int) $counts['failed']
		);
		echo '<p class="description">' . esc_html__(
			'Distinguish: pending = not yet processed; degraded/failed = genuine processing problem; not admitted = capability verification incomplete; unsupported = shape not offered by this site.',
			'universal-multilingual'
		) . '</p>';
	}

	/**
	 * Human-readable localized URL activation state.
	 *
	 * @param string $state Persisted state.
	 */
	private function localized_urls_state_label( string $state ): string {
		switch ( $state ) {
			case LocalizedUrlsActivationService::STATE_ON:
				return __( 'On', 'universal-multilingual' );
			case LocalizedUrlsActivationService::STATE_ACTIVATING:
				return __( 'Activating', 'universal-multilingual' );
			case LocalizedUrlsActivationService::STATE_FAILED:
				return __( 'Failed', 'universal-multilingual' );
			default:
				return __( 'Off', 'universal-multilingual' );
		}
	}

	/**
	 * Confirmation prompt before enabling localized URLs.
	 */
	private function render_localized_urls_confirmation_script(): void {
		$message = __(
			'Enabling localized URLs starts a background verification of all prepared active routes. Visitors may see source-slug URLs until activation completes. Continue?',
			'universal-multilingual'
		);

		printf(
			'<script>
			(function () {
				var link = document.querySelector("[data-aiml-localized-urls-enable]");
				if (!link) { return; }
				link.addEventListener("click", function (event) {
					if (!window.confirm(%1$s)) {
						event.preventDefault();
					}
				});
			}());
			</script>',
			wp_json_encode( $message )
		);
	}

	/**
	 * Redirects back to the settings screen after localized URL actions.
	 */
	private function redirect_localized_urls_settings(): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SETTINGS_SLUG,
					'aiml_updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders one Strategy F flag checkbox with dependency metadata.
	 *
	 * @param string $key                 Settings flag key.
	 * @param string $label               Field label.
	 * @param string $description         Help text.
	 * @param bool   $checked             Saved state.
	 * @param bool   $enabled             Whether the control is interactive.
	 * @param string $missing_prerequisite Prerequisite flag key when disabled.
	 * @param bool   $requires_confirm    Whether enabling requires explicit confirmation.
	 */
	private function strategy_f_checkbox_row(
		string $key,
		string $label,
		string $description,
		bool $checked,
		bool $enabled,
		string $missing_prerequisite = '',
		bool $requires_confirm = false
	): void {
		$input_id = 'aiml-' . $key;
		$deps     = FeatureFlags::prerequisite_label( $key );

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label for="' . esc_attr( $input_id ) . '">';
		printf(
			'<input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" class="aiml-strategy-f-flag"%4$s%5$s%6$s />',
			esc_attr( $input_id ),
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			$enabled ? '' : ' disabled="disabled"',
			$requires_confirm ? ' data-aiml-requires-confirm="1"' : ''
		);
		echo ' ' . esc_html( $description ) . '</label>';

		if ( '' !== $deps ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: prerequisite flag key(s) */
					__( 'Requires: %s', 'universal-multilingual' ),
					$deps
				)
			) . '</p>';
		}

		if ( ! $enabled && '' !== $missing_prerequisite ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: prerequisite flag key */
					__( 'Enable %s first.', 'universal-multilingual' ),
					$missing_prerequisite
				)
			) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Renders saved/effective Strategy F flag state without health queries.
	 *
	 * @param array<string, mixed> $saved     Persisted settings.
	 * @param array<string, mixed> $effective Dependency-validated settings.
	 * @param bool                 $valid     Whether the saved combination is valid.
	 */
	private function render_strategy_f_diagnostics( array $saved, array $effective, bool $valid ): void {
		echo '<div class="aiml-strategy-f-diagnostics" style="margin:1.5em 0;padding:1em;border:1px solid #ccd0d4;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Strategy F diagnostics (settings state only)', 'universal-multilingual' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Flag', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Saved', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Effective', 'universal-multilingual' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( FeatureFlags::PRODUCTION_FLAGS as $flag ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $flag ),
				esc_html( $this->flag_state_label( ! empty( $saved[ $flag ] ) ) ),
				esc_html( $this->flag_state_label( ! empty( $effective[ $flag ] ) ) )
			);
		}

		echo '</tbody></table>';
		printf(
			'<p><strong>%1$s</strong> %2$s<br /><strong>%3$s</strong> %4$s</p>',
			esc_html__( 'Combination valid:', 'universal-multilingual' ),
			esc_html( $valid ? __( 'Yes', 'universal-multilingual' ) : __( 'No', 'universal-multilingual' ) ),
			esc_html__( 'Frontend rendering active:', 'universal-multilingual' ),
			esc_html( $this->flag_state_label( ! empty( $effective[ FeatureFlags::FRONTEND_RENDER ] ) ) )
		);
		echo '</div>';
	}

	/**
	 * Renders TI.7 publication gate/mode Saved vs Effective diagnostics.
	 *
	 * @param array<string, mixed> $saved Persisted settings.
	 */
	private function render_publication_diagnostics( array $saved ): void {
		$sanitized      = Settings::sanitize( $saved );
		$gate_saved     = ! empty( $saved['segment_publication_gate_enabled'] );
		$gate_effective = ! empty( $sanitized['segment_publication_gate_enabled'] );
		$mode_saved     = (string) ( $saved['auto_publication_mode'] ?? PublicationMode::MANUAL );
		$mode_effective = (string) ( $sanitized['auto_publication_mode'] ?? PublicationMode::MANUAL );
		if ( ! PublicationMode::is_valid( $mode_effective ) ) {
			$mode_effective = PublicationMode::MANUAL;
		}

		echo '<div class="aiml-publication-diagnostics" style="margin:1.5em 0;padding:1em;border:1px solid #ccd0d4;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Publication diagnostics (settings state only)', 'universal-multilingual' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Setting', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Saved', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Effective', 'universal-multilingual' ) . '</th>';
		echo '</tr></thead><tbody>';

		printf(
			'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
			esc_html( 'segment_publication_gate_enabled' ),
			esc_html( $this->flag_state_label( $gate_saved ) ),
			esc_html( $this->flag_state_label( $gate_effective ) )
		);
		printf(
			'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
			esc_html( 'auto_publication_mode' ),
			esc_html( $mode_saved ),
			esc_html( $mode_effective )
		);

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Gate defaults off; mode defaults to manual. Setting mode to manual disables future automation without mass-unpublish.', 'universal-multilingual' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Confirmation prompts for gate enable and auto-publication mode changes.
	 */
	private function render_publication_confirmation_script(): void {
		$gate_message = __(
			'Enabling the publication gate immediately enforces overlay eligibility based on each segment\'s existing publish_status. It does not delete translations or mass-unpublish. Continue?',
			'universal-multilingual'
		);
		$mode_message = __(
			'Changing auto-publication mode only affects future maybe_auto_publish attempts after auto-translate. It does not reconcile inventory or mass-publish. Returning to manual stops future automation and does not mass-unpublish. Continue?',
			'universal-multilingual'
		);

		printf(
			'<script>
			(function () {
				var gate = document.querySelector(%1$s);
				if (gate) {
					gate.addEventListener("change", function () {
						if (!gate.checked) { return; }
						if (!window.confirm(%2$s)) { gate.checked = false; }
					});
				}
				var mode = document.getElementById("aiml-auto_publication_mode");
				if (mode) {
					var previous = mode.value;
					mode.addEventListener("change", function () {
						if (mode.value === previous) { return; }
						if (!window.confirm(%3$s)) {
							mode.value = previous;
							return;
						}
						previous = mode.value;
					});
				}
			}());
			</script>',
			wp_json_encode( 'input[name="' . Settings::OPTION . '[segment_publication_gate_enabled]"]' ),
			wp_json_encode( $gate_message ),
			wp_json_encode( $mode_message )
		);
	}

	/**
	 * Inline confirmation guard for enabling frontend rendering.
	 */
	private function render_strategy_f_confirmation_script(): void {
		$message = __(
			'Enabling frontend rendering may show translated block content to site visitors. Prerequisites must already be enabled. Disabling this flag is the immediate kill switch. Continue?',
			'universal-multilingual'
		);

		printf(
			'<script>
			(function () {
				var box = document.getElementById(%1$s);
				if (!box) { return; }
				box.addEventListener("change", function () {
					if (!box.checked || !box.hasAttribute("data-aiml-requires-confirm")) { return; }
					if (!window.confirm(%2$s)) { box.checked = false; }
				});
			}());
			</script>',
			wp_json_encode( 'aiml-' . FeatureFlags::FRONTEND_RENDER ),
			wp_json_encode( $message )
		);
	}

	/**
	 * Human-readable on/off label for diagnostics output.
	 *
	 * @param bool $enabled Flag state.
	 */
	private function flag_state_label( bool $enabled ): string {
		return $enabled ? __( 'On', 'universal-multilingual' ) : __( 'Off', 'universal-multilingual' );
	}

	/**
	 * Clears any queued Strategy F rejection notice for the current user.
	 */
	private function clear_flag_rejection_notice(): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		delete_transient( self::FLAG_NOTICE_TRANSIENT . '_' . $user_id );
	}

	/**
	 * Queues an admin notice when submitted production flags were normalized away.
	 *
	 * @param array<string, mixed> $payload Shared rejection audit payload.
	 */
	private function queue_flag_rejection_notice( array $payload ): void {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'set_transient' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || empty( $payload['dropped_flags'] ) ) {
			return;
		}

		set_transient(
			self::FLAG_NOTICE_TRANSIENT . '_' . $user_id,
			array(
				'id'        => self::FLAG_NOTICE_ID,
				'dropped'   => $payload['dropped_flags'],
				'submitted' => $payload['submitted'],
				'effective' => $payload['effective'],
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints Strategy F dependency rejection notices after settings save.
	 */
	public function render_strategy_f_admin_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::SETTINGS_SLUG !== $page ) {
			return;
		}

		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_transient' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$payload = get_transient( self::FLAG_NOTICE_TRANSIENT . '_' . $user_id );
		if ( ! is_array( $payload ) || empty( $payload['dropped'] ) ) {
			return;
		}

		delete_transient( self::FLAG_NOTICE_TRANSIENT . '_' . $user_id );

		$messages = array();
		foreach ( (array) $payload['dropped'] as $flag ) {
			if ( ! is_string( $flag ) ) {
				continue;
			}

			$prerequisite = FeatureFlags::prerequisite_label( $flag );
			$messages[]   = sprintf(
				/* translators: 1: flag key, 2: prerequisite flag key(s) */
				__( '%1$s could not be enabled because prerequisite %2$s is off.', 'universal-multilingual' ),
				$flag,
				$prerequisite
			);
		}

		printf(
			'<div class="notice notice-warning is-dismissible" data-notice-id="%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( self::FLAG_NOTICE_ID ),
			esc_html__( 'Strategy F flag combination adjusted.', 'universal-multilingual' ),
			esc_html( implode( ' ', $messages ) )
		);
	}

	/**
	 * Human-readable label for a language state.
	 *
	 * @param string $status Status value.
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
	 * Redirects back to a screen carrying the outcome of a write.
	 *
	 * @param true|int|WP_Error $result Outcome from the languages store.
	 * @param string            $page   Admin page slug to return to.
	 */
	private function redirect_with_result( $result, string $page ): void {
		$args = array( 'page' => $page );

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
