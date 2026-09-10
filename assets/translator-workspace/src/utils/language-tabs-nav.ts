/**
 * MLW1a — pure keyboard-navigation math for the LanguageTabs strip (WP2).
 *
 * Kept out of the component so the roving-tabindex behaviour (ArrowLeft/Right,
 * ArrowUp/Down, Home, End, wrap-around) is unit-testable without a DOM.
 */

/**
 * Index of the tab a key press should move focus to, or `null` for keys that
 * are not navigation keys (the component then lets the event through).
 *
 * @param key   KeyboardEvent.key value.
 * @param index Currently focused tab index.
 * @param count Total number of tabs.
 */
export function nextTabIndex(
	key: string,
	index: number,
	count: number
): number | null {
	if ( count <= 0 ) {
		return null;
	}
	switch ( key ) {
		case 'ArrowRight':
		case 'ArrowDown':
			return ( index + 1 ) % count;
		case 'ArrowLeft':
		case 'ArrowUp':
			return ( index - 1 + count ) % count;
		case 'Home':
			return 0;
		case 'End':
			return count - 1;
		default:
			return null;
	}
}
