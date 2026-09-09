<?php
/**
 * Stateless signed dry-run review token (ADR-0030 §6/§7).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * `base64url(canonical_json(claims)) . "." . base64url(HMAC_sha256(secret, canonical_json(claims)))`.
 *
 * The claims travel in the token — the server recovers and re-verifies them
 * without a second copy and without persisting anything. The token binds an
 * apply to the exact actor, package artifact and reviewed plan.
 */
final class ReviewToken {

	/**
	 * Default token lifetime.
	 */
	public const TTL_SECONDS = 3600;

	/**
	 * Builds the helper.
	 *
	 * @param string $secret HMAC key (defaults to a wp_salt-derived value).
	 */
	public function __construct(
		private readonly string $secret = ''
	) {
	}

	/**
	 * Mints a token for a reviewed plan.
	 *
	 * @param int    $actor_id           Reviewing user id.
	 * @param string $package_id         Package id.
	 * @param string $package_checksum   Whole-artifact checksum.
	 * @param string $canonical_plan_hash Hash of the reviewed ImportPlan.
	 * @param int    $ttl                Lifetime in seconds.
	 */
	public function mint( int $actor_id, string $package_id, string $package_checksum, string $canonical_plan_hash, int $ttl = self::TTL_SECONDS ): string {
		$now    = time();
		$claims = array(
			'actor_id'            => $actor_id,
			'package_id'          => $package_id,
			'package_checksum'    => $package_checksum,
			'canonical_plan_hash' => $canonical_plan_hash,
			'issued_at'           => $now,
			'expires_at'          => $now + $ttl,
		);

		$body = CanonicalJson::encode( $claims );

		return $this->b64( $body ) . '.' . $this->b64( $this->sign( $body ) );
	}

	/**
	 * Verifies a token and returns its claims, or a failure code.
	 *
	 * @param string $token          The token string.
	 * @param int    $actor_id       The current user id.
	 * @param string $package_checksum The re-uploaded package's checksum.
	 * @return array{ok:bool,code:string,claims:array<string, mixed>}
	 */
	public function verify( string $token, int $actor_id, string $package_checksum ): array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return $this->fail( 'token_malformed' );
		}

		$body = $this->unb64( $parts[0] );
		$sig  = $this->unb64( $parts[1] );
		if ( '' === $body || ! hash_equals( $this->sign( $body ), $sig ) ) {
			return $this->fail( 'token_bad_signature' );
		}

		$claims = json_decode( $body, true );
		if ( ! is_array( $claims ) ) {
			return $this->fail( 'token_malformed' );
		}

		if ( (int) ( $claims['expires_at'] ?? 0 ) < time() ) {
			return $this->fail( 'token_expired' );
		}
		if ( (int) ( $claims['actor_id'] ?? -1 ) !== $actor_id ) {
			return $this->fail( 'token_wrong_actor' );
		}
		if ( ! hash_equals( (string) ( $claims['package_checksum'] ?? '' ), $package_checksum ) ) {
			return $this->fail( 'token_package_mismatch' );
		}

		return array(
			'ok'     => true,
			'code'   => '',
			'claims' => $claims,
		);
	}

	/**
	 * Builds a verification failure result.
	 *
	 * @param string $code Failure code.
	 * @return array{ok:bool,code:string,claims:array<string, mixed>}
	 */
	private function fail( string $code ): array {
		return array(
			'ok'     => false,
			'code'   => $code,
			'claims' => array(),
		);
	}

	/**
	 * HMAC of a body with the configured / derived secret.
	 *
	 * @param string $body Canonical claims body.
	 */
	private function sign( string $body ): string {
		return hash_hmac( 'sha256', $body, $this->key(), true );
	}

	/**
	 * The HMAC key.
	 */
	private function key(): string {
		if ( '' !== $this->secret ) {
			return $this->secret;
		}

		return function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) . '|aiml-promotion' : 'aiml-promotion-fallback-key';
	}

	/**
	 * URL-safe base64 encode without padding.
	 *
	 * @param string $raw Bytes.
	 */
	private function b64( string $raw ): string {
		// Token transport encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $encoded Encoded string.
	 */
	private function unb64( string $encoded ): string {
		// Token transport decoding, not obfuscation.
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return false === $decoded ? '' : $decoded;
	}
}
