<?php
/**
 * Language field provider for Regional Preferences.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

/**
 * Owns language slot render + save. Never writes currency meta.
 */
final class PreferredLanguageField {

	public const FIELD_NAME   = 'aiml_preferred_language';
	public const NONCE_NAME   = 'aiml_preferred_language_nonce';
	public const NONCE_ACTION = 'aiml_preferred_language_save';

	/**
	 * Preference service.
	 *
	 * @var PreferredLanguage
	 */
	private PreferredLanguage $preference;

	/**
	 * Creates the language field provider.
	 *
	 * @param PreferredLanguage $preference Preference service.
	 */
	public function __construct( PreferredLanguage $preference ) {
		$this->preference = $preference;
	}

	/**
	 * Registers composition hooks.
	 */
	public function register(): void {
		add_action( RegionalPreferencesComposition::ACTION_RENDER, array( $this, 'render' ), 10, 1 );
		add_action( RegionalPreferencesComposition::ACTION_SAVE, array( $this, 'save' ), 10, 1 );
		add_filter( RegionalPreferencesComposition::FILTER_CLAIMED_SLOTS, array( $this, 'claim_slot' ), 10, 1 );
	}

	/**
	 * Claims the language slot whenever UML is loaded.
	 *
	 * @param array<int, string> $slots Claimed slots.
	 * @return array<int, string>
	 */
	public function claim_slot( array $slots ): array {
		$slots[] = RegionalPreferencesComposition::SLOT_LANGUAGE;
		return array_values( array_unique( $slots ) );
	}

	/**
	 * Renders the language preference field.
	 *
	 * @param array{surface: string, user_id: int, can_edit: bool} $context Composition context.
	 */
	public function render( array $context ): void {
		$user_id = (int) ( $context['user_id'] ?? 0 );
		$state   = $this->preference->get_state( $user_id );
		if ( null === $state ) {
			return;
		}

		$editable = ! empty( $context['can_edit'] ) && ! empty( $state['editable'] );
		$surface  = (string) ( $context['surface'] ?? RegionalPreferencesComposition::SURFACE_PROFILE );

		echo '<tr class="aiml-regional-preference-language"><th><label for="' . esc_attr( self::FIELD_NAME ) . '">';
		echo esc_html__( 'Language', 'universal-multilingual' );
		echo '</label></th><td>';

		if ( $editable ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
			echo '<select name="' . esc_attr( self::FIELD_NAME ) . '" id="' . esc_attr( self::FIELD_NAME ) . '">';
			echo '<option value="">' . esc_html__( 'Use site default', 'universal-multilingual' ) . '</option>';
			$selected = is_string( $state['stored'] ?? null ) && PreferredLanguage::SOURCE_STORED === ( $state['source'] ?? '' )
				? (string) $state['stored']
				: '';
			foreach ( $state['options'] as $code => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( (string) $code ),
					selected( $selected, (string) $code, false ),
					esc_html( (string) $label )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" id="%s" value="%s" class="regular-text" disabled="disabled" />',
				esc_attr( self::FIELD_NAME ),
				esc_attr( (string) $state['label'] )
			);
			if ( ! empty( $state['unavailable_message'] ) ) {
				echo '<p class="description">' . esc_html( (string) $state['unavailable_message'] ) . '</p>';
			} elseif ( empty( $state['available'] ) ) {
				echo '<p class="description">' . esc_html__( 'Multilingual support is not currently available.', 'universal-multilingual' ) . '</p>';
			}
		}

		if ( RegionalPreferencesComposition::SURFACE_ACCOUNT === $surface ) {
			echo '<span class="description">';
			echo esc_html__( 'Preferred language for your account. It does not change the language of the page you are viewing.', 'universal-multilingual' );
			echo '</span>';
		}

		echo '</td></tr>';
	}

	/**
	 * Processes the language preference POST.
	 *
	 * @param array{surface: string, user_id: int, can_edit: bool} $context Composition context.
	 */
	public function save( array $context ): void {
		if ( empty( $context['can_edit'] ) ) {
			return;
		}

		$user_id = (int) ( $context['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::FIELD_NAME ] ) ) {
			return;
		}

		$code = sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_NAME ] ) );
		$this->preference->set( $user_id, '' === $code ? null : $code );
	}
}
