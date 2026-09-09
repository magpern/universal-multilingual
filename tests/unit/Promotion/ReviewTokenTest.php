<?php
/**
 * Stateless signed review token (ADR-0030 §6/§7).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\ReviewToken;
use PHPUnit\Framework\TestCase;

/**
 * The token binds actor + package checksum + reviewed plan hash; claims travel
 * inside it and are re-verified without a side copy.
 */
final class ReviewTokenTest extends TestCase {

	private ReviewToken $tokens;

	protected function setUp(): void {
		$this->tokens = new ReviewToken( 'test-secret-key' );
	}

	public function test_a_fresh_token_verifies_and_returns_its_claims(): void {
		$token  = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );
		$result = $this->tokens->verify( $token, 7, 'checksum-abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'plan-hash-1', $result['claims']['canonical_plan_hash'] );
		$this->assertSame( 'pkg-1', $result['claims']['package_id'] );
	}

	public function test_wrong_actor_is_rejected(): void {
		$token = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );

		$this->assertSame( 'token_wrong_actor', $this->tokens->verify( $token, 8, 'checksum-abc' )['code'] );
	}

	public function test_package_checksum_mismatch_is_rejected(): void {
		$token = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );

		$this->assertSame( 'token_package_mismatch', $this->tokens->verify( $token, 7, 'checksum-XXX' )['code'] );
	}

	public function test_expired_token_is_rejected(): void {
		$token = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1', -10 );

		$this->assertSame( 'token_expired', $this->tokens->verify( $token, 7, 'checksum-abc' )['code'] );
	}

	public function test_tampered_signature_is_rejected(): void {
		$token    = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );
		[ $body ] = explode( '.', $token );
		$forged   = $body . '.' . rtrim( strtr( base64_encode( 'not-the-signature' ), '+/', '-_' ), '=' );

		$this->assertSame( 'token_bad_signature', $this->tokens->verify( $forged, 7, 'checksum-abc' )['code'] );
	}

	public function test_tampered_claims_are_rejected(): void {
		$token       = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );
		[ , $sig ]   = explode( '.', $token );
		$forged_body = rtrim( strtr( base64_encode( '{"actor_id":9999,"package_checksum":"checksum-abc","expires_at":99999999999}' ), '+/', '-_' ), '=' );

		$this->assertSame( 'token_bad_signature', $this->tokens->verify( $forged_body . '.' . $sig, 7, 'checksum-abc' )['code'] );
	}

	public function test_malformed_token_is_rejected(): void {
		$this->assertSame( 'token_malformed', $this->tokens->verify( 'not-a-token', 7, 'checksum-abc' )['code'] );
	}

	public function test_a_different_secret_cannot_verify(): void {
		$token = $this->tokens->mint( 7, 'pkg-1', 'checksum-abc', 'plan-hash-1' );
		$other = new ReviewToken( 'a-completely-different-key' );

		$this->assertSame( 'token_bad_signature', $other->verify( $token, 7, 'checksum-abc' )['code'] );
	}
}
