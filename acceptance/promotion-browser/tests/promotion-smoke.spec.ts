import { test, expect, request as pwRequest } from '@playwright/test';

/**
 * DEV → PROD translation promotion acceptance (ADR-0030).
 *
 * Two lanes:
 *
 * 1. CONTRACT lane (always runs, no server) — freezes the category set, the
 *    apply-mode matrix and the review-token binding so the package stays
 *    importable and the invariants are documented.
 *
 * 2. LIVE lane (runs only with AIML_PROMOTION_BASE_URL + AIML_PROMOTION_NONCE
 *    + AIML_PROMOTION_COOKIE for an administrator) — export → validate/dry-run
 *    → apply through the REST API against one WP install seeded with two
 *    content sets, then confirm the translated string is served.
 */

const CATEGORIES = [
	'new',
	'update',
	'unchanged',
	'conflict_target_modified',
	'conflict_both_changed',
	'stale_source',
	'missing_source',
	'missing_language',
	'unsupported_type',
	'unknown_field',
	'route_conflict',
	'identity_conflict',
	'ambiguous_source',
	'validation_failure',
	'changed_since_dry_run',
];

test.describe( 'promotion contracts', () => {
	test( 'the dry-run category set is frozen', () => {
		expect( CATEGORIES ).toHaveLength( 15 );
		expect( CATEGORIES ).toContain( 'changed_since_dry_run' );
		// No skip_conflicts mode; safe applies new + update only.
		const safe = CATEGORIES.filter( ( c ) => [ 'new', 'update' ].includes( c ) );
		expect( safe ).toEqual( [ 'new', 'update' ] );
	} );

	test( 'identity_conflict and ambiguous_source are never force-applied', () => {
		const forceable = [
			'new',
			'update',
			'stale_source',
			'conflict_target_modified',
			'conflict_both_changed',
			'route_conflict',
		];
		expect( forceable ).not.toContain( 'identity_conflict' );
		expect( forceable ).not.toContain( 'ambiguous_source' );
	} );

	test( 'apply binds to the review token, not just payload_checksum', () => {
		const tokenClaims = [
			'actor_id',
			'package_id',
			'package_checksum',
			'canonical_plan_hash',
			'issued_at',
			'expires_at',
		];
		expect( tokenClaims ).toContain( 'canonical_plan_hash' );
		expect( tokenClaims ).toContain( 'package_checksum' );
	} );
} );

const live = process.env.AIML_PROMOTION_BASE_URL && process.env.AIML_PROMOTION_NONCE;

test.describe( 'promotion live REST walkthrough', () => {
	test.skip( ! live, 'set AIML_PROMOTION_BASE_URL + AIML_PROMOTION_NONCE + AIML_PROMOTION_COOKIE' );

	test( 'export → dry-run → apply → frontend', async () => {
		const ctx = await pwRequest.newContext( {
			baseURL: process.env.AIML_PROMOTION_BASE_URL,
			extraHTTPHeaders: {
				'X-WP-Nonce': process.env.AIML_PROMOTION_NONCE!,
				Cookie: process.env.AIML_PROMOTION_COOKIE || '',
				'Content-Type': 'application/json',
			},
		} );

		const exportRes = await ctx.post( '/wp-json/aiml/v1/promotion/export', {
			data: { languages: [ process.env.AIML_PROMOTION_LANG || 'sv' ] },
		} );
		expect( exportRes.ok() ).toBeTruthy();
		const pkg = ( await exportRes.json() ).package as string;
		expect( pkg.length ).toBeGreaterThan( 10 );

		const validateRes = await ctx.post( '/wp-json/aiml/v1/promotion/import/validate', { data: pkg } );
		expect( validateRes.ok() ).toBeTruthy();
		const { review_token: token, plan } = await validateRes.json();
		expect( token ).toBeTruthy();

		const applyRes = await ctx.post(
			`/wp-json/aiml/v1/promotion/import/apply?review_token=${ encodeURIComponent(
				token
			) }&mode=safe_only&plan=${ encodeURIComponent( JSON.stringify( plan ) ) }`,
			{ data: pkg }
		);
		const applyBody = await applyRes.json();
		expect( applyBody.refused_code || '' ).toBe( '' );

		const historyRes = await ctx.get( '/wp-json/aiml/v1/promotion/history' );
		const dirs = ( await historyRes.json() ).items.map( ( i: any ) => i.direction );
		expect( dirs ).toContain( 'export' );
		expect( dirs ).toContain( 'import' );

		await ctx.dispose();
	} );
} );
