<?php
/**
 * Structural invariants.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Plugin;

/**
 * Asserts the architecture, not the behaviour.
 *
 * These read the source and the registered hook table to pin down boundaries
 * that are easy to erode one convenient call at a time: the plugin never writes
 * to canonical content, direct SQL stays in the two places allowed to have it,
 * and Milestone 1 does not quietly grow a REST API or a cookie. A behavioural
 * test would only catch the consequence; these catch the cause.
 */
final class PluginGuardTest extends AimlTestCase {

	/**
	 * Absolute path to the plugin root.
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file under src/, keyed by repo-relative path.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$files    = array();
		$root     = $this->root();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = str_replace( $root . '/', '', $file->getPathname() );

			$files[ $path ] = (string) file_get_contents( $file->getPathname() );
		}

		$this->assertNotEmpty( $files, 'Expected to find source files to inspect.' );

		return $files;
	}

	/**
	 * Asserts no source file contains any of the given fragments.
	 *
	 * @param string[] $needles Forbidden fragments.
	 * @param string   $why     Why they are forbidden.
	 * @param string[] $allowed Repo-relative paths exempted.
	 */
	private function assert_absent( array $needles, string $why, array $allowed = array() ): void {
		foreach ( $this->sources() as $path => $code ) {
			if ( in_array( $path, $allowed, true ) ) {
				continue;
			}

			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$code,
					sprintf( '%s found "%s". %s', $path, $needle, $why )
				);
			}
		}
	}

	// -- The overlay model --

	public function test_no_canonical_content_is_ever_created_or_written(): void {
		$this->assert_absent(
			array(
				'wp_insert_post(',
				'wp_update_post(',
				'wp_insert_term(',
				'wp_update_term(',
				'wp_delete_post(',
				'wp_insert_attachment(',
			),
			'Translations are overlays; there is exactly one canonical object and the plugin never writes it (I1-I3).',
			array( 'src/Block/BlockIdentityMigration.php' )
		);
	}

	public function test_promotion_treats_the_package_as_untrusted_input(): void {
		foreach ( $this->sources() as $path => $code ) {
			if ( ! str_starts_with( $path, 'src/Promotion/' ) ) {
				continue;
			}

			foreach ( array( 'unserialize(', 'maybe_unserialize(', 'eval(', 'create_function(', 'wp_insert_post(', 'wp_update_post(', 'wp_insert_term(' ) as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$code,
					sprintf( '%s uses "%s". The promotion package is untrusted input and $wpdb / core writes belong in src/Database (ADR-0030).', $path, $needle )
				);
			}
		}
	}

	public function test_no_direct_writes_to_core_content_tables(): void {
		$allowed_reads = array(
			'src/Routing/HierarchyChildRepository.php',
			'src/Routing/WooProductDependencyRepository.php',
		);

		foreach ( $this->sources() as $path => $code ) {
			if ( in_array( $path, $allowed_reads, true ) ) {
				continue;
			}
			foreach ( array( '$wpdb->posts', '$wpdb->postmeta', '$wpdb->terms', '$wpdb->termmeta', '$wpdb->term_taxonomy' ) as $table ) {
				$this->assertStringNotContainsString(
					$table,
					$code,
					"{$path} touches {$table}. Canonical content is read through the WordPress API and never written."
				);
			}
		}
	}

	public function test_no_woocommerce_object_is_mutated(): void {
		$this->assert_absent(
			array( '->set_price(', '->set_stock_quantity(', '->set_sku(', '->set_name(', '->set_description(' ),
			'WooCommerce owns product data; translation is a view-context overlay only (I2).'
		);
	}

	// -- Database boundary --

	public function test_direct_sql_is_confined(): void {
		$allowed = array(
			'src/Database/Schema.php',
			'src/Database/Migrator.php',
			'src/Database/ObjectIdentityRepository.php',
			'src/Database/PromotionStateRepository.php',
			'src/Database/PromotionLogRepository.php',
			'src/Language/Languages.php',
			'src/Translation/Store.php',
			'src/Translation/Memory/TMRepository.php',
			'src/Glossary/GlossaryRepository.php',
			'src/Rollout/Metrics/RolloutMetricsRepository.php',
			'src/Jobs/BackgroundTranslationJobRepository.php',
			'src/Jobs/BackgroundTranslationItemRepository.php',
			'src/Routing/SlugRouteRepository.php',
			'src/Routing/RouteHistoryRepository.php',
			'src/Routing/ReindexFrontierRepository.php',
			'src/Routing/HierarchyChildRepository.php',
			'src/Routing/WooProductDependencyRepository.php',
			'src/Routing/RoutePublicationService.php',
		);

		foreach ( $this->sources() as $path => $code ) {
			if ( in_array( $path, $allowed, true ) ) {
				continue;
			}

			$this->assertStringNotContainsString(
				'$wpdb',
				$code,
				"{$path} uses \$wpdb. Direct SQL belongs in src/Database or a store class (I9)."
			);
		}
	}

	public function test_table_names_are_always_prefixed_at_runtime(): void {
		foreach ( $this->sources() as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression(
				"/'wp_aiml_/",
				$code,
				"{$path} hardcodes a wp_ table prefix; names must come from \$wpdb->prefix (I9)."
			);
		}
	}

	public function test_every_interpolated_query_is_prepared_or_built_from_schema(): void {
		$store = (string) file_get_contents( $this->root() . '/src/Translation/Store.php' );

		// Every read/write of user-supplied values goes through prepare(); the
		// only interpolation is the table name, which comes from Schema.
		$reads = substr_count( $store, '$wpdb->get_results(' )
			+ substr_count( $store, '$wpdb->get_var(' )
			+ substr_count( $store, '$wpdb->get_row(' );
		$this->assertSame(
			$reads,
			substr_count( $store, '$wpdb->prepare(' ) - substr_count( $store, '$wpdb->query( $wpdb->prepare(' ),
			'Every read in Store must be a prepared statement.'
		);
	}

	// -- Milestone 1 scope --

	public function test_no_rest_routes_are_registered(): void {
		$this->assert_absent(
			array( 'register_rest_route', 'WP_REST_Controller', 'rest_api_init' ),
			'REST is confined to the translator workspace and provider admin controllers under aiml/v1.',
			array(
				'src/Rest/WorkspaceController.php',
				'src/Rest/ProviderController.php',
				'src/Rest/GlossaryController.php',
				'src/Rest/TermSlugController.php',
				'src/Jobs/JobsController.php',
				'src/Rest/SiteTranslateController.php',
				'src/Rest/PromotionController.php',
			)
		);
	}

	public function test_no_cookie_is_set(): void {
		$this->assert_absent(
			array( 'setcookie(', 'wp_set_auth_cookie(', '$_COOKIE' ),
			'The URL is the only language authority in Milestone 1; a Set-Cookie header would hurt cacheability for nothing.'
		);
	}

	public function test_no_rewrite_rules_are_registered_or_flushed(): void {
		$this->assert_absent(
			array( 'add_rewrite_rule(', 'add_rewrite_tag(', 'flush_rewrite_rules(' ),
			'Routing strips the prefix before WordPress parses the request, so no rewrite state exists to manage (ADR-0002).'
		);
	}

	// -- Independence and safety --

	public function test_no_coupling_to_another_translation_plugin(): void {
		$this->assert_absent(
			array( 'trp_', 'TranslatePress', 'icl_', 'polylang', 'Polylang' ),
			'This plugin owns its own data and never reads another translation plugin.'
		);
	}

	public function test_no_broad_exception_is_swallowed(): void {
		$tier_b_allowlist = array(
			'src/Extension/ExtensionRegistrar.php',
		);

		foreach ( $this->sources() as $path => $code ) {
			if ( in_array( $path, $tier_b_allowlist, true ) ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(
				'/catch\s*\(\s*\\\\?(Throwable|Exception)\s/',
				$code,
				"{$path} swallows a broad exception; failures must stay visible."
			);
		}
	}

	public function test_object_cache_access_goes_through_the_wrapper(): void {
		foreach ( $this->sources() as $path => $code ) {
			if ( 'src/Cache/Cache.php' === $path ) {
				continue;
			}

			$this->assertStringNotContainsString(
				'wp_cache_',
				$code,
				"{$path} calls the object cache directly. All access goes through Cache so keys always carry the language (I10)."
			);
		}
	}

	public function test_cache_keys_embed_the_language(): void {
		$cache = (string) file_get_contents( $this->root() . '/src/Cache/Cache.php' );

		$this->assertMatchesRegularExpression(
			'/private function key\([^)]*\): string \{.*?\$language_id.*?\}/s',
			$cache,
			'Cache::key() must include the language, or one language will serve another its translations.'
		);
	}

	// -- Uninstall --

	public function test_uninstall_never_removes_data_unconditionally(): void {
		$uninstall = (string) file_get_contents( $this->root() . '/uninstall.php' );

		$this->assertStringContainsString(
			'remove_data_on_uninstall',
			$uninstall,
			'Uninstall must consult the retention setting.'
		);

		$guard = strpos( $uninstall, "empty( \$aiml_settings['remove_data_on_uninstall'] )" );

		$this->assertNotFalse( $guard, 'Expected the early-return retention guard.' );

		foreach ( array( 'DROP TABLE', 'delete_option(', 'remove_cap(' ) as $destructive ) {
			$position = strpos( $uninstall, $destructive );

			$this->assertNotFalse( $position, "Expected uninstall to be able to {$destructive}." );
			$this->assertGreaterThan(
				$guard,
				$position,
				"{$destructive} appears before the retention guard; retention is all-or-nothing (I5)."
			);
		}
	}

	// -- Boot --

	public function test_boot_is_idempotent(): void {
		$before = count( $GLOBALS['wp_filter']['the_title']->callbacks[10] ?? array() );

		Plugin::instance()->init();
		Plugin::instance()->init();

		$after = count( $GLOBALS['wp_filter']['the_title']->callbacks[10] ?? array() );

		$this->assertSame( $before, $after, 'Re-initialising must not register a second set of filters.' );
	}

	public function test_instance_is_shared(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_the_content_overlay_runs_before_core_block_rendering(): void {
		$reflection = new \ReflectionMethod( \AIMultilingual\Translation\Renderer::class, 'register' );
		$source     = (string) file_get_contents( (string) $reflection->getFileName() );

		$this->assertStringContainsString(
			"add_filter( 'the_content', array( \$this, 'filter_content' ), 1 )",
			$source,
			'Core attaches block hooks at 8 and do_blocks at 9; the overlay must run before both.'
		);
	}

	public function test_ti7_publication_surfaces_have_no_force_bypass(): void {
		$this->assert_absent(
			array(
				"'force' => true",
				'skip_checks',
				'bypass_policy',
				'admin_super_override',
			),
			'TI.7 forbids force-publish of hard blockers (AP20).',
			array()
		);

		$policy = (string) file_get_contents( $this->root() . '/src/Translation/Publication/PublicationPolicy.php' );
		$this->assertStringNotContainsString( 'confidence', $policy );
		$this->assertStringNotContainsString( 'quality_score', $policy );

		$service = (string) file_get_contents( $this->root() . '/src/Translation/Publication/PublicationService.php' );
		$this->assertStringContainsString( 'AssessmentAssembler', $service );
	}

	public function test_ti7_gate_seams_use_central_eligibility(): void {
		foreach ( array(
			'src/Translation/Store.php',
			'src/Translation/BlockTranslationLookup.php',
			'src/Elementor/ElementorOverlayResolver.php',
			'src/Integration/IntegrationFrontendBridge.php',
			'src/Integration/WooCommerce/CustomerEmailBridge.php',
		) as $path ) {
			$code = (string) file_get_contents( $this->root() . '/' . $path );
			$this->assertStringContainsString(
				'is_publicly_overlay_eligible',
				$code,
				$path . ' must use the central TI.7 overlay eligibility helper.'
			);
		}
	}

	/**
	 * OTL.0 must not persist composite operator state or invent policy engines.
	 */
	public function test_otl_foundation_boundaries(): void {
		$this->assert_absent(
			array(
				'operator_status',
			),
			'OTL.0 forbids persisted composite operator state.'
		);

		$schema = (string) file_get_contents( $this->root() . '/src/Database/Schema.php' );
		$this->assertStringNotContainsString( 'aiml_operator', $schema );

		$assembler = (string) file_get_contents( $this->root() . '/src/Workspace/Operator/OperatorTranslationAssembler.php' );
		$this->assertStringContainsString( 'AssessmentAssembler', $assembler );
		$this->assertStringContainsString( 'PublicationService', $assembler );
		$this->assertStringNotContainsString( 'structurally_clean => eligible', $assembler );
		$this->assertStringNotContainsString( 'structurally_clean ⇒ eligible', $assembler );

		$resolver = (string) file_get_contents( $this->root() . '/src/Workspace/Operator/AllowedActionsResolver.php' );
		$this->assertStringContainsString( 'NOT mutation authority', $resolver );
		$this->assertStringNotContainsString( '$wpdb', $resolver );

		$controller = (string) file_get_contents( $this->root() . '/src/Rest/WorkspaceController.php' );
		$this->assertStringContainsString( '/operations', $controller );
		$this->assertStringContainsString( 'attention-counts', $controller );
		$this->assertStringNotContainsString( 'register_rest_route', (string) file_get_contents( $this->root() . '/src/Workspace/Operator/OperatorTranslationAssembler.php' ) );

		$attention = (string) file_get_contents( $this->root() . '/src/Workspace/Operator/OperationalAttention.php' );
		$this->assertStringContainsString( 'review_pending', $attention );
		$this->assertStringContainsString( 'reserved for TI.5', $attention );
		$this->assertStringNotContainsString( 'ID_NEEDS_REVIEW', $attention );

		$integration = '';
		$iterator    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/src/Integration' ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$integration .= (string) file_get_contents( $file->getPathname() );
			}
		}
		$this->assertStringNotContainsString( 'AllowedActionsResolver', $integration );
		$this->assertStringNotContainsString( 'OperatorTranslation', $integration );
		$this->assertStringNotContainsString( '/workspace/operations', $integration );

		// OTL.0 shipped at TARGET 7 (historical); schema advances at MSEO.0 only.
	}

	/**
	 * OTL.2 must reuse Workspace save + existing review/publication owners.
	 */
	public function test_otl2_unified_detail_boundaries(): void {
		$service = (string) file_get_contents( $this->root() . '/src/Workspace/WorkspaceService.php' );
		$this->assertStringContainsString( 'expected_translation_hash', $service );
		$this->assertStringContainsString( 'WorkspaceTranslationConflictException', $service );

		$controller = (string) file_get_contents( $this->root() . '/src/Rest/WorkspaceController.php' );
		$this->assertStringContainsString( 'aiml_translation_hash_mismatch', $controller );
		$this->assertStringContainsString( 'aiml_source_hash_mismatch', $controller );

		$inspector = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/components/OperationsInspector.tsx'
		);
		$this->assertStringContainsString( 'Approved does not mean published', $inspector );
		$this->assertStringContainsString( 'onPublish', $inspector );
		$this->assertStringContainsString( 'onUnpublish', $inspector );
		$this->assertStringContainsString( 'onRetranslate', $inspector );
		$this->assertStringContainsString( 'overlay', strtolower( $inspector ) );
		$this->assertStringNotContainsString( 'guaranteed visible', strtolower( $inspector ) );
		$this->assertStringNotContainsString( 'OTLPublicationPolicy', $inspector );

		$honesty = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/utils/detail-dirty.ts'
		);
		$this->assertStringContainsString( 'last saved', $honesty );

		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/OtlSaveService.php' );
		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/OtlReviewPolicy.php' );
		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/OtlPublicationPolicy.php' );

		$translation = (string) file_get_contents( $this->root() . '/src/Workspace/TranslationService.php' );
		$this->assertStringContainsString( 'expected_translation_hash', $translation );
		$this->assertStringContainsString( 'guard_expected_translation_hash', $translation );
		$this->assertStringContainsString( 'aiml_translation_hash_mismatch', $translation );

		$resolver = (string) file_get_contents(
			$this->root() . '/src/Workspace/Operator/AllowedActionsResolver.php'
		);
		$start    = strpos( $resolver, 'function action_retranslate_stale' );
		$this->assertNotFalse( $start );
		$slice = substr( $resolver, (int) $start, 900 );
		$this->assertStringNotContainsString( 'DEFERRED_MILESTONE', $slice );
		$this->assertStringContainsString( 'null )', $slice );

		// OTL.2 shipped at TARGET 7 (historical).

		$integration = '';
		$iterator    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/src/Integration' ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$integration .= (string) file_get_contents( $file->getPathname() );
			}
		}
		$this->assertStringNotContainsString( 'expected_translation_hash', $integration );
		$this->assertStringNotContainsString( 'aiml_translation_hash_mismatch', $integration );
	}

	/**
	 * OTL.4 Jobs integration boundaries — TI.6 ownership, no schema, list stays cheap.
	 */
	public function test_otl4_jobs_integration_boundaries(): void {
		$linker = (string) file_get_contents( $this->root() . '/src/Jobs/JobsLifecycleLinker.php' );
		$this->assertStringContainsString( 'LOOKUP_JOB_SCAN_LIMIT = 32', $linker );
		$this->assertStringContainsString( 'list_recent_by_object', $linker );
		$this->assertStringNotContainsString( 'selection_rule', $linker );

		$admission = (string) file_get_contents( $this->root() . '/src/Jobs/JobsOperationAdmission.php' );
		$this->assertStringContainsString( 'validate_resume', $admission );
		$this->assertStringContainsString( 'mutation_scope', $admission );

		$service = (string) file_get_contents( $this->root() . '/src/Jobs/BackgroundTranslationJobService.php' );
		$this->assertStringContainsString( 'JobsOperationAdmission', $service );
		$this->assertStringContainsString( 'OP_RETRY_FAILED', $service );

		$this->assertStringNotContainsString( "'last_error_message'", $linker );

		$assembler = (string) file_get_contents( $this->root() . '/src/Workspace/Operator/OperatorTranslationAssembler.php' );
		$this->assertStringContainsString( "'jobs'              => null", $assembler );
		$this->assertStringContainsString( 'JobsLifecycleLinker', $assembler );

		$resolver = (string) file_get_contents(
			$this->root() . '/src/Workspace/Operator/AllowedActionsResolver.php'
		);
		$this->assertStringContainsString( 'ACTION_RESUME_JOB', $resolver );
		$this->assertStringContainsString( 'JobsOperationAdmission', $resolver );

		$inspector = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/components/OperationsInspector.tsx'
		);
		$this->assertStringContainsString( 'bounded lookup', strtolower( $inspector ) );
		$this->assertStringNotContainsString( 'no retained Jobs record exists', $inspector );
		$this->assertStringNotContainsString( 'selection_rule', $inspector );
		$this->assertStringContainsString( 'entire', strtolower( $inspector ) );

		$jobs_ts = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/utils/jobs.ts'
		);
		$this->assertStringContainsString( 'operations', $jobs_ts );

		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/OtlJobsPolicy.php' );
		$this->assertFileDoesNotExist( $this->root() . '/src/Jobs/OtlJobsEngine.php' );

		// OTL.4 shipped at TARGET 7 (historical).
	}

	/**
	 * OTL.5 bounded bulk must not invent policies, retry engines, or queues.
	 */
	public function test_otl5_bulk_boundaries(): void {
		$coordinator = (string) file_get_contents(
			$this->root() . '/src/Workspace/Operator/OperationsBulkCoordinator.php'
		);
		$this->assertStringContainsString( 'BATCH_LIMIT = 50', $coordinator );
		$this->assertStringContainsString( 'OUTCOME_ENQUEUED', $coordinator );
		$this->assertStringContainsString( 'PublicationService', $coordinator );
		$this->assertStringContainsString( 'JobTypes::TRANSLATE_SELECTED', $coordinator );
		$this->assertStringNotContainsString( 'retry_failed', strtolower( $coordinator ) );
		$this->assertStringNotContainsString( 'force_publish', strtolower( $coordinator ) );
		$this->assertStringNotContainsString( 'class PublicationPolicy', $coordinator );

		$controller = (string) file_get_contents( $this->root() . '/src/Rest/WorkspaceController.php' );
		$this->assertStringContainsString( '/operations/bulk', $controller );
		$this->assertStringContainsString( 'operations_bulk', $controller );
		$this->assertStringNotContainsString( "'retry_failed'", $controller );

		$panel = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/components/OperationsPanel.tsx'
		);
		$this->assertStringContainsString( 'enqueued', strtolower( $panel ) );
		$this->assertStringContainsString( 'evaluated individually for publication', strtolower( $panel ) );
		$this->assertStringNotContainsString( 'Ready to publish', $panel );
		$this->assertStringNotContainsString( 'Publishable', $panel );
		// Forbid user-facing eligibility labels (A2); allow identifier names like mutationEligible.
		$this->assertDoesNotMatchRegularExpression( "/['\"]Eligible['\"]/", $panel );
		$this->assertDoesNotMatchRegularExpression( '/__\(\s*[\'"]Eligible[\'"]/', $panel );

		$selection = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/utils/operations-selection.ts'
		);
		$this->assertStringContainsString( 'OPERATIONS_SELECTION_LIMIT = 50', $selection );
		$this->assertStringContainsString( 'dirtyBlocksBulk', $selection );
		$this->assertStringContainsString( 'applyBulkResultToSelection', $selection );

		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/OtlPublicationPolicy.php' );
		$this->assertFileDoesNotExist( $this->root() . '/src/Workspace/BulkRetryEngine.php' );

		$integration = '';
		$iterator    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root() . '/src/Integration' ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$integration .= (string) file_get_contents( $file->getPathname() );
			}
		}
		$this->assertStringNotContainsString( 'operations/bulk', $integration );
		$this->assertStringNotContainsString( 'OperationsBulkCoordinator', $integration );

		// OTL.5 shipped at TARGET 7 (historical).
	}

	/**
	 * Product PHP under src/ must stay site-neutral (public/SaaS).
	 */
	public function test_otl_product_code_has_no_site_specific_branding(): void {
		$forbidden = array( 'Biopentra', 'biopentra.eu', 'biopentra', 'peptide' );
		foreach ( $this->sources() as $path => $code ) {
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$code,
					$path . ' must not embed site-specific product behavior (' . $needle . ').'
				);
			}
		}

		$workspace_root = $this->root() . '/assets/translator-workspace/src';
		$this->assertDirectoryExists( $workspace_root );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $workspace_root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = $file->getExtension();
			if ( 'ts' !== $ext && 'tsx' !== $ext ) {
				continue;
			}
			$path = str_replace( $this->root() . '/', '', $file->getPathname() );
			$code = (string) file_get_contents( $file->getPathname() );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$code,
					$path . ' must not embed site-specific product behavior (' . $needle . ').'
				);
			}
		}
	}

	/**
	 * OTL.6 architecture forbids — ConfirmDialog, session memory, no Jobs→Ops enrichment.
	 */
	public function test_otl6_operator_lifecycle_boundaries(): void {
		$confirm = $this->root() . '/assets/translator-workspace/src/components/ConfirmDialog.tsx';
		$this->assertFileExists( $confirm );

		$panel = (string) file_get_contents(
			$this->root() . '/assets/translator-workspace/src/components/OperationsPanel.tsx'
		);
		$this->assertStringNotContainsString(
			'window.confirm',
			$panel,
			'Operations consequential confirms must use ConfirmDialog, not window.confirm.'
		);

		$session_path = $this->root() . '/assets/translator-workspace/src/utils/operations-session.ts';
		$this->assertFileExists( $session_path );
		$session = (string) file_get_contents( $session_path );
		$this->assertDoesNotMatchRegularExpression(
			'/\blocalStorage\s*\./',
			$session,
			'operations-session must not call localStorage APIs.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\bsessionStorage\s*\./',
			$session,
			'operations-session must not call sessionStorage APIs.'
		);

		// Jobs→Ops enrichment remains Deferred (A3 / OP15): serializers must not emit translation_id.
		foreach ( array(
			'src/Jobs/JobsViewModel.php',
			'src/Jobs/JobItemViewModel.php',
			'src/Jobs/JobsViewModelSerializer.php',
		) as $rel ) {
			$code = (string) file_get_contents( $this->root() . '/' . $rel );
			$this->assertStringNotContainsString(
				'translation_id',
				$code,
				$rel . ' must not enrich Jobs payloads with translation_id for Ops reverse nav.'
			);
		}

		// OTL.6 shipped at TARGET 7 (historical).
	}

	/**
	 * TSC.0 structural neutrality: Fluent hardcoded host/form IDs and public CPT filter.
	 *
	 * Scoped to Fluent Forms production sources for FORM_ID / CONTACT_PAGE_ID / 3410
	 * remnants — not a generic suspicious-integer ban across the tree.
	 */
	public function test_tsc0_fluent_neutrality_and_no_public_cpt_admission_filter(): void {
		$fluent_root = $this->root() . '/src/Integration/FluentForms';
		$this->assertDirectoryExists( $fluent_root );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $fluent_root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = str_replace( $this->root() . '/', '', $file->getPathname() );
			$code = (string) file_get_contents( $file->getPathname() );

			$this->assertDoesNotMatchRegularExpression(
				'/\bconst\s+FORM_ID\b/',
				$code,
				$path . ' must not reintroduce a behavioral FORM_ID constant.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\bFORM_ID\s*=\s*5\b/',
				$code,
				$path . ' must not hardcode FORM_ID = 5.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\bconst\s+CONTACT_PAGE_ID\b/',
				$code,
				$path . ' must not reintroduce CONTACT_PAGE_ID.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\bCONTACT_PAGE_ID\s*=\s*3410\b/',
				$code,
				$path . ' must not hardcode CONTACT_PAGE_ID = 3410.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\b3410\b/',
				$code,
				$path . ' must not embed contact-page id 3410 behavioral remnants.'
			);
		}

		$this->assert_absent(
			array( 'aiml_admitted_post_types' ),
			'TSC.0 forbids a public CPT admission filter (aiml_admitted_post_types).'
		);
	}

	/**
	 * TSC.1 term-identity structural invariants (AC36, AC40, AC52, AC54, AC58).
	 *
	 * Sole alias resolver; no get_term mutation; overlay seam scope; neutrality;
	 * TARGET stays 7; no opportunistic TSC.2+ Store source types; resolver read-only.
	 */
	public function test_tsc1_term_identity_invariants(): void {
		// AC1 / AC54 — SOURCE_TERM introduced at TSC.1 (STATE A; shipped at TARGET 7).
		$store = (string) file_get_contents( $this->root() . '/src/Translation/Store.php' );
		$this->assertMatchesRegularExpression(
			"/const\s+SOURCE_TERM\s*=\s*'term'\s*;/",
			$store,
			'Store::SOURCE_TERM must exist and equal term (AC1).'
		);

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringContainsString( 'step_8_mseo_localized_url_foundation', $migrator );
		$this->assertSame( 10, Migrator::TARGET );

		// AC36 — sole hosted-key builder; no duplicate alias implementation.
		$alias_builders = array();
		foreach ( $this->sources() as $path => $code ) {
			if ( preg_match( '/\bfunction\s+hosted_segment_key\s*\(/', $code ) ) {
				$alias_builders[] = $path;
			}
		}
		$this->assertSame(
			array( 'src/Translation/TermTranslationResolver.php' ),
			$alias_builders,
			'Only TermTranslationResolver may define hosted_segment_key (AC36 / TT16).'
		);

		// Literal hosted catalog-key construction must not fork outside the resolver.
		// Allowlist: DTO/adoption consumers that carry resolver-built addresses, extractors,
		// and Rank Math which retains p:rankmath:term:* keys (not product_cat hosted keys).
		$hosted_key_allowlist = array(
			'src/Translation/TermTranslationResolver.php',
			'src/Translation/TermCompatRef.php',
			'src/Translation/TermAdoptionService.php',
			'src/Translation/TermExtractor.php',
			'src/Integration/RankMath/RankMathIntegration.php',
			'src/Integration/RankMath/RankMathSitemapOverlay.php',
		);
		foreach ( $this->sources() as $path => $code ) {
			if ( in_array( $path, $hosted_key_allowlist, true ) ) {
				continue;
			}
			$builds_hosted_catalog_key = false !== strpos( $code, 'p:woocommerce:product_cat:' )
				|| (
					false !== strpos( $code, 'product_cat:' )
					&& (
						false !== strpos( $code, '->build(' )
						|| false !== strpos( $code, 'hosted_segment_key' )
						|| preg_match( '/SEGMENT_KEY_PREFIX/', $code )
					)
				);
			$this->assertFalse(
				$builds_hosted_catalog_key,
				$path . ' must not construct hosted product_cat segment keys; use TermTranslationResolver (AC36).'
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\bfinal\s+class\s+\w*TermAlias\w*/',
			implode( "\n", $this->sources() ),
			'No second TermAlias* class may implement term address resolution (AC36).'
		);

		// AC40 — no get_term mutation hooks anywhere in src/.
		foreach ( $this->sources() as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression(
				'/add_(?:filter|action)\s*\(\s*[\'"]get_term[\'"]/',
				$code,
				$path . ' must not register get_term mutation hooks (AC40 / TT31).'
			);
		}

		// Overlay seam scope: deferred archive title + attribute labels out of TSC.1
		// (AC37–AC39 seams only; TT33 / TSC.3). Checked on the TSC.1 visitor surfaces only —
		// WooCommerceIntegration may still host attribute-label chrome until TSC.3.
		foreach ( array(
			'src/Integration/TermVisitorOverlay.php',
			'src/Integration/IntegrationFrontendBridge.php',
		) as $rel ) {
			$code = (string) file_get_contents( $this->root() . '/' . $rel );
			$this->assertStringNotContainsString(
				'get_the_archive_title',
				$code,
				$rel . ' must not hook get_the_archive_title (Deferred).'
			);
			$this->assertStringNotContainsString(
				'woocommerce_attribute_label',
				$code,
				$rel . ' must not hook woocommerce_attribute_label (TSC.3 / TT33).'
			);
		}

		// AC52 — neutrality + no public taxonomy admission API.
		$this->assert_absent(
			array( 'aiml_admitted_taxonomies' ),
			'TSC.1 forbids a public taxonomy admission filter (aiml_admitted_taxonomies).'
		);

		$forbidden_site = array( 'Biopentra', 'biopentra.eu', 'biopentra', 'peptide' );
		$surface_root   = $this->root() . '/src/Surface';
		$surface_files  = array( 'src/Surface/AdmittedTaxonomies.php' );
		$iterator       = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $surface_root ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$surface_files[] = str_replace( $this->root() . '/', '', $file->getPathname() );
			}
		}
		$surface_files = array_values( array_unique( $surface_files ) );
		foreach ( $surface_files as $rel ) {
			$code = (string) file_get_contents( $this->root() . '/' . $rel );
			foreach ( $forbidden_site as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$code,
					$rel . ' must not embed site-specific taxonomy/product literals (' . $needle . ') (AC52).'
				);
			}
			$this->assertStringNotContainsString(
				'apply_filters',
				$code,
				$rel . ' must remain code-owned; no public Surface/taxonomy admission filter (AC52).'
			);
		}

		$admitted = (string) file_get_contents( $this->root() . '/src/Surface/AdmittedTaxonomies.php' );
		$this->assertStringContainsString( 'CORE_TAXONOMIES', $admitted );
		$this->assertStringContainsString( 'CATALOG_TAXONOMIES', $admitted );
		$this->assertStringNotContainsString( 'get_taxonomies(', $admitted );

		// AC58 — no opportunistic TSC.2+ Store source_type constants.
		foreach ( $this->sources() as $path => $code ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\bconst\s+SOURCE_(?:MENU|WIDGET|BLOCK|NAV|SHORTCODE|ELEMENTOR)\b/',
				$code,
				$path . ' must not register unfrozen TSC.2+ Store source types (AC58).'
			);
		}
		$this->assertDoesNotMatchRegularExpression(
			'/\bconst\s+SOURCE_(?:MENU|WIDGET)\b/',
			$store,
			'Store must not define SOURCE_MENU / SOURCE_WIDGET until those milestones freeze (AC58).'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\bconst\s+SOURCE_META\b/',
			$store,
			'Store must not define SOURCE_META (TSC.2 freezes meta as post/term segments).'
		);

		// TSC.2 — no public meta registration API / generic filter overlay engine.
		foreach ( $this->sources() as $path => $code ) {
			$this->assertStringNotContainsString(
				'register_translatable_meta',
				$code,
				$path . ' must not expose public register_translatable_meta (TSC.6).'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/add_filter\s*\(\s*[\'"]get_post_meta[\'"]/',
				$code,
				$path . ' must not globally intercept get_post_meta for translation.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/add_filter\s*\(\s*[\'"]get_term_meta[\'"]/',
				$code,
				$path . ' must not globally intercept get_term_meta for translation.'
			);
		}

		$rank_defs = (string) file_get_contents( $this->root() . '/src/Surface/Meta/RankMathMetaDefinitions.php' );
		$this->assertStringContainsString( 'SEO_META_KEYS', $rank_defs );
		$this->assertStringContainsString( 'seo_meta_keys', $rank_defs );
		$post_adapter = (string) file_get_contents( $this->root() . '/src/Surface/PostSurfaceAdapter.php' );
		$this->assertStringContainsString( 'RankMathMetaDefinitions', $post_adapter );
		$this->assertStringContainsString( 'meta_registry', $post_adapter );
		$registry = (string) file_get_contents( $this->root() . '/src/Surface/Meta/RegisteredMetaRegistry.php' );
		$this->assertStringContainsString( 'FORBIDDEN_ECONOMIC_META_KEYS', $registry );
		$this->assertStringContainsString( '_price', $registry );
		$this->assertStringContainsString( '_sku', $registry );
		foreach ( array( '_price', '_stock', '_sku' ) as $economic ) {
			$this->assertStringNotContainsString(
				"meta_key: '" . $economic . "'",
				$rank_defs,
				'Rank Math catalog must not register Woo economic keys.'
			);
		}

		// AC35 / TT17 — TermTranslationResolver is read-only (no public write/lock/adopt).
		$reflection = new \ReflectionClass( \AIMultilingual\Translation\TermTranslationResolver::class );
		foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}
			$name = $method->getName();
			$this->assertDoesNotMatchRegularExpression(
				'/(?:write|lock|adopt|save|mutate|insert|update|delete)/i',
				$name,
				'TermTranslationResolver::' . $name . ' must not be a public write/lock/adopt API (AC35).'
			);
		}

		$this->assert_tsc3_invariants();
		$this->assert_tsc4_invariants();
		$this->assert_tsc5_invariants();
		$this->assert_tsc6_invariants();
	}

	/**
	 * TSC.6 public extension boundary architecture guards.
	 */
	private function assert_tsc6_invariants(): void {
		$this->assertFileExists( $this->root() . '/src/Extension/ExtensionRegistrar.php' );
		$this->assertFileExists( $this->root() . '/src/Extension/Block/ExtensionBlockAdapter.php' );
		$this->assertFileExists( $this->root() . '/src/Extension/VisitorTranslationResolver.php' );
		$this->assertFileExists( $this->root() . '/src/Extension/functions.php' );
		$this->assertFileExists( $this->root() . '/docs/adr/0022-public-extension-boundary-and-registration-lifecycle.md' );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'do_action( \'aiml_register_extensions\'', $plugin );
		$this->assertStringContainsString( '$extension_registrar->seal()', $plugin );
		$this->assertStringContainsString( 'ExtensionCli::register', $plugin );

		$registrar = (string) file_get_contents( $this->root() . '/src/Extension/ExtensionRegistrar.php' );
		$this->assertStringContainsString( 'BlockRegistry::SUPPORTED_BLOCKS', $registrar );
		$this->assertStringContainsString( 'RESERVED_NAMESPACES', $registrar );

		$resolver = (string) file_get_contents( $this->root() . '/src/Extension/VisitorTranslationResolver.php' );
		$this->assertStringContainsString( 'SourceSegmentReference', $resolver );
		$this->assertStringContainsString( 'LanguageReference', $resolver );
		$this->assertStringContainsString( 'is_publicly_overlay_eligible', $resolver );

		// TSC.6 shipped at TARGET 7 (historical); MSEO.0 advances to TARGET 8.

		foreach ( $this->sources() as $path => $code ) {
			$this->assertStringNotContainsString(
				'register_elementor_widget',
				$code,
				'TSC.6 forbids public Elementor registration API: ' . $path
			);
			$this->assertStringNotContainsString(
				'aiml_admitted_post_types',
				$code,
				'TSC.6 forbids public CPT admission filter: ' . $path
			);
			$this->assertStringNotContainsString(
				'aiml_admitted_taxonomies',
				$code,
				'TSC.6 forbids public taxonomy admission filter: ' . $path
			);
		}
	}

	/**
	 * TSC.5 Elementor coverage expansion architecture guards.
	 */
	private function assert_tsc5_invariants(): void {
		$adapter = (string) file_get_contents( $this->root() . '/src/Surface/PostSurfaceAdapter.php' );
		$this->assertStringContainsString( 'elementor/document/after_save', $adapter );
		$this->assertStringNotContainsString( 'elementor/document/before_save', $adapter );
		$this->assertStringNotContainsString( '_elementor_data', $adapter );

		$this->assertFileExists( $this->root() . '/src/Translation/Safety/StructuralAttributeGuard.php' );

		$block_guard = (string) file_get_contents( $this->root() . '/src/Block/BlockStructuralAttributeGuard.php' );
		$this->assertStringContainsString( 'StructuralAttributeGuard', $block_guard );

		$bridge = (string) file_get_contents( $this->root() . '/src/Elementor/ElementorFrontendBridge.php' );
		$this->assertStringContainsString( 'ElementorRenderContextGate', $bridge );
		$this->assertStringNotContainsString( 'wp_update_post', $bridge );

		$registry = (string) file_get_contents( $this->root() . '/src/Elementor/ElementorControlRegistry.php' );
		foreach ( array( 'heading', 'text-editor', 'button', 'accordion', 'toggle', 'image', 'icon-list', 'call-to-action' ) as $widget ) {
			$this->assertStringContainsString( "'" . $widget . "'", $registry );
		}
		$this->assertStringNotContainsString( "'testimonial'", $registry );
		$this->assertStringNotContainsString( "'icon-box'", $registry );

		$settings = (string) file_get_contents( $this->root() . '/src/Settings.php' );
		$this->assertMatchesRegularExpression( "/'elementor_extraction_enabled'\\s*=>\\s*false/", $settings );
		$this->assertMatchesRegularExpression( "/'elementor_frontend_rendering_enabled'\\s*=>\\s*false/", $settings );

		$store = (string) file_get_contents( $this->root() . '/src/Translation/Store.php' );
		$this->assertStringNotContainsString( 'SOURCE_ELEMENTOR', $store );

		// TSC.5 shipped at TARGET 7 (historical).

		foreach ( $this->sources() as $path => $code ) {
			if ( ! str_starts_with( $path, 'src/Elementor/' ) && ! str_starts_with( $path, 'src/Translation/' ) ) {
				continue;
			}
			$this->assertStringNotContainsString(
				'register_elementor_widget',
				$code,
				'TSC.6 public registration must not leak into TSC.5: ' . $path
			);
		}
	}

	/**
	 * TSC.4 Gutenberg coverage expansion architecture guards.
	 */
	private function assert_tsc4_invariants(): void {
		$lookup = (string) file_get_contents( $this->root() . '/src/Translation/BlockTranslationLookup.php' );
		$this->assertStringContainsString( 'Contract::is_supported_field', $lookup );
		$this->assertStringNotContainsString( 'Contract::FIELD_CONTENT !== $parsed', $lookup );

		$renderer = (string) file_get_contents( $this->root() . '/src/Translation/BlockRenderer.php' );
		$this->assertStringContainsString( 'BlockStructuralAttributeGuard', $renderer );
		$this->assertStringContainsString( 'EVENT_STRUCTURAL_REJECTED', $renderer );

		$this->assertFileExists( $this->root() . '/src/Block/BlockStructuralAttributeGuard.php' );

		$settings = (string) file_get_contents( $this->root() . '/src/Settings.php' );
		$this->assertStringContainsString( "'block_attr_registration_enabled'", $settings );
		$this->assertStringContainsString( "'block_uuid_injection_enabled'", $settings );
		$this->assertStringContainsString( "'block_extraction_enabled'", $settings );
		$this->assertStringContainsString( "'block_frontend_rendering_enabled'", $settings );
		$this->assertMatchesRegularExpression( "/'block_attr_registration_enabled'\\s*=>\\s*false/", $settings );
		$this->assertMatchesRegularExpression( "/'block_uuid_injection_enabled'\\s*=>\\s*false/", $settings );
		$this->assertMatchesRegularExpression( "/'block_extraction_enabled'\\s*=>\\s*false/", $settings );
		$this->assertMatchesRegularExpression( "/'block_frontend_rendering_enabled'\\s*=>\\s*false/", $settings );

		$registry = (string) file_get_contents( $this->root() . '/src/Block/BlockRegistry.php' );
		$this->assertStringNotContainsString( "'core/html'", $registry );
		$this->assertStringNotContainsString( "'core/shortcode'", $registry );
		$this->assertStringNotContainsString( "'core/embed'", $registry );

		// TSC.4 shipped at TARGET 7 (historical).

		foreach ( $this->sources() as $path => $code ) {
			if ( str_contains( $path, 'Cli.php' ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression(
				"/add_filter\\(\\s*['\"]render_block['\"]/",
				$code,
				$path . ' must not register render_block.'
			);
			$this->assertDoesNotMatchRegularExpression(
				"/add_filter\\(\\s*['\"]pre_render_block['\"]/",
				$code,
				$path . ' must not register pre_render_block.'
			);
		}

		$render_files = array(
			'src/Translation/BlockFrontendRenderer.php',
			'src/Translation/BlockRenderer.php',
			'src/Translation/BlockTranslationLookup.php',
			'src/Translation/BlockTranslationSanitizer.php',
		);
		foreach ( $render_files as $path ) {
			$code = (string) file_get_contents( $this->root() . '/' . $path );
			$this->assertStringNotContainsString( 'wp_update_post', $code, $path . ' must not write canonical content.' );
		}

		$sources = $this->sources();
		foreach ( $sources as $path => $code ) {
			if ( ! str_starts_with( $path, 'src/Integration/Elementor/' ) ) {
				continue;
			}
			$this->assertStringNotContainsString(
				'register_elementor',
				$code,
				'TSC.4 must not implement Elementor (TSC.5).'
			);
		}
	}

	/**
	 * TSC.3 WooCommerce extended surfaces architecture guards.
	 */
	private function assert_tsc3_invariants(): void {
		$woo = (string) file_get_contents( $this->root() . '/src/Integration/WooCommerce/WooCommerceIntegration.php' );
		$this->assertStringContainsString( 'extract_global_attribute_label_units', $woo );
		$this->assertStringContainsString( 'is_taxonomy', $woo );
		$this->assertStringContainsString( 'AttributeLabelIdentity', $woo );

		$identity = (string) file_get_contents( $this->root() . '/src/Integration/WooCommerce/AttributeLabelIdentity.php' );
		$this->assertStringContainsString( "OWNER_ATTRIBUTE = 'attribute'", $identity );
		$this->assertStringContainsString( 'rehost_predicate', $identity );

		$store = (string) file_get_contents( $this->root() . '/src/Translation/Store.php' );
		$this->assertStringContainsString( 'function rehost_segments', $store );

		$auth = (string) file_get_contents( $this->root() . '/src/Integration/WooCommerce/WooAttributeLabelAuthority.php' );
		$this->assertStringContainsString( 'manage_product_terms', $auth );

		$inv = (string) file_get_contents( $this->root() . '/src/Integration/WooCommerce/WooCommerceInvalidation.php' );
		$this->assertStringContainsString( 'woocommerce_shop_page_id', $inv );
		$this->assertStringContainsString( 'woocommerce_attribute_updated', $inv );
		$this->assertStringContainsString( '_settings', $inv );

		$pub = (string) file_get_contents( $this->root() . '/src/Translation/Publication/PublicationService.php' );
		$this->assertStringContainsString( 'is_row_source_public', $pub );

		$otl = (string) file_get_contents( $this->root() . '/src/Workspace/Operator/AllowedActionsResolver.php' );
		$this->assertStringContainsString( 'denies_row_write', $otl );
		$this->assertStringContainsString( 'set_segment_authority_registry', $otl );

		// TSC.3 shipped at TARGET 7 (historical).

		// No public registration API leakage / no SOURCE_META.
		$this->assertStringNotContainsString( 'SOURCE_META', $identity );
		$this->assertStringNotContainsString( 'register_translatable_attribute', $woo );
	}

	/**
	 * MSEO.2 public routing structural guards.
	 */
	public function test_mseo2_public_routing_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );
		$this->assertFileExists( $this->root() . '/src/Routing/RouteRecognitionContext.php' );

		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'RouteRecognitionContext', $router );
		$this->assertStringContainsString( 'KIND_CURRENT_LOCALIZED', $router );
		$this->assertStringContainsString( 'KIND_SOURCE_PATH', $router );
		$this->assertStringContainsString( 'find_active_by_localized_path', $router );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'EffectiveUrlService', $plugin );
		$this->assertStringContainsString( 'new Router(', $plugin );

		$eligibility = (string) file_get_contents( $this->root() . '/src/Routing/ObjectLanguagePublicEligibility.php' );
		$this->assertStringContainsString( 'is_discoverable', $eligibility );
		$this->assertStringContainsString( 'is_localized_url_generation_enabled', $eligibility );

		$this->assertTrue( class_exists( 'AIMultilingual\\Jobs\\SlugRouteActivationJob' ) );
		$this->assertFileExists( $this->root() . '/src/Jobs/SlugRouteActivationJob.php' );

		$activation_job      = (string) file_get_contents( $this->root() . '/src/Jobs/SlugRouteActivationJob.php' );
		$activation_verifier = (string) file_get_contents( $this->root() . '/src/Routing/SlugRouteActivationVerifier.php' );
		foreach ( array( $activation_job, $activation_verifier ) as $source ) {
			$this->assertStringNotContainsString( 'RoutePublicationService', $source );
			$this->assertStringNotContainsString( 'publish_route', $source );
			$this->assertStringNotContainsString( 'SlugCandidateService', $source );
		}

		$settings_page = (string) file_get_contents( $this->root() . '/src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( 'render_localized_urls_settings', $settings_page );
		$this->assertStringContainsString( 'Localized URLs', $settings_page );
	}

	/**
	 * MSEO.0 inert foundation structural guards.
	 */
	public function test_mseo0_inert_foundation_boundaries(): void {
		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertSame( 10, Migrator::TARGET );
		$this->assertStringContainsString( 'step_8_mseo_localized_url_foundation', $migrator );

		$this->assertFileExists( $this->root() . '/src/Routing/PathHash.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/PathCanonicalizer.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/EffectiveUrlService.php' );

		$path_hash = (string) file_get_contents( $this->root() . '/src/Routing/PathHash.php' );
		$this->assertStringContainsString( "hash( 'sha256'", $path_hash );

		$canonicalizer = (string) file_get_contents( $this->root() . '/src/Routing/PathCanonicalizer.php' );
		$this->assertStringNotContainsString( 'sanitize_title', $canonicalizer );

		$settings_page = (string) file_get_contents( $this->root() . '/src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( 'render_localized_urls_settings', $settings_page );
	}

	/**
	 * MSEO.1 lifecycle boundaries — TARGET 8, prepared routes, no public routing.
	 */
	public function test_mseo1_lifecycle_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		foreach ( array(
			\AIMultilingual\Routing\SlugCandidateService::class,
			\AIMultilingual\Routing\RoutePublicationService::class,
			\AIMultilingual\Routing\CanonicalPathCollisionChecker::class,
			\AIMultilingual\Routing\ObjectLanguagePublicEligibility::class,
			\AIMultilingual\Routing\RoutingCapabilityRegistry::class,
		) as $class ) {
			$this->assertTrue( class_exists( $class ), $class . ' must exist for MSEO.1' );
		}

		$publication = (string) file_get_contents( $this->root() . '/src/Translation/Publication/PublicationService.php' );
		$this->assertTrue(
			false !== strpos( $publication, 'aiml_slug_publish_requires_route' )
			|| false !== strpos( $publication, 'reject_format_slug' ),
			'PublicationService must fail-closed for standalone FORMAT_SLUG publish.'
		);
		$this->assertStringContainsString( 'publish_under_route_authority', $publication );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'EffectiveUrlService', $plugin );
		$this->assertStringContainsString( 'RouteRecognitionContext', (string) file_get_contents( $this->root() . '/src/Routing/Router.php' ) );
		$this->assertStringContainsString( 'refresh_source_path', $plugin );
		$this->assertStringContainsString( 'deactivate_for_source', $plugin );
		$this->assertStringContainsString( 'purge_for_source', $plugin );

		$rest = (string) file_get_contents( $this->root() . '/src/Rest/WorkspaceController.php' );
		$this->assertStringContainsString( 'slug/publish-route', $rest );
		$this->assertStringContainsString( 'clear_slug_candidate', $rest );

		$this->assertTrue( class_exists( 'AIMultilingual\\Jobs\\SlugRouteActivationJob' ) );
		$this->assertFileExists( $this->root() . '/src/Jobs/SlugRouteActivationJob.php' );

		$settings_page = (string) file_get_contents( $this->root() . '/src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( 'render_localized_urls_settings', $settings_page );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );

		$route_pub = (string) file_get_contents( $this->root() . '/src/Routing/RoutePublicationService.php' );
		$this->assertStringContainsString( 'publish_under_route_authority', $route_pub );
		$this->assertStringContainsString( 'collision_adjusted', $route_pub );
	}

	/**
	 * MSEO.3 hierarchy/term routing structural guards.
	 */
	public function test_mseo3_hierarchy_term_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		$this->assertFileExists( $this->root() . '/src/Routing/RoutingCapabilityAdmission.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/HierarchyPathBuilder.php' );
		$this->assertFileExists( $this->root() . '/src/Jobs/CapabilityVerificationJob.php' );
		$this->assertFileExists( $this->root() . '/src/Jobs/HierarchyReindexJob.php' );

		$admission = (string) file_get_contents( $this->root() . '/src/Routing/RoutingCapabilityAdmission.php' );
		$this->assertStringContainsString( 'CODE_CAPABILITY_EPOCH', $admission );
		$this->assertStringContainsString( 'commit_admission', $admission );
		$this->assertStringContainsString( 'is_publicly_admitted', $admission );

		$hierarchy = (string) file_get_contents( $this->root() . '/src/Routing/HierarchyPathBuilder.php' );
		$this->assertStringContainsString( 'source_path_for_term', $hierarchy );
		$this->assertStringContainsString( 'localized_path_for_post', $hierarchy );
		$this->assertStringNotContainsString( 'add_rewrite_rule', $hierarchy );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'RoutingCapabilityAdmission', $plugin );
		$this->assertStringContainsString( 'HierarchyPathBuilder', $plugin );
		$this->assertStringContainsString( 'CapabilityVerificationJob', $plugin );
		$this->assertStringContainsString( 'HierarchyReindexJob', $plugin );
		$this->assertStringContainsString( 'aiml_hierarchy_reindex_root', $plugin );

		$cli = (string) file_get_contents( $this->root() . '/src/Cli.php' );
		$this->assertStringContainsString( 'localized-urls capabilities', $cli );
		$this->assertStringContainsString( 'localized-urls reindex-status', $cli );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );
		$this->assertSame( 10, Migrator::TARGET );

		$cap_job = (string) file_get_contents( $this->root() . '/src/Jobs/CapabilityVerificationJob.php' );
		$this->assertStringContainsString( 'commit_admission', $cap_job );
		$this->assertStringNotContainsString( "localized_urls_state' => 'off'", $cap_job );
		$this->assertStringNotContainsString( 'fail_activation', $cap_job );
		$this->assertStringNotContainsString( 'request_disable', $cap_job );

		$frontier = (string) file_get_contents( $this->root() . '/src/Routing/FrontierRecord.php' );
		$this->assertStringContainsString( 'degraded', $frontier );

		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'filter_term_link', $router );
		$this->assertStringContainsString( 'SOURCE_TERM', $router );
		$this->assertStringNotContainsString( 'add_rewrite_rule', $router );
	}

	/**
	 * MSEO.4 WooCommerce localized product permalink structural guards.
	 */
	public function test_mseo4_woo_product_permalink_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		$this->assertFileExists( $this->root() . '/src/Routing/WooProductCategoryAuthority.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/WooProductPathBuilder.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/WooProductPermalinkFingerprint.php' );
		$this->assertFileExists( $this->root() . '/src/Routing/WooProductDependencyRepository.php' );
		$this->assertFileExists( $this->root() . '/src/Jobs/WooProductRouteReindexJob.php' );

		$authority = (string) file_get_contents( $this->root() . '/src/Routing/WooProductCategoryAuthority.php' );
		$this->assertStringContainsString( 'wc_product_post_type_link_product_cat', $authority );
		$this->assertStringContainsString( 'finally', $authority );
		$this->assertStringContainsString( 'remove_filter', $authority );
		$this->assertStringContainsString( 'get_permalink', $authority );

		$builder = (string) file_get_contents( $this->root() . '/src/Routing/WooProductPathBuilder.php' );
		$this->assertStringContainsString( 'OUTCOME_SOURCE_FALLBACK_AUTHORITY_DISAGREE', $builder );
		$this->assertStringContainsString( 'OUTCOME_SOURCE_FALLBACK_NONDETERMINISTIC', $builder );

		$admission = (string) file_get_contents( $this->root() . '/src/Routing/RoutingCapabilityAdmission.php' );
		$this->assertStringContainsString( 'CODE_CAPABILITY_EPOCH = 2', $admission );
		$this->assertStringContainsString( 'SHAPE_PRODUCT_CATEGORY_PERMALINK', $admission );
		$this->assertStringContainsString( 'localized_urls_woo_product_fingerprint', $admission );
		$this->assertStringContainsString( 'hash_equals', $admission );

		$job = (string) file_get_contents( $this->root() . '/src/Jobs/WooProductRouteReindexJob.php' );
		$this->assertStringContainsString( 'MAX_PER_TICK = 100', $job );
		$this->assertStringContainsString( "TYPE_PRODUCT_DEP = 'product_dep'", $job );
		$this->assertStringContainsString( "TYPE_WOO_CONFIG = 'woo_product_config'", $job );
		$this->assertStringContainsString( 'CONFIG_ROOT_ID = 1', $job );
		$this->assertStringNotContainsString( 'product_dep / 0', $job );
		$this->assertStringNotContainsString( 'TYPE_PRODUCT_DEP, 0', $job );

		$frontier = (string) file_get_contents( $this->root() . '/src/Routing/ReindexFrontierRepository.php' );
		$this->assertStringContainsString( 'parent_source_types', $frontier );

		$hierarchy_job = (string) file_get_contents( $this->root() . '/src/Jobs/HierarchyReindexJob.php' );
		$this->assertStringContainsString( 'SOURCE_POST', $hierarchy_job );
		$this->assertStringContainsString( 'SOURCE_TERM', $hierarchy_job );
		$this->assertStringNotContainsString( 'product_dep', $hierarchy_job );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'WooProductPathBuilder', $plugin );
		$this->assertStringContainsString( 'WooProductRouteReindexJob', $plugin );
		$this->assertStringContainsString( 'aiml_woo_product_dep_root', $plugin );
		$this->assertStringContainsString( 'update_option_woocommerce_permalinks', $plugin );

		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'should_redirect_current_localized_to_effective', $router );
		$this->assertStringContainsString( 'is_same_normalized_url', $router );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );
		$this->assertSame( 10, Migrator::TARGET );
	}

	/**
	 * MSEO.5 program closeout architecture guards (code/architecture only).
	 *
	 * Does not assert ROADMAP prose or closure-document workflow state (A10).
	 */
	public function test_mseo5_program_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );
		$this->assertStringContainsString( 'step_8_mseo_localized_url_foundation', $migrator );

		foreach ( array(
			\AIMultilingual\Routing\EffectiveUrlService::class,
			\AIMultilingual\Routing\PathCanonicalizer::class,
			\AIMultilingual\Routing\PathHash::class,
			\AIMultilingual\Routing\SlugRouteRepository::class,
			\AIMultilingual\Routing\RouteHistoryRepository::class,
			\AIMultilingual\Routing\ReindexFrontierRepository::class,
			\AIMultilingual\Routing\SlugCandidateService::class,
			\AIMultilingual\Routing\RoutePublicationService::class,
			\AIMultilingual\Routing\ObjectLanguagePublicEligibility::class,
			\AIMultilingual\Routing\RoutingCapabilityRegistry::class,
			\AIMultilingual\Routing\RoutingCapabilityAdmission::class,
			\AIMultilingual\Routing\HierarchyPathBuilder::class,
			\AIMultilingual\Routing\WooProductPathBuilder::class,
			\AIMultilingual\Routing\Router::class,
			\AIMultilingual\Seo\LanguageRelationshipService::class,
			\AIMultilingual\Integration\RankMath\RankMathSitemapOverlay::class,
		) as $class ) {
			$this->assertTrue( class_exists( $class ), $class . ' must remain for MSEO program stack' );
		}

		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'filter_home_url', $router );
		$this->assertStringContainsString( 'filter_redirect_canonical', $router );
		$this->assertStringContainsString( 'filter_term_link', $router );
		$this->assertStringNotContainsString( 'add_rewrite_rule', $router );
		$this->assertStringNotContainsString( 'flush_rewrite_rules', $router );

		$eus = (string) file_get_contents( $this->root() . '/src/Routing/EffectiveUrlService.php' );
		$this->assertStringContainsString( 'is_localized_url_generation_enabled', $eus );

		$publication = (string) file_get_contents( $this->root() . '/src/Translation/Publication/PublicationService.php' );
		$this->assertTrue(
			false !== strpos( $publication, 'aiml_slug_publish_requires_route' )
			|| false !== strpos( $publication, 'reject_format_slug' )
			|| false !== strpos( $publication, 'publish_under_route_authority' ),
			'FORMAT_SLUG standalone publish must remain fail-closed.'
		);

		$jobs_processor = (string) file_get_contents( $this->root() . '/src/Jobs/BackgroundTranslationItemProcessor.php' );
		$this->assertStringContainsString( 'FORMAT_SLUG segments are not provider-admitted', $jobs_processor );

		$eligibility = (string) file_get_contents( $this->root() . '/src/Routing/ObjectLanguagePublicEligibility.php' );
		$this->assertStringContainsString( 'is_discoverable', $eligibility );

		$admission = (string) file_get_contents( $this->root() . '/src/Routing/RoutingCapabilityAdmission.php' );
		$this->assertStringContainsString( 'CODE_CAPABILITY_EPOCH', $admission );
		$this->assertStringContainsString( 'is_publicly_admitted', $admission );

		$history = (string) file_get_contents( $this->root() . '/src/Routing/RouteHistoryRepository.php' );
		$this->assertStringNotContainsString( 'destination_path', $history );

		$sitemap = (string) file_get_contents( $this->root() . '/src/Integration/RankMath/RankMathSitemapOverlay.php' );
		$this->assertStringContainsString( 'xhtml:link', $sitemap );
		$this->assertStringNotContainsString( 'replace_loc', $sitemap );

		$hier_job = (string) file_get_contents( $this->root() . '/src/Jobs/HierarchyReindexJob.php' );
		$this->assertStringContainsString( 'MAX_PER_TICK = 100', $hier_job );

		$woo_job = (string) file_get_contents( $this->root() . '/src/Jobs/WooProductRouteReindexJob.php' );
		$this->assertStringContainsString( 'MAX_PER_TICK = 100', $woo_job );

		$route_pub = (string) file_get_contents( $this->root() . '/src/Routing/RoutePublicationService.php' );
		$this->assertStringNotContainsString( 'wp_update_post', $route_pub );
		$this->assertStringNotContainsString( 'wp_update_term', $route_pub );

		$candidate = (string) file_get_contents( $this->root() . '/src/Routing/SlugCandidateService.php' );
		$this->assertStringNotContainsString( 'AIProvider', $candidate );
		$this->assertStringNotContainsString( 'translate(', $candidate );

		// Negatives: Post-MSEO backlog must not appear as production symbols.
		$src_files = array(
			$this->root() . '/src/Routing/Router.php',
			$this->root() . '/src/Routing/EffectiveUrlService.php',
			$this->root() . '/src/Plugin.php',
		);
		foreach ( $src_files as $path ) {
			$src = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( 'VariationRoute', $src );
			$this->assertStringNotContainsString( 'LayeredNav', $src );
			$this->assertStringNotContainsString( 'TranslatedRewriteBase', $src );
			$this->assertStringNotContainsString( 'LocalizedCheckoutEndpoint', $src );
		}

		$this->assertFileDoesNotExist( $this->root() . '/src/Routing/CompetingSitemapGenerator.php' );
		$this->assertFileDoesNotExist( $this->root() . '/src/Routing/VariationRoutePublisher.php' );
	}

	/**
	 * V1.5.1 corrective milestone architecture guards.
	 */
	public function test_v151_corrective_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );
		$this->assertSame( 10, Migrator::TARGET );

		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'filtering_term_link', $router );
		$this->assertStringContainsString( 'OutboundLocalizationSuspender', $router );
		$this->assertStringContainsString( 'filter_term_link', $router );
		$this->assertStringNotContainsString( 'add_rewrite_rule', $router );
		$this->assertStringNotContainsString( 'CompetingUrlAuthority', $router );

		$sb11 = (string) file_get_contents( $this->root() . '/src/Seo/LanguageRelationshipService.php' );
		$this->assertStringContainsString( 'url_to_postid_unfiltered_home', $sb11 );
		$this->assertStringContainsString( 'OutboundLocalizationSuspender', $sb11 );
		$this->assertStringContainsString( 'EffectiveUrlService', $sb11 );
		$this->assertStringNotContainsString( 'CompetingSeoUrlAuthority', $sb11 );

		$hier = (string) file_get_contents( $this->root() . '/src/Routing/HierarchyPathBuilder.php' );
		$this->assertStringContainsString( 'OutboundLocalizationSuspender', $hier );

		$this->assertTrue( class_exists( \AIMultilingual\Routing\OutboundLocalizationSuspender::class ) );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringNotContainsString( 'ProgramB', $plugin );
		$this->assertStringNotContainsString( 'VariationRoutePublisher', $plugin );

		$this->assertFileExists( $this->root() . '/tests/integration/V151D1TermLinkRecursionTest.php' );
		$this->assertFileExists( $this->root() . '/tests/integration/V151ModelAConsumerTest.php' );
		$this->assertFileExists( $this->root() . '/docs/plans/V151_LOCALIZED_URL_CORRECTNESS_STABILIZATION_IMPLEMENTATION_PLAN.md' );

		$version = (string) file_get_contents( $this->root() . '/universal-multilingual.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*1\.15\.2/', $version );
		$this->assertStringContainsString( "define( 'AIML_VERSION', '1.15.2' )", $version );
	}

	/**
	 * P0 Localized URL Operator Completion architecture guards.
	 */
	public function test_p0_operator_completion_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );
		$this->assertSame( 10, Migrator::TARGET );

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );

		$version = (string) file_get_contents( $this->root() . '/universal-multilingual.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*1\.15\.2/', $version );
		$this->assertStringContainsString( "define( 'AIML_VERSION', '1.15.2' )", $version );

		$this->assertFileExists( $this->root() . '/src/Rest/TermSlugController.php' );
		$this->assertFileExists( $this->root() . '/src/Admin/TermLocalizedSlugAdmin.php' );
		$this->assertFileExists( $this->root() . '/docs/plans/LOCALIZED_URL_OPERATOR_COMPLETION_P0_IMPLEMENTATION_PLAN.md' );

		$term_ctrl = (string) file_get_contents( $this->root() . '/src/Rest/TermSlugController.php' );
		$this->assertStringContainsString( 'SlugCandidateService', $term_ctrl );
		$this->assertStringContainsString( 'RoutePublicationService', $term_ctrl );
		$this->assertStringContainsString( 'publish_term_route', $term_ctrl );
		$this->assertStringNotContainsString( 'CompetingUrlAuthority', $term_ctrl );

		$routes = (string) file_get_contents( $this->root() . '/src/Routing/RoutePublicationService.php' );
		$this->assertStringContainsString( 'function sync_term_view', $routes );

		$settings = (string) file_get_contents( $this->root() . '/src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( 'render_localized_urls_honesty', $settings );
		$this->assertStringContainsString( 'Capability admission', $settings );

		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'TermSlugController', $plugin );
		$this->assertStringContainsString( 'TermLocalizedSlugAdmin', $plugin );
		$this->assertStringNotContainsString( 'VariationRoutePublisher', $plugin );

		$workspace = (string) file_get_contents( $this->root() . '/assets/translator-workspace/src/App.tsx' );
		$this->assertStringContainsString( 'LocalizedSlugPanel', $workspace );
	}

	/**
	 * P2 Jobs / stale operator literacy — TARGET 8, no new Job type, A1 via missing resolve.
	 */
	public function test_p2_jobs_stale_literacy_boundaries(): void {
		$this->assertSame( 10, Migrator::TARGET );

		$version = (string) file_get_contents( $this->root() . '/universal-multilingual.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*1\.15\.2/', $version );

		$service = (string) file_get_contents( $this->root() . '/src/Jobs/BackgroundTranslationJobService.php' );
		// AIT1 (ADR-0031) folded P2's job_type_resolves_missing into the shared
		// job_type_mode() → TranslatableSegmentEligibility policy.
		$this->assertStringContainsString( 'job_type_mode', $service );
		$this->assertStringContainsString( 'JobTypes::BULK_TRANSLATE', $service );
		$this->assertStringNotContainsString( 'silent_overwrite', $service );

		$types = (string) file_get_contents( $this->root() . '/src/Jobs/JobTypes.php' );
		$this->assertStringContainsString( 'BULK_TRANSLATE', $types );
		// AIT1 adds exactly one job type: retranslate_machine (ADR-0031).
		$this->assertStringContainsString( 'RETRANSLATE_MACHINE', $types );
		$this->assertSame(
			5,
			substr_count( $types, 'public const' ),
			'AIT1 adds retranslate_machine and no other JobTypes constant.'
		);

		$this->assertFileExists( $this->root() . '/docs/plans/P2_JOBS_STALE_OPERATOR_LITERACY_IMPLEMENTATION_PLAN.md' );
		$this->assertFileExists( $this->root() . '/assets/translator-workspace/src/utils/stale-copy.ts' );
		$this->assertFileExists( $this->root() . '/assets/translator-workspace/src/utils/job-item-literacy.ts' );

		// Jobs→Ops reverse enrichment remains Deferred (no translation_id on Jobs serializers).
		foreach ( array(
			'src/Jobs/JobsViewModel.php',
			'src/Jobs/JobItemViewModel.php',
			'src/Jobs/JobsViewModelSerializer.php',
		) as $rel ) {
			$contents = (string) file_get_contents( $this->root() . '/' . $rel );
			$this->assertStringNotContainsString(
				'translation_id',
				$contents,
				$rel . ' must not enrich Jobs payloads with translation_id.'
			);
		}

		$migrator = (string) file_get_contents( $this->root() . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_11_', $migrator );
	}
}
