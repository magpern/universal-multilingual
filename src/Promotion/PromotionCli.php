<?php
/**
 * WP-CLI DEV → PROD translation promotion commands (ADR-0030 §12).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

use WP_CLI;

/**
 * Reuses the exact same domain services as the REST API.
 *
 * Commands:
 * - `wp aiml promotion export --lang=sv[,de] [--post-types=post,product]
 *   [--approved-only] [--published-only] [--changed-since=2026-09-01] [--file=pkg.json]`
 * - `wp aiml promotion import <file>` (dry-run)
 * - `wp aiml promotion import <file> --apply --mode=safe_only|selective|force`
 * - `wp aiml promotion history [--limit=20]`
 * - `wp aiml promotion backfill-identity`
 */
final class PromotionCli {

	/**
	 * Builds the CLI.
	 *
	 * @param TranslationExportService                          $exporter   Export service.
	 * @param TranslationImportService                          $importer   Import service.
	 * @param \AIMultilingual\Database\PromotionLogRepository   $log        Promotion log.
	 * @param \AIMultilingual\Database\ObjectIdentityRepository $identities Identity repository.
	 */
	public function __construct(
		private readonly TranslationExportService $exporter,
		private readonly TranslationImportService $importer,
		private readonly \AIMultilingual\Database\PromotionLogRepository $log,
		private readonly \AIMultilingual\Database\ObjectIdentityRepository $identities
	) {
	}

	/**
	 * Registers the `wp aiml promotion` command tree.
	 *
	 * @param TranslationExportService                          $exporter   Export service.
	 * @param TranslationImportService                          $importer   Import service.
	 * @param \AIMultilingual\Database\PromotionLogRepository   $log        Promotion log.
	 * @param \AIMultilingual\Database\ObjectIdentityRepository $identities Identity repository.
	 */
	public static function register(
		TranslationExportService $exporter,
		TranslationImportService $importer,
		\AIMultilingual\Database\PromotionLogRepository $log,
		\AIMultilingual\Database\ObjectIdentityRepository $identities
	): void {
		if ( ! class_exists( WP_CLI::class ) || ! method_exists( WP_CLI::class, 'add_command' ) ) {
			return;
		}

		$instance = new self( $exporter, $importer, $log, $identities );

		WP_CLI::add_command( 'aiml promotion export', array( $instance, 'export' ), array( 'shortdesc' => 'Builds a translation promotion package.' ) );
		WP_CLI::add_command( 'aiml promotion import', array( $instance, 'import' ), array( 'shortdesc' => 'Dry-runs or applies a translation promotion package.' ) );
		WP_CLI::add_command( 'aiml promotion history', array( $instance, 'history' ), array( 'shortdesc' => 'Lists recent promotions.' ) );
		WP_CLI::add_command( 'aiml promotion backfill-identity', array( $instance, 'backfill_identity' ), array( 'shortdesc' => 'Mints object identity UUIDs for existing translatable objects.' ) );
	}

	/**
	 * Builds a package.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );

		$filters = ExportFilters::from_request(
			array(
				'languages'      => array_filter( explode( ',', (string) ( $assoc_args['lang'] ?? '' ) ) ),
				'post_types'     => array_filter( explode( ',', (string) ( $assoc_args['post-types'] ?? '' ) ) ),
				'taxonomies'     => array_filter( explode( ',', (string) ( $assoc_args['taxonomies'] ?? '' ) ) ),
				'approved_only'  => isset( $assoc_args['approved-only'] ),
				'published_only' => isset( $assoc_args['published-only'] ),
				'changed_since'  => $assoc_args['changed-since'] ?? null,
			)
		);

		$result = $this->exporter->export( $filters );
		if ( ! $result->is_ok() ) {
			WP_CLI::error( $result->message() );
		}

		$json = ( new JsonPackageSerializer() )->serialize( $result->package() );
		$file = (string) ( $assoc_args['file'] ?? '' );

		if ( '' !== $file ) {
			file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::success( sprintf( 'Wrote %s (%d bytes).', $file, strlen( $json ) ) );

			return;
		}

		WP_CLI::print_value( $json );
	}

	/**
	 * Dry-runs or applies a package.
	 *
	 * @param array<int, string>    $args       Positional: the package file path.
	 * @param array<string, string> $assoc_args Flags: --dry-run | --apply, --mode.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = (string) ( $args[0] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			WP_CLI::error( 'Pass a readable package file path.' );
		}

		$raw       = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$validated = $this->importer->validate( $raw );
		if ( ! $validated['ok'] ) {
			WP_CLI::error( $validated['code'] . ': ' . implode( ' ', $validated['messages'] ) );
		}

		$prepared = $this->importer->plan( $validated['package'] );
		$plan     = $prepared['plan'];

		WP_CLI::log( 'Dry-run:' );
		foreach ( $plan->counts() as $category => $count ) {
			WP_CLI::log( sprintf( '  %-26s %d', $category, $count ) );
		}

		if ( ! isset( $assoc_args['apply'] ) ) {
			return;
		}

		$result = $this->importer->apply(
			$raw,
			$prepared['token'],
			$plan->to_array(),
			ApplyMode::normalize( (string) ( $assoc_args['mode'] ?? ApplyMode::SAFE_ONLY ) )
		);

		if ( $result->is_refused() ) {
			WP_CLI::error( $result->refused_code . ': ' . $result->refused_message );
		}

		WP_CLI::success(
			sprintf(
				'Applied %d, skipped %d, changed since dry-run %d.',
				$result->applied_count(),
				array_sum( $result->skipped_by_category ),
				count( $result->changed_since_dry_run )
			)
		);
	}

	/**
	 * Lists recent promotions.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags: --limit.
	 */
	public function history( array $args, array $assoc_args ): void {
		unset( $args );

		$rows = array();
		foreach ( $this->log->recent( (int) ( $assoc_args['limit'] ?? 20 ) ) as $row ) {
			$rows[] = array(
				'when'      => (string) $row->created_at,
				'direction' => (string) $row->direction,
				'mode'      => (string) $row->mode,
				'result'    => (string) $row->result,
				'package'   => substr( (string) $row->package_id, 0, 12 ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'when', 'direction', 'mode', 'result', 'package' ) );
	}

	/**
	 * Mints identity UUIDs for existing translatable objects (idempotent).
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags (unused).
	 */
	public function backfill_identity( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$minted = 0;

		foreach ( PromotionScope::POST_SUBTYPES as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'numberposts'      => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			foreach ( (array) $ids as $id ) {
				$post = get_post( (int) $id );
				if ( null === $post ) {
					continue;
				}
				$ancestors = array();
				if ( is_post_type_hierarchical( $post_type ) ) {
					foreach ( array_reverse( get_post_ancestors( (int) $id ) ) as $anc ) {
						$ancestors[] = (string) get_post_field( 'post_name', (int) $anc );
					}
				}
				$key    = ObjectNaturalKey::for_post( $post_type, (string) $post->post_name, $ancestors, is_post_type_hierarchical( $post_type ) );
				$parts  = ObjectNaturalKey::parts( 'post', $post_type, (string) $post->post_name, $ancestors );
				$before = $this->identities->count();
				$this->identities->ensure( 'post', (int) $id, $post_type, $key, (string) wp_json_encode( $parts ) );
				$minted += $this->identities->count() - $before;
			}
		}

		WP_CLI::success( sprintf( 'Backfilled identity for %d new object(s).', $minted ) );
	}
}
