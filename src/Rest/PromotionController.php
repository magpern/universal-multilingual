<?php
/**
 * DEV → PROD translation promotion REST API (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Rest;

use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Promotion\ApplyMode;
use AIMultilingual\Promotion\ExportFilters;
use AIMultilingual\Promotion\JsonPackageSerializer;
use AIMultilingual\Promotion\PromotionCapabilities;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationImportService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Thin controller: validate → authorize → delegate to the promotion services →
 * serialize. Every route requires {@see PromotionCapabilities::PROMOTE}; the
 * trust-review path additionally requires {@see PromotionCapabilities::TRUST_REVIEW_STATE}.
 */
final class PromotionController {

	public const REST_NAMESPACE = 'aiml/v1';
	public const REST_BASE      = 'promotion';

	/**
	 * Builds the controller.
	 *
	 * @param TranslationExportService $exporter Export service.
	 * @param TranslationImportService $importer Import service.
	 * @param PromotionLogRepository   $log      Promotion log repository.
	 */
	public function __construct(
		private readonly TranslationExportService $exporter,
		private readonly TranslationImportService $importer,
		private readonly PromotionLogRepository $log
	) {
	}

	/**
	 * Registers REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		if ( did_action( 'rest_api_init' ) ) {
			$this->register_routes();
		}
	}

	/**
	 * Route registration callback.
	 */
	public function register_routes(): void {
		$base = '/' . self::REST_BASE;

		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'can_promote' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/import/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => array( $this, 'can_promote' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/import/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'can_promote' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'can_promote' ),
			)
		);
	}

	/**
	 * Permission check for every promotion route.
	 *
	 * @return true|WP_Error
	 */
	public function can_promote() {
		if ( current_user_can( PromotionCapabilities::PROMOTE ) ) {
			return true;
		}

		return new WP_Error(
			'aiml_forbidden',
			__( 'You do not have permission to promote translations.', 'universal-multilingual' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Builds a package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		$body    = $this->json_body( $request );
		$filters = ExportFilters::from_request( $body );

		if ( array() === $filters->language_codes ) {
			return new WP_Error( 'aiml_no_languages', __( 'Select at least one target language.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$result = $this->exporter->export( $filters );
		if ( ! $result->is_ok() ) {
			return new WP_Error( 'aiml_' . $result->error_code(), $result->message(), array( 'status' => 422 ) );
		}

		$package = $result->package();

		return new WP_REST_Response(
			array(
				'filename'         => $this->exporter->filename( $package ),
				'package'          => ( new JsonPackageSerializer() )->serialize( $package ),
				'package_id'       => $package->package_id(),
				'package_checksum' => $package->package_checksum(),
				'counts'           => $package->payload()['counts'],
			)
		);
	}

	/**
	 * Validates an uploaded package and returns a read-only dry-run plan + token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate( WP_REST_Request $request ) {
		$raw = $this->uploaded_package( $request );
		if ( $raw instanceof WP_Error ) {
			return $raw;
		}

		$validated = $this->importer->validate( $raw );
		if ( ! $validated['ok'] ) {
			return new WP_Error(
				'aiml_' . $validated['code'],
				implode( ' ', $validated['messages'] ),
				array( 'status' => 422 )
			);
		}

		$prepared = $this->importer->plan( $validated['package'] );

		return new WP_REST_Response(
			array(
				'review_token' => $prepared['token'],
				'plan'         => $prepared['plan']->to_array(),
			)
		);
	}

	/**
	 * Applies a reviewed package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$raw = $this->uploaded_package( $request );
		if ( $raw instanceof WP_Error ) {
			return $raw;
		}

		$token = (string) $request->get_param( 'review_token' );
		if ( '' === $token ) {
			return new WP_Error( 'aiml_missing_token', __( 'The review token is required.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$plan_param = $request->get_param( 'plan' );
		$plan       = is_array( $plan_param ) ? $plan_param : (array) json_decode( (string) $plan_param, true );

		$trust_review = (bool) $request->get_param( 'trust_review' );
		if ( $trust_review && ! current_user_can( PromotionCapabilities::TRUST_REVIEW_STATE ) ) {
			return new WP_Error(
				'aiml_forbidden_trust',
				__( 'You do not have permission to carry review and publication state across environments.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		$result = $this->importer->apply(
			$raw,
			$token,
			$plan,
			ApplyMode::normalize( (string) $request->get_param( 'mode' ) ),
			$this->string_list( $request->get_param( 'row_allowlist' ) ),
			$this->string_list( $request->get_param( 'acknowledgements' ) ),
			$trust_review
		);

		$status = $result->is_refused() ? 409 : 200;

		return new WP_REST_Response( $result->to_array(), $status );
	}

	/**
	 * Returns recent promotion-log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$requested = (int) $request->get_param( 'limit' );
		$limit     = max( 1, min( 100, $requested > 0 ? $requested : 50 ) );

		$rows = array();
		foreach ( $this->log->recent( $limit ) as $row ) {
			$rows[] = array(
				'promotion_id'     => (int) $row->promotion_id,
				'direction'        => (string) $row->direction,
				'package_id'       => (string) $row->package_id,
				'package_checksum' => (string) $row->package_checksum,
				'mode'             => (string) $row->mode,
				'language_codes'   => (string) $row->language_codes,
				'result'           => (string) $row->result,
				'counts'           => null === $row->counts_json ? null : json_decode( (string) $row->counts_json, true ),
				'actor_id'         => (int) $row->actor_id,
				'created_at'       => (string) $row->created_at,
				'finished_at'      => $row->finished_at,
			);
		}

		return new WP_REST_Response( array( 'items' => $rows ) );
	}

	/**
	 * Reads and size-checks the uploaded package file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function uploaded_package( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['package'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			// Allow a raw JSON body as a fallback (CLI-style callers / tests).
			$raw = (string) $request->get_body();
			if ( '' !== trim( $raw ) && '{' === substr( ltrim( $raw ), 0, 1 ) ) {
				return $raw;
			}

			return new WP_Error( 'aiml_no_package', __( 'Upload a promotion package file.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( ! str_ends_with( strtolower( $name ), '.json' ) ) {
			return new WP_Error( 'aiml_bad_file_type', __( 'The package must be a .json file.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		if ( (int) ( $file['size'] ?? 0 ) > $this->importer->max_package_bytes() ) {
			return new WP_Error( 'aiml_package_too_large', __( 'The package exceeds the size limit.', 'universal-multilingual' ), array( 'status' => 413 ) );
		}

		// Reading a local upload tmp file — not a remote URL.
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return false === $contents ? new WP_Error( 'aiml_read_failed', __( 'Could not read the uploaded file.', 'universal-multilingual' ), array( 'status' => 500 ) ) : $contents;
	}

	/**
	 * Decodes a JSON request body to an array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function json_body( WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) ) {
			return $json;
		}

		$params = $request->get_params();

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Normalizes a loose value into a string list.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string>
	 */
	private function string_list( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $value ) ) );
		}

		return array_values( array_map( 'strval', (array) $value ) );
	}
}
