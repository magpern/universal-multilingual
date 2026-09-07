<?php
/**
 * Regional Preferences section host (UML primary).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

/**
 * Opens the Regional Preferences shell once; providers own field render/save.
 */
final class RegionalPreferencesHost {

	public const HOST_PRIORITY = 10;

	/**
	 * Registers WordPress profile and WooCommerce account hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render_profile' ), self::HOST_PRIORITY );
		add_action( 'edit_user_profile', array( $this, 'render_profile' ), self::HOST_PRIORITY );
		add_action( 'personal_options_update', array( $this, 'save_profile' ), self::HOST_PRIORITY );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ), self::HOST_PRIORITY );

		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account' ), self::HOST_PRIORITY );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account' ), self::HOST_PRIORITY );
	}

	/**
	 * Profile surface render.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render_profile( $user ): void {
		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;
		$this->render_section(
			RegionalPreferencesComposition::SURFACE_PROFILE,
			$user_id,
			$this->can_edit_user( $user_id )
		);
	}

	/**
	 * Profile surface save.
	 *
	 * @param int $user_id User id.
	 */
	public function save_profile( int $user_id ): void {
		$this->save_section(
			RegionalPreferencesComposition::SURFACE_PROFILE,
			$user_id,
			$this->can_edit_user( $user_id )
		);
	}

	/**
	 * My Account → Account details render.
	 */
	public function render_account(): void {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		if ( did_action( RegionalPreferencesComposition::ACTION_RENDERED ) > 0 ) {
			return;
		}

		echo '<fieldset class="aiml-regional-preferences">';
		$this->render_section(
			RegionalPreferencesComposition::SURFACE_ACCOUNT,
			$user_id,
			true,
			false
		);
		echo '</fieldset>';
	}

	/**
	 * My Account save.
	 *
	 * @param int $user_id Current customer id.
	 */
	public function save_account( int $user_id ): void {
		$current = (int) get_current_user_id();
		if ( $user_id <= 0 || $current !== $user_id ) {
			return;
		}

		$this->save_section(
			RegionalPreferencesComposition::SURFACE_ACCOUNT,
			$user_id,
			true
		);
	}

	/**
	 * Opens the section once and fires provider render + unclaimed fallbacks.
	 *
	 * @param string $surface     profile|account.
	 * @param int    $user_id     Target user.
	 * @param bool   $can_edit    Whether viewer may edit.
	 * @param bool   $table_wrap  Whether to wrap rows in a table (profile).
	 */
	private function render_section( string $surface, int $user_id, bool $can_edit, bool $table_wrap = true ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		if ( did_action( RegionalPreferencesComposition::ACTION_RENDERED ) > 0 ) {
			return;
		}

		/**
		 * Marks the Regional Preferences shell as rendered (once per request).
		 *
		 * @since 1.12.0
		 */
		do_action( RegionalPreferencesComposition::ACTION_RENDERED );

		$context = RegionalPreferencesComposition::context( $surface, $user_id, $can_edit );

		if ( $table_wrap ) {
			echo '<h2>' . esc_html__( 'Regional preferences', 'universal-multilingual' ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tbody>';
		} else {
			echo '<legend>' . esc_html__( 'Regional preferences', 'universal-multilingual' ) . '</legend>';
			echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
			echo '<table class="form-table" role="presentation"><tbody>';
		}

		/**
		 * Filters provider-claimed Regional Preferences slots.
		 *
		 * @since 1.12.0
		 *
		 * @param array<int, string> $slots Claimed slots.
		 */
		$claimed = apply_filters( RegionalPreferencesComposition::FILTER_CLAIMED_SLOTS, array() );

		/**
		 * Renders fields supplied by Regional Preferences providers.
		 *
		 * @since 1.12.0
		 *
		 * @param array{surface: string, user_id: int, can_edit: bool} $context Composition context.
		 */
		do_action( RegionalPreferencesComposition::ACTION_RENDER, $context );

		if ( ! in_array( RegionalPreferencesComposition::SLOT_LANGUAGE, $claimed, true ) ) {
			$this->render_language_fallback();
		}
		if ( ! in_array( RegionalPreferencesComposition::SLOT_CURRENCY, $claimed, true ) ) {
			$this->render_currency_fallback();
		}

		echo '</tbody></table>';
		if ( ! $table_wrap ) {
			echo '</p>';
		}
	}

	/**
	 * Fires provider save action once for this host attempt.
	 *
	 * @param string $surface  profile|account.
	 * @param int    $user_id  Target user.
	 * @param bool   $can_edit Whether viewer may edit.
	 */
	private function save_section( string $surface, int $user_id, bool $can_edit ): void {
		if ( $user_id <= 0 || ! $can_edit ) {
			return;
		}

		if ( did_action( RegionalPreferencesComposition::ACTION_SAVE_STARTED ) > 0 ) {
			return;
		}

		/**
		 * Marks Regional Preferences save as started (once per request).
		 *
		 * @since 1.12.0
		 */
		do_action( RegionalPreferencesComposition::ACTION_SAVE_STARTED );

		$context = RegionalPreferencesComposition::context( $surface, $user_id, $can_edit );

		/**
		 * Lets providers process their own Regional Preferences POST fields.
		 *
		 * @since 1.12.0
		 *
		 * @param array{surface: string, user_id: int, can_edit: bool} $context Composition context.
		 */
		do_action( RegionalPreferencesComposition::ACTION_SAVE, $context );
	}

	/**
	 * Host-owned language RO fallback (provider absent). Uses site WPLANG only.
	 */
	private function render_language_fallback(): void {
		echo '<tr class="um-regional-preference-language-fallback"><th>';
		echo esc_html__( 'Language', 'universal-multilingual' );
		echo '</th><td>';
		printf(
			'<input type="text" value="%s" class="regular-text" disabled="disabled" />',
			esc_attr( SiteDefaultLanguage::label() )
		);
		echo '<p class="description">';
		echo esc_html__( 'Multilingual support is not currently available.', 'universal-multilingual' );
		echo '</p></td></tr>';
	}

	/**
	 * Host-owned currency RO fallback (provider absent).
	 */
	private function render_currency_fallback(): void {
		$label = $this->store_currency_label();

		echo '<tr class="um-regional-preference-currency-fallback"><th>';
		echo esc_html__( 'Currency', 'universal-multilingual' );
		echo '</th><td>';
		printf(
			'<input type="text" value="%s" class="regular-text" disabled="disabled" />',
			esc_attr( $label )
		);
		echo '<p class="description">';
		echo esc_html__( 'Multicurrency support is not currently available.', 'universal-multilingual' );
		echo '</p></td></tr>';
	}

	/**
	 * Store currency label when WooCommerce is present; otherwise a generic unavailable label.
	 */
	private function store_currency_label(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = (string) get_woocommerce_currency();
			$name = function_exists( 'get_woocommerce_currency_symbol' )
				? (string) get_woocommerce_currency_symbol( $code )
				: '';
			$base = '' !== $name ? sprintf( '%s — %s', $code, $name ) : $code;

			return sprintf(
				/* translators: %s: currency code/name */
				__( '%s (store default)', 'universal-multilingual' ),
				$base
			);
		}

		return __( 'Store default (unavailable)', 'universal-multilingual' );
	}

	/**
	 * Whether the viewer may edit the target user.
	 *
	 * @param int $user_id Target user.
	 */
	private function can_edit_user( int $user_id ): bool {
		$current = (int) get_current_user_id();
		if ( $current > 0 && $current === $user_id ) {
			return true;
		}

		return current_user_can( 'edit_user', $user_id );
	}
}
