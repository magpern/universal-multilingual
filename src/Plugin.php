<?php
/**
 * Composition root.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual;

use AIMultilingual\Jobs\BackgroundTranslationBatchCoordinator;
use AIMultilingual\Jobs\BackgroundTranslationConcurrencyPolicy;
use AIMultilingual\Jobs\BackgroundTranslationDiagnostics;
use AIMultilingual\Jobs\BackgroundTranslationItemProcessor;
use AIMultilingual\Jobs\BackgroundTranslationItemRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobAuditLogger;
use AIMultilingual\Jobs\BackgroundTranslationJobProviderValidator;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Jobs\BackgroundTranslationBudgetPolicy;
use AIMultilingual\Jobs\BackgroundTranslationRetentionCleanup;
use AIMultilingual\Jobs\BackgroundTranslationRetryPolicy;
use AIMultilingual\Jobs\BackgroundTranslationScheduler;
use AIMultilingual\Jobs\BackgroundTranslationWorker;
use AIMultilingual\Jobs\JobLeaseService;
use AIMultilingual\Jobs\JobProgressReconciler;
use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Jobs\JobsCli;
use AIMultilingual\Jobs\JobsController;
use AIMultilingual\Rest\SiteTranslateController;
use AIMultilingual\SiteTranslate\SiteTranslateAdmissionService;
use AIMultilingual\SiteTranslate\SiteTranslateBatchService;
use AIMultilingual\SiteTranslate\SiteTranslateCoverageService;
use AIMultilingual\SiteTranslate\SiteTranslateLocalizedUrlBatchService;
use AIMultilingual\Jobs\JobsViewModelSerializer;
use AIMultilingual\Jobs\SlugRouteActivationJob;
use AIMultilingual\Jobs\CapabilityVerificationJob;
use AIMultilingual\Jobs\HierarchyReindexJob;
use AIMultilingual\Admin\Editor;
use AIMultilingual\Admin\GlossaryAdminPage;
use AIMultilingual\Admin\RolloutAdminPage;
use AIMultilingual\Admin\SeoDiagnosticsAdminPage;
use AIMultilingual\Admin\SettingsPage;
use AIMultilingual\Admin\TermLocalizedSlugAdmin;
use AIMultilingual\Admin\TranslatorWorkspace;
use AIMultilingual\Block\AdapterRegistry;
use AIMultilingual\Block\AttributeRegistrar;
use AIMultilingual\Block\BlockExtractionLogger;
use AIMultilingual\Block\BlockIdentityLogger;
use AIMultilingual\Block\BlockHealthService;
use AIMultilingual\Block\BlockIdentityAnalyzer;
use AIMultilingual\Block\BlockIdentityMigration;
use AIMultilingual\Block\BlockMetricsAggregator;
use AIMultilingual\Block\BlockMigrationLogger;
use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Block\BlockRenderLogger;
use AIMultilingual\Block\SavePipeline;
use AIMultilingual\Block\UuidInjector;
use AIMultilingual\Cache\Cache;
use AIMultilingual\Database\Migrator;
use AIMultilingual\Elementor\ElementorCacheInvalidation;
use AIMultilingual\Elementor\ElementorCompatibility;
use AIMultilingual\Elementor\ElementorControlRegistry;
use AIMultilingual\Elementor\ElementorDiagnostics;
use AIMultilingual\Elementor\ElementorDocumentDetector;
use AIMultilingual\Elementor\ElementorExtractor;
use AIMultilingual\Elementor\ElementorFrontendBridge;
use AIMultilingual\Elementor\ElementorIdentity;
use AIMultilingual\Elementor\ElementorOverlayApplier;
use AIMultilingual\Elementor\ElementorOverlayResolver;
use AIMultilingual\Extension\Cli\ExtensionCli;
use AIMultilingual\Extension\ExtensionDiagnostics;
use AIMultilingual\Extension\ExtensionRegistrar;
use AIMultilingual\Extension\ExtensionRegistry;
use AIMultilingual\Extension\ExtensionServices;
use AIMultilingual\Extension\VisitorTranslationResolver;
use AIMultilingual\Frontend\FloatingSelector;
use AIMultilingual\Frontend\Switcher;
use AIMultilingual\User\PreferenceServices;
use AIMultilingual\User\PreferredLanguage;
use AIMultilingual\User\PreferredLanguageField;
use AIMultilingual\User\RegionalPreferencesHost;
use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsService;
use AIMultilingual\Seo\DocumentSeoHead;
use AIMultilingual\Seo\LanguageRelationshipService;
use AIMultilingual\Glossary\GlossaryCapabilities;
use AIMultilingual\Glossary\GlossaryMatcher;
use AIMultilingual\Glossary\GlossaryNormalizer;
use AIMultilingual\Glossary\GlossaryRepository;
use AIMultilingual\Glossary\GlossaryService;
use AIMultilingual\Integration\FluentForms\FluentFormsIntegration;
use AIMultilingual\Integration\RankMath\RankMathIntegration;
use AIMultilingual\Integration\WooCommerce\WooCommerceIntegration;
use AIMultilingual\Integration\WooCommerce\OrderTransactionalLanguage;
use AIMultilingual\Integration\WooCommerce\CustomerEmailBridge;
use AIMultilingual\Integration\Identity\PluginIdentity;
use AIMultilingual\Integration\IntegrationDiagnostics;
use AIMultilingual\Integration\IntegrationFrontendBridge;
use AIMultilingual\Integration\IntegrationAdmission;
use AIMultilingual\Integration\IntegrationAdmissionRegistry;
use AIMultilingual\Integration\IntegrationRegistry;
use AIMultilingual\Integration\TermVisitorOverlay;
use AIMultilingual\Surface\Meta\RankMathMetaDefinitions;
use AIMultilingual\Surface\Meta\RegisteredMetaExtractor;
use AIMultilingual\Surface\Meta\RegisteredMetaReader;
use AIMultilingual\Surface\Meta\RegisteredMetaRegistry;
use AIMultilingual\Surface\PostSurfaceAdapter;
use AIMultilingual\Surface\RequestLocalInvalidationCoordinator;
use AIMultilingual\Surface\SurfaceRegistry;
use AIMultilingual\Surface\TermSurfaceAdapter;
use AIMultilingual\Workspace\Operator\AllowedActionsResolver;
use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\LanguageResolver;
use AIMultilingual\Language\Languages;
use AIMultilingual\Rest\GlossaryController;
use AIMultilingual\Rest\ProviderController;
use AIMultilingual\Rest\PromotionController;
use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Database\PromotionStateRepository;
use AIMultilingual\Promotion\ObjectIdentityResolver;
use AIMultilingual\Promotion\PromotionAudit;
use AIMultilingual\Promotion\ReviewToken;
use AIMultilingual\Promotion\TranslationConflictDetector;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationImportService;
use AIMultilingual\Rest\ViewModel\ReviewQueueItemSerializer;
use AIMultilingual\Rest\ViewModel\WorkspacePageSummarySerializer;
use AIMultilingual\Rest\ViewModel\WorkspaceSegmentSerializer;
use AIMultilingual\Rest\ViewModel\WorkspaceTranslationStatusSerializer;
use AIMultilingual\Rest\WorkspaceController;
use AIMultilingual\Rest\TermSlugController;
use AIMultilingual\Rollout\RolloutCapabilities;
use AIMultilingual\Rollout\RolloutConfigurationRepository;
use AIMultilingual\Rollout\GeneralAvailabilityCohortProvider;
use AIMultilingual\Rollout\RolloutPolicyService;
use AIMultilingual\Rollout\RolloutRenderGateBridge;
use AIMultilingual\Rollout\RolloutCli;
use AIMultilingual\Rollout\Cache\RenderCacheInvalidationService;
use AIMultilingual\Rollout\Cache\RenderCacheKeyFactory;
use AIMultilingual\Rollout\Cache\RenderCacheService;
use AIMultilingual\Rollout\Cache\RolloutCacheInvalidationHooks;
use AIMultilingual\Rollout\Cache\RolloutRenderCacheBridge;
use AIMultilingual\Rollout\Metrics\RolloutMetricsCollector;
use AIMultilingual\Routing\EffectiveUrlService;
use AIMultilingual\Routing\HierarchyPathBuilder;
use AIMultilingual\Routing\HierarchyChildRepository;
use AIMultilingual\Routing\LocalizedUrlsActivationService;
use AIMultilingual\Routing\PathCanonicalizer;
use AIMultilingual\Routing\ReindexFrontierRepository;
use AIMultilingual\Routing\RouteHistoryRepository;
use AIMultilingual\Routing\RoutingCapabilityAdmission;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\SlugRouteActivationVerifier;
use AIMultilingual\Routing\SlugRouteRepository;
use AIMultilingual\Routing\Router;
use AIMultilingual\Translation\AI\CredentialVault;
use AIMultilingual\Translation\AI\NullAIProvider;
use AIMultilingual\Translation\AI\PromptProfileRegistry;
use AIMultilingual\Translation\AI\ProviderFactory;
use AIMultilingual\Translation\AI\ProviderRegistry;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\BlockExtractor;
use AIMultilingual\Translation\Publication\PublicationAuditLogger;
use AIMultilingual\Translation\Publication\PublicationEditInvalidationAuditBridge;
use AIMultilingual\Translation\Publication\PublicationPolicy;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Memory\TMEligibilityPolicy;
use AIMultilingual\Translation\Memory\TMGenerationLookup;
use AIMultilingual\Translation\Memory\TMRepository;
use AIMultilingual\Translation\Memory\TranslationMemoryService;
use AIMultilingual\Translation\BlockFrontendRenderer;
use AIMultilingual\Translation\BlockFrontendRenderLogger;
use AIMultilingual\Translation\BlockRenderGate;
use AIMultilingual\Translation\BlockRenderer;
use AIMultilingual\Translation\BlockTranslationLookup;
use AIMultilingual\Translation\BlockTranslationSanitizer;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Renderer;
use AIMultilingual\Translation\Store;
use AIMultilingual\Translation\TermAdoptionService;
use AIMultilingual\Translation\TermExtractor;
use AIMultilingual\Translation\TermTranslationResolver;
use AIMultilingual\Workspace\QA\Checks\GlossaryTermCheck;
use AIMultilingual\Workspace\QA\QAEngine;
use AIMultilingual\Workspace\PreviewService;
use AIMultilingual\Promotion\PromotionCapabilities;
use AIMultilingual\Workspace\Review\ReviewCapabilities;
use AIMultilingual\Workspace\Review\ReviewEditInvalidationAuditBridge;
use AIMultilingual\Workspace\Review\ReviewWorkflowService;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\Suggestion\AISuggestionProvider;
use AIMultilingual\Workspace\Suggestion\GlossarySuggestionProvider;
use AIMultilingual\Workspace\Suggestion\TranslationMemorySuggestionProvider;
use AIMultilingual\Workspace\TranslationService;
use AIMultilingual\Workspace\TranslationStatusCalculator;
use AIMultilingual\Workspace\TranslationSuggestionService;
use AIMultilingual\Workspace\WorkspaceService;
use WP_Post;

/**
 * Builds the object graph once and lets each service register its own hooks.
 *
 * Services are constructed eagerly and wired by hand. There is no container:
 * the graph is small, and an explicit constructor call says more about what
 * depends on what than a service definition would.
 */
final class Plugin {

	/**
	 * Capability gating translation work.
	 *
	 * Translators and editors need this daily; it is deliberately not
	 * `manage_options`, which stays reserved for configuration.
	 */
	public const CAPABILITY = 'aiml_translate';

	/**
	 * Roles granted the translation capability on activation.
	 */
	private const CAPABLE_ROLES = array( 'administrator', 'editor' );

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether services have been wired.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Plugin settings shared across the composition root.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires services and registers hooks. Idempotent.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->settings = new Settings();
		$settings       = $this->settings;
		$cache          = new Cache();
		$languages      = new Languages( $cache );
		$resolver       = new LanguageResolver();
		$context        = new LanguageContext();
		$store          = new Store( $cache );

		$adapter_registry = new AdapterRegistry();
		$block_registry   = new BlockRegistry( $adapter_registry );
		$block_logger     = new BlockIdentityLogger();
		$uuid_injector    = new UuidInjector( $block_registry, $block_logger );
		$block_extractor  = new BlockExtractor(
			$adapter_registry,
			$block_registry,
			new BlockExtractionLogger()
		);

		$elementor_diagnostics   = new ElementorDiagnostics();
		$elementor_compatibility = new ElementorCompatibility();
		$elementor_detector      = new ElementorDocumentDetector();
		$elementor_registry      = new ElementorControlRegistry();
		$elementor_identity      = new ElementorIdentity();
		$elementor_extractor     = new ElementorExtractor(
			$elementor_detector,
			$elementor_registry,
			$elementor_identity,
			$elementor_diagnostics
		);

		$integration_diagnostics = new IntegrationDiagnostics();
		$integration_registry    = new IntegrationRegistry( $integration_diagnostics );
		$plugin_identity         = new PluginIdentity( $integration_diagnostics );
		$woo_integration         = WooCommerceIntegration::create_default( $plugin_identity, $store, $context );

		$path_canonicalizer     = new PathCanonicalizer();
		$slug_routes            = new SlugRouteRepository();
		$route_history          = new RouteHistoryRepository();
		$routing_capabilities   = new RoutingCapabilityRegistry();
		$routing_admission      = new RoutingCapabilityAdmission( $settings, $routing_capabilities );
		$hierarchy_paths        = new HierarchyPathBuilder( $slug_routes, $path_canonicalizer );
		$woo_category_authority = new \AIMultilingual\Routing\WooProductCategoryAuthority();
		$woo_product_paths      = new \AIMultilingual\Routing\WooProductPathBuilder(
			$woo_category_authority,
			$slug_routes,
			$path_canonicalizer
		);
		$effective_url          = new EffectiveUrlService(
			$settings,
			$slug_routes,
			$routing_capabilities,
			$path_canonicalizer,
			$languages,
			$routing_admission
		);
		$slug_eligibility       = new \AIMultilingual\Routing\ObjectLanguagePublicEligibility(
			$store,
			$languages,
			$routing_capabilities,
			$settings,
			$slug_routes,
			$routing_admission
		);
		$relationships          = new LanguageRelationshipService(
			$languages,
			$context,
			$effective_url,
			$slug_eligibility,
			$settings
		);
		$integration_registry->register(
			FluentFormsIntegration::create_default( $plugin_identity )
		);
		$integration_registry->register( $woo_integration );
		$rank_math_integration = RankMathIntegration::create_default(
			$plugin_identity,
			$store,
			$context,
			$relationships
		);
		$integration_registry->register( $rank_math_integration );
		// A.SEOe: Rank Math serves sitemaps on parse_query (before `wp`).
		$rank_math_integration->register_sitemap_hooks();

		$meta_registry         = new RegisteredMetaRegistry( $plugin_identity, $store );
		$extension_diagnostics = new ExtensionDiagnostics();
		$extension_registry    = new ExtensionRegistry();
		$extension_registrar   = new ExtensionRegistrar(
			$meta_registry,
			$adapter_registry,
			$extension_registry,
			$extension_diagnostics
		);
		/**
		 * Register Extension API v1 extensions (meta, block adapters).
		 *
		 * Registries seal after this hook; late registration is rejected.
		 *
		 * @since 1.3.0
		 *
		 * @param ExtensionRegistrar $extension_registrar Public registrar.
		 */
		do_action( 'aiml_register_extensions', $extension_registrar );
		$extension_registrar->seal();

		/**
		 * Register typed Integration API v1 integrations.
		 *
		 * Callbacks must call `$registry->register( PluginIntegrationInterface )`
		 * with code-owned objects only. Database/serialized callbacks are forbidden.
		 *
		 * @since 1.1.0
		 *
		 * @param IntegrationRegistry $integration_registry Registry.
		 */
		do_action( 'aiml_register_integrations', $integration_registry );

		$chrome_admission = new IntegrationAdmissionRegistry( $integration_diagnostics, $plugin_identity );
		$chrome_admission->collect_from_registry( $integration_registry );
		IntegrationAdmission::bind( $chrome_admission );
		$activate_chrome = static function () use ( $chrome_admission ): void {
			$chrome_admission->validate_and_activate();
		};
		if ( did_action( 'init' ) ) {
			$activate_chrome();
		} else {
			add_action( 'init', $activate_chrome, 20 );
		}

		$order_transactional_language = new OrderTransactionalLanguage(
			$context,
			$languages,
			$integration_diagnostics
		);
		$order_transactional_language->register();
		$email_bridge = new CustomerEmailBridge(
			$woo_integration,
			$order_transactional_language,
			$store,
			$plugin_identity,
			$integration_diagnostics
		);
		// Defer until WooCommerce has loaded so compatibility/hooks are accurate.
		add_action(
			'init',
			static function () use ( $email_bridge ): void {
				$email_bridge->register();
			},
			20
		);

		RankMathMetaDefinitions::register_into(
			$meta_registry,
			static fn (): bool => $rank_math_integration->allows_extract_operation()
		);
		$registered_meta_reader  = new RegisteredMetaReader();
		$registered_meta_extract = new RegisteredMetaExtractor( $meta_registry, $registered_meta_reader );

		$extractor           = new Extractor( $settings, $block_extractor, $elementor_extractor, $integration_registry, $registered_meta_extract, $chrome_admission );
		$block_renderer      = new BlockRenderer( $adapter_registry, new BlockRenderLogger() );
		$config_repo         = new RolloutConfigurationRepository();
		$rollout_bridge      = new RolloutRenderGateBridge(
			new RolloutPolicyService( new GeneralAvailabilityCohortProvider() ),
			$config_repo
		);
		$render_cache_bridge = new RolloutRenderCacheBridge(
			new RenderCacheService( $cache ),
			new RenderCacheKeyFactory(),
			$store,
			$config_repo
		);
		$block_frontend      = new BlockFrontendRenderer(
			new BlockRenderGate( $rollout_bridge ),
			new BlockTranslationLookup( $store ),
			new BlockTranslationSanitizer(),
			$block_renderer,
			new BlockFrontendRenderLogger(),
			$settings,
			$context,
			$extractor,
			$render_cache_bridge
		);

		$router = new Router(
			$languages,
			$resolver,
			$context,
			$effective_url,
			$settings,
			$path_canonicalizer,
			$slug_routes,
			$route_history,
			$hierarchy_paths
		);
		$router->register();
		( new Renderer( $context, $store, $extractor, $block_frontend ) )->register();
		( new DocumentSeoHead( $relationships ) )->register();
		$switcher = new Switcher( $settings, $languages, $context, $relationships );
		$switcher->register();
		( new FloatingSelector( $settings, $languages, $context, $switcher ) )->register();

		$preferred_language = new PreferredLanguage( $languages );
		PreferenceServices::bind_preferred_language( $preferred_language );
		( new PreferredLanguageField( $preferred_language ) )->register();
		( new RegionalPreferencesHost() )->register();

		$elementor_resolver = new ElementorOverlayResolver( $store, $elementor_diagnostics );
		$elementor_applier  = new ElementorOverlayApplier( $elementor_registry, $elementor_diagnostics );
		( new ElementorFrontendBridge(
			$settings,
			$context,
			$elementor_compatibility,
			$elementor_detector,
			$elementor_extractor,
			$elementor_resolver,
			$elementor_applier,
			$elementor_diagnostics
		) )->register();
		( new ElementorCacheInvalidation( $elementor_detector, $elementor_compatibility, $settings, $context ) )->register();

		$term_extractor = new TermExtractor( $registered_meta_extract );
		$term_resolver  = new TermTranslationResolver( $store );
		$term_adoption  = new TermAdoptionService( $store, $term_extractor, $term_resolver );

		( new IntegrationFrontendBridge(
			$settings,
			$context,
			$integration_registry,
			$store,
			$integration_diagnostics,
			$term_resolver
		) )->register();

		add_action(
			'wp',
			static function () use ( $context, $term_resolver ): void {
				if ( function_exists( 'is_admin' ) && is_admin() ) {
					return;
				}
				if ( $context->is_default() ) {
					return;
				}
				$language = $context->current();
				if ( null === $language ) {
					return;
				}
				( new TermVisitorOverlay( $term_resolver, (int) $language->language_id ) )->register();
			},
			6
		);

		$assembler         = new SegmentAssembler( $extractor, $store, $block_registry, $term_extractor, $term_resolver, $meta_registry );
		$status_calculator = new TranslationStatusCalculator( $store );
		$vault             = new CredentialVault();
		$profiles          = new PromptProfileRegistry();
		$provider_registry = new ProviderRegistry( $this->settings, new NullAIProvider() );
		$provider_registry->register(
			ProviderFactory::openai_from_settings( $this->settings, $vault, $profiles )
		);
		$provider_registry->register(
			ProviderFactory::deepseek_from_settings( $this->settings, $vault, $profiles )
		);
		$glossary_service     = new GlossaryService(
			new GlossaryRepository(),
			new GlossaryNormalizer(),
			new GlossaryMatcher( new GlossaryNormalizer() )
		);
		$tm_service           = new TranslationMemoryService( new TMRepository() );
		$tm_lookup            = new TMGenerationLookup(
			$tm_service,
			new TMEligibilityPolicy( $glossary_service ),
			$glossary_service
		);
		$assessment_assembler = new AssessmentAssembler();
		$publication_policy   = new PublicationPolicy();
		$publication_audit    = new PublicationAuditLogger();
		$surface_registry     = new SurfaceRegistry();
		$post_surface         = new PostSurfaceAdapter( $this->settings, $extractor, $meta_registry );
		$term_surface         = new TermSurfaceAdapter( $term_extractor, $meta_registry );
		$surface_registry->register( $post_surface );
		$surface_registry->register( $term_surface );
		$invalidation_coordinator = new RequestLocalInvalidationCoordinator( $store, $surface_registry, $meta_registry );
		$visitor_resolver         = new VisitorTranslationResolver(
			$store,
			$languages,
			$context,
			$meta_registry,
			$integration_registry,
			$plugin_identity,
			$extension_diagnostics,
			$chrome_admission
		);
		ExtensionServices::bind(
			$invalidation_coordinator,
			$visitor_resolver,
			$extension_registrar,
			$extension_diagnostics,
			$context
		);
		$post_surface->register_invalidation_events( $invalidation_coordinator );
		$term_surface->register_invalidation_events( $invalidation_coordinator );
		$invalidation_coordinator->ensure_shutdown_hook();
		( new \AIMultilingual\Integration\WooCommerce\WooCommerceInvalidation(
			$woo_integration,
			$store,
			$invalidation_coordinator
		) )->register();

		$segment_authority_registry = new \AIMultilingual\Integration\IntegrationSegmentAuthorityRegistry();
		$segment_authority_registry->register( new \AIMultilingual\Integration\WooCommerce\WooAttributeLabelAuthority() );
		AllowedActionsResolver::set_surface_registry( $surface_registry );
		AllowedActionsResolver::set_segment_authority_registry( $segment_authority_registry );

		$publication = new PublicationService(
			$store,
			$assessment_assembler,
			$publication_policy,
			$publication_audit,
			$this->settings,
			$surface_registry,
			$term_resolver,
			$segment_authority_registry
		);

		$collision_checker = new \AIMultilingual\Routing\CanonicalPathCollisionChecker(
			$slug_routes,
			$route_history,
			$path_canonicalizer
		);
		$slug_candidates   = new \AIMultilingual\Routing\SlugCandidateService( $store );
		$route_publication = new \AIMultilingual\Routing\RoutePublicationService(
			$store,
			$publication,
			$slug_routes,
			$route_history,
			$path_canonicalizer,
			$collision_checker,
			$slug_eligibility,
			$routing_capabilities,
			$hierarchy_paths,
			$routing_admission,
			$woo_product_paths
		);

		$localized_urls_activation = new LocalizedUrlsActivationService( $this->settings );
		$slug_route_activation     = new SlugRouteActivationJob(
			$this->settings,
			$slug_routes,
			new SlugRouteActivationVerifier(
				$languages,
				$routing_capabilities,
				$path_canonicalizer,
				$slug_routes,
				$route_history,
				$collision_checker
			),
			$localized_urls_activation
		);
		$localized_urls_activation->bind_job( $slug_route_activation );
		$slug_route_activation->register_hooks();

		$capability_verification = new CapabilityVerificationJob(
			$this->settings,
			$routing_admission,
			$routing_capabilities,
			$hierarchy_paths,
			$slug_routes,
			$woo_product_paths
		);
		$capability_verification->register_hooks();
		add_action(
			'init',
			static function () use ( $capability_verification ): void {
				$capability_verification->maybe_enqueue();
			},
			20
		);

		$frontier_repository = new ReindexFrontierRepository();
		$hierarchy_reindex   = new HierarchyReindexJob(
			$frontier_repository,
			$route_publication,
			new HierarchyChildRepository(),
			$slug_routes
		);
		$hierarchy_reindex->register_hooks();

		$woo_product_reindex = new \AIMultilingual\Jobs\WooProductRouteReindexJob(
			$frontier_repository,
			$route_publication,
			$slug_routes,
			new \AIMultilingual\Routing\WooProductDependencyRepository(),
			$this->settings
		);
		$woo_product_reindex->register_hooks();

		add_action(
			'aiml_hierarchy_reindex_root',
			static function ( $source_type, $source_id ) use ( $hierarchy_reindex ): void {
				$hierarchy_reindex->enqueue_root( (string) $source_type, (int) $source_id );
			},
			10,
			2
		);

		add_action(
			'aiml_woo_product_dep_root',
			static function ( $term_id ) use ( $woo_product_reindex ): void {
				$woo_product_reindex->enqueue_product_dep( (int) $term_id );
			},
			10,
			1
		);

		add_action(
			'set_object_terms',
			static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $woo_product_reindex ): void {
				unset( $terms, $tt_ids );
				if ( 'product_cat' !== (string) $taxonomy ) {
					return;
				}
				$post = get_post( (int) $object_id );
				if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
					return;
				}
				$assigned = wp_get_post_terms( (int) $object_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( ! is_array( $assigned ) ) {
					return;
				}
				foreach ( $assigned as $term_id ) {
					$woo_product_reindex->enqueue_product_dep( (int) $term_id );
				}
			},
			20,
			4
		);

		add_action(
			'update_option_woocommerce_permalinks',
			static function () use ( $woo_product_reindex ): void {
				$woo_product_reindex->enqueue_woo_config();
			},
			20,
			0
		);

		add_action(
			'post_updated',
			static function ( $post_id, $post_after, $post_before ) use ( $hierarchy_reindex ): void {
				if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
					return;
				}
				if ( 'page' !== $post_after->post_type ) {
					return;
				}
				$parent_changed = (int) $post_after->post_parent !== (int) $post_before->post_parent;
				$name_changed   = (string) $post_after->post_name !== (string) $post_before->post_name;
				if ( ! $parent_changed && ! $name_changed ) {
					return;
				}
				$roots = array( (int) $post_after->ID );
				if ( (int) $post_before->post_parent > 0 ) {
					$roots[] = (int) $post_before->post_parent;
				}
				if ( (int) $post_after->post_parent > 0 ) {
					$roots[] = (int) $post_after->post_parent;
				}
				foreach ( array_unique( $roots ) as $root_id ) {
					$hierarchy_reindex->enqueue_root( Store::SOURCE_POST, (int) $root_id );
				}
			},
			20,
			3
		);

		add_action(
			'edited_term',
			static function ( $term_id, $tt_id, $taxonomy ) use ( $hierarchy_reindex, $routing_capabilities, $woo_product_reindex ): void {
				unset( $tt_id );
				if ( ! $routing_capabilities->supports_term_taxonomy( (string) $taxonomy ) ) {
					return;
				}
				$hierarchy_reindex->enqueue_root( Store::SOURCE_TERM, (int) $term_id );
				if ( 'product_cat' === (string) $taxonomy ) {
					$woo_product_reindex->enqueue_product_dep( (int) $term_id );
				}
			},
			20,
			3
		);

		add_action(
			'delete_term',
			static function ( $term_id ) use ( $route_publication ): void {
				$route_publication->purge_for_term( (int) $term_id );
			},
			10,
			1
		);

		$translation        = new TranslationService(
			$store,
			$assembler,
			$languages,
			$provider_registry->active(),
			$profiles,
			null,
			$glossary_service,
			null,
			$tm_lookup,
			$tm_service,
			$publication,
			$term_adoption,
			$meta_registry
		);
		$preview            = new PreviewService( $languages, $context, $router );
		$suggestion_service = new TranslationSuggestionService(
			array(
				new TranslationMemorySuggestionProvider( $tm_service ),
				new GlossarySuggestionProvider( $glossary_service ),
				new AISuggestionProvider( $translation ),
			)
		);
		$qa_engine          = new QAEngine(
			null,
			! empty( $this->settings->get()['qa_block_on_error'] )
		);
		$qa_engine->register( new GlossaryTermCheck( $glossary_service ) );
		$review    = new ReviewWorkflowService( $store, null, $term_resolver );
		$workspace = new WorkspaceService(
			$assembler,
			$status_calculator,
			$translation,
			$preview,
			$languages,
			$store,
			$extractor,
			$suggestion_service,
			$qa_engine,
			$tm_service,
			$review,
			null,
			$assessment_assembler,
			null,
			$publication,
			$surface_registry,
			$term_adoption,
			$slug_candidates,
			$route_publication
		);

		add_action(
			'wp_trash_post',
			static function ( $post_id ) use ( $route_publication ): void {
				$route_publication->deactivate_for_source( (int) $post_id );
			}
		);
		add_action(
			'before_delete_post',
			static function ( $post_id ) use ( $route_publication ): void {
				$route_publication->purge_for_source( (int) $post_id );
			}
		);
		add_action(
			'post_updated',
			static function ( $post_id, $post_after, $post_before ) use ( $route_publication, $slug_routes ): void {
				if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
					return;
				}
				if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
					return;
				}
				if (
					(string) $post_after->post_name === (string) $post_before->post_name
					&& (int) $post_after->post_parent === (int) $post_before->post_parent
				) {
					return;
				}

				foreach ( $slug_routes->list_language_ids_for_source( Store::SOURCE_POST, (int) $post_id ) as $language_id ) {
					$route_publication->refresh_source_path( $post_after, (int) $language_id );
				}
			},
			20,
			3
		);

		$job_repo        = new BackgroundTranslationJobRepository();
		$item_repo       = new BackgroundTranslationItemRepository();
		$job_leases      = new JobLeaseService( $job_repo, $item_repo );
		$job_reconciler  = new JobProgressReconciler( $job_repo, $item_repo );
		$job_audit       = new BackgroundTranslationJobAuditLogger();
		$job_diagnostics = new BackgroundTranslationDiagnostics( $job_repo, $item_repo );
		$job_scheduler   = new BackgroundTranslationScheduler(
			new BackgroundTranslationRetentionCleanup( $job_repo, $item_repo, $job_diagnostics ),
			$job_diagnostics
		);
		$job_budget      = new BackgroundTranslationBudgetPolicy( $job_repo );
		$job_concurrency = new BackgroundTranslationConcurrencyPolicy( $job_repo );
		$job_retry       = new BackgroundTranslationRetryPolicy();
		$job_provider    = new BackgroundTranslationJobProviderValidator( $provider_registry );
		$job_service     = new BackgroundTranslationJobService(
			$job_repo,
			$item_repo,
			$job_leases,
			$job_reconciler,
			$store,
			$assembler,
			$job_scheduler,
			$job_budget,
			$job_provider,
			$job_audit,
			$job_diagnostics,
			$surface_registry
		);
		$job_processor   = new BackgroundTranslationItemProcessor(
			$store,
			$translation,
			$glossary_service,
			$assembler,
			$job_retry,
			$surface_registry,
			$meta_registry
		);
		$job_worker      = new BackgroundTranslationWorker(
			$job_processor,
			$job_service,
			$job_repo,
			$item_repo,
			$job_leases,
			$job_reconciler,
			$job_retry,
			$job_budget,
			$job_scheduler,
			$job_provider,
			$job_audit,
			$job_diagnostics,
			$job_concurrency
		);
		$job_batches     = new BackgroundTranslationBatchCoordinator( $job_service, $job_repo, $job_scheduler );
		$job_scheduler->register_hooks( $job_worker, $job_leases );
		add_action(
			'init',
			static function () use ( $job_scheduler ): void {
				$job_scheduler->schedule_sweep();
			},
			20
		);

		( new JobsController(
			$job_service,
			$job_batches,
			$job_scheduler,
			$job_worker,
			$languages,
			new JobsViewModelSerializer(),
			$job_diagnostics,
			$job_concurrency,
			$assessment_assembler,
			$assembler,
			$surface_registry
		) )->register();

		// OTL.5: Jobs must be available to Operations bulk enqueue after Jobs stack exists.
		$workspace->set_jobs_service( $job_service );

		( new WorkspaceController(
			$workspace,
			new WorkspaceSegmentSerializer(),
			new WorkspacePageSummarySerializer(),
			new WorkspaceTranslationStatusSerializer(),
			new ReviewQueueItemSerializer()
		) )->register();

		$site_translate_admission = new SiteTranslateAdmissionService( $extractor, $settings );
		$site_translate_coverage  = new SiteTranslateCoverageService(
			$assembler,
			$extractor,
			$site_translate_admission,
			$meta_registry
		);
		$site_translate_batches   = new SiteTranslateBatchService(
			$job_batches,
			$job_repo,
			$site_translate_admission
		);
		$site_translate_routes    = new SiteTranslateLocalizedUrlBatchService(
			$store,
			$slug_candidates,
			$route_publication,
			$routing_capabilities
		);

		( new SiteTranslateController(
			$site_translate_coverage,
			$site_translate_admission,
			$site_translate_batches,
			$site_translate_routes,
			$languages,
			new JobsViewModelSerializer()
		) )->register();

		( new TermSlugController( $slug_candidates, $route_publication, $languages ) )->register();

		( new ProviderController( $provider_registry ) )->register();
		( new GlossaryController( $glossary_service ) )->register();

		( new AttributeRegistrar( $settings, $block_registry ) )->register();
		( new SavePipeline( $settings, $uuid_injector, $extractor ) )->register();

		$metrics = new BlockMetricsAggregator();
		$metrics->register();

		( new RolloutMetricsCollector() )->register();

		( new RolloutCacheInvalidationHooks(
			new RenderCacheInvalidationService( null, $cache ),
			$languages
		) )->register();

		( new ReviewEditInvalidationAuditBridge() )->register();
		( new PublicationEditInvalidationAuditBridge( $publication_audit ) )->register();

		// DEV → PROD translation promotion (ADR-0030). The capability-widening
		// filter must be available to REST, CLI and admin alike.
		( new PromotionCapabilities() )->register();

		$promotion_identities = new ObjectIdentityRepository();
		$promotion_log        = new PromotionLogRepository(
			(int) ( $settings->get()['promotion_log_retention'] ?? PromotionLogRepository::DEFAULT_RETENTION )
		);
		$promotion_audit      = new PromotionAudit();
		$promotion_export     = new TranslationExportService(
			$store,
			$languages,
			$promotion_identities,
			$promotion_log,
			$promotion_audit
		);
		$promotion_import     = new TranslationImportService(
			$store,
			$languages,
			new ObjectIdentityResolver( $promotion_identities ),
			new PromotionStateRepository(),
			$promotion_identities,
			$promotion_log,
			$promotion_audit,
			$settings,
			new TranslationConflictDetector(),
			new ReviewToken(),
			$review,
			$publication
		);
		( new PromotionController( $promotion_export, $promotion_import, $promotion_log ) )->register();

		// Stale invalidation is owned by RequestLocalInvalidationCoordinator
		// (save_post + Rank Math meta mark dirty; shutdown flush). Do not sync here.

		$seo_diagnostics = new SeoDiagnosticsService(
			$relationships,
			$languages,
			$rank_math_integration
		);

		if ( is_admin() ) {
			( new SettingsPage(
				$settings,
				$languages,
				$vault,
				$localized_urls_activation,
				$routing_admission,
				$frontier_repository
			) )->register();
			( new RolloutAdminPage() )->register();
			( new SeoDiagnosticsAdminPage( $seo_diagnostics ) )->register();
			( new Editor( $languages, $store, $extractor ) )->register();
			( new TranslatorWorkspace( $languages ) )->register();
			( new \AIMultilingual\Admin\PluginActionLinks() )->register();
			( new \AIMultilingual\Admin\PostListAiTranslateBulkAction() )->register();
			( new \AIMultilingual\Admin\AdminNavigation() )->register();
			( new GlossaryAdminPage( $languages ) )->register();
			( new TermLocalizedSlugAdmin( $languages ) )->register();
			( new \AIMultilingual\Admin\PromotionAdminPage( $languages ) )->register();

			// Bind-mount deployments update files in place and never fire the
			// activation hook, so schema drift has to be caught on its own.
			add_action(
				'admin_init',
				static function () {
					( new Migrator() )->maybe_migrate();
					PromotionCapabilities::provision();
				}
			);
			add_action( 'admin_notices', array( Migrator::class, 'render_blocked_notice' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$migration = new BlockIdentityMigration(
				$uuid_injector,
				$extractor,
				new Extractor(
					new Settings(
						array(
							'block_attr_registration_enabled' => true,
							'block_uuid_injection_enabled' => true,
							'block_extraction_enabled'     => true,
						)
					),
					$block_extractor
				),
				$store,
				new BlockMigrationLogger()
			);
			$health    = new BlockHealthService(
				$store,
				$extractor,
				new BlockIdentityAnalyzer( $block_registry )
			);

			Cli::register(
				$languages,
				$store,
				$extractor,
				$migration,
				$health,
				$metrics,
				$seo_diagnostics,
				$publication,
				$localized_urls_activation,
				$slug_route_activation,
				$routing_admission,
				new ReindexFrontierRepository()
			);
			ExtensionCli::register( $extension_registrar, $extension_diagnostics );
			RolloutCli::register();
			JobsCli::register( $job_service, $job_batches, $job_scheduler, $job_worker, $job_leases, $job_concurrency );
			\AIMultilingual\Promotion\PromotionCli::register( $promotion_export, $promotion_import, $promotion_log, $promotion_identities );
		}
	}

	/**
	 * Reloads settings from the database for long-lived processes and tests.
	 */
	public function reload_settings(): void {
		$this->settings?->reload();
	}

	/**
	 * Creates tables, seeds the default language and grants the capability.
	 *
	 * Also called directly by the integration bootstrap, which needs the schema
	 * in place before the first test transaction opens.
	 */
	public static function activate(): void {
		( new Migrator() )->migrate();

		$cache     = new Cache();
		$languages = new Languages( $cache );

		$languages->ensure_default( get_locale() );

		self::grant_capability();
		RolloutCapabilities::grant_default_roles();
		GlossaryCapabilities::grant_default_roles();
		ReviewCapabilities::grant_default_roles();
		JobsCapabilities::grant_default_roles();
		PromotionCapabilities::provision();
	}

	/**
	 * Adds the translation capability to the roles that should hold it.
	 */
	private static function grant_capability(): void {
		foreach ( self::CAPABLE_ROLES as $role_name ) {
			$role = get_role( $role_name );

			if ( null !== $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}
}
