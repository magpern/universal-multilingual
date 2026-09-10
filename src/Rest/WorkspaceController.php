<?php
/**
 * REST transport layer for the translator workspace.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Rest;

use AIMultilingual\Plugin;
use AIMultilingual\Rest\ViewModel\OperatorTranslationDetailSerializer;
use AIMultilingual\Rest\ViewModel\OperatorTranslationListItemSerializer;
use AIMultilingual\Rest\ViewModel\ReviewQueueItemSerializer;
use AIMultilingual\Rest\ViewModel\WorkspacePageSummarySerializer;
use AIMultilingual\Rest\ViewModel\WorkspaceSegmentSerializer;
use AIMultilingual\Rest\ViewModel\WorkspaceTranslationStatusSerializer;
use AIMultilingual\Workspace\Operator\OperationalAttention;
use AIMultilingual\Workspace\Review\ReviewBatchCoordinator;
use AIMultilingual\Workspace\Review\ReviewCapabilities;
use AIMultilingual\Workspace\Review\ReviewQAUnavailableException;
use AIMultilingual\Workspace\Review\ReviewWorkflowException;
use AIMultilingual\Workspace\Review\ReviewWorkflowService;
use AIMultilingual\Workspace\WorkspaceConflictException;
use AIMultilingual\Workspace\WorkspaceQAException;
use AIMultilingual\Workspace\WorkspaceTranslationConflictException;
use AIMultilingual\Workspace\WorkspaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Thin REST controller — validates, authorizes, delegates, serializes.
 */
final class WorkspaceController {

	public const REST_NAMESPACE = 'aiml/v1';

	public const REST_BASE = 'workspace';

	/**
	 * Application facade.
	 *
	 * @var WorkspaceService
	 */
	private WorkspaceService $workspace;

	/**
	 * Segment row serializer.
	 *
	 * @var WorkspaceSegmentSerializer
	 */
	private WorkspaceSegmentSerializer $segment_serializer;

	/**
	 * Page list serializer.
	 *
	 * @var WorkspacePageSummarySerializer
	 */
	private WorkspacePageSummarySerializer $page_serializer;

	/**
	 * Page status serializer.
	 *
	 * @var WorkspaceTranslationStatusSerializer
	 */
	private WorkspaceTranslationStatusSerializer $status_serializer;

	/**
	 * Review queue row serializer.
	 *
	 * @var ReviewQueueItemSerializer
	 */
	private ReviewQueueItemSerializer $review_queue_serializer;

	/**
	 * OTL operations list serializer.
	 *
	 * @var OperatorTranslationListItemSerializer
	 */
	private OperatorTranslationListItemSerializer $operations_list_serializer;

	/**
	 * OTL operations detail serializer.
	 *
	 * @var OperatorTranslationDetailSerializer
	 */
	private OperatorTranslationDetailSerializer $operations_detail_serializer;

	/**
	 * Builds the workspace REST controller.
	 *
	 * @param WorkspaceService                           $workspace                     Application facade.
	 * @param WorkspaceSegmentSerializer                 $segment_serializer            Segment serializer.
	 * @param WorkspacePageSummarySerializer             $page_serializer               Page serializer.
	 * @param WorkspaceTranslationStatusSerializer       $status_serializer             Status serializer.
	 * @param ReviewQueueItemSerializer                  $review_queue_serializer       Review queue serializer.
	 * @param OperatorTranslationListItemSerializer|null $operations_list_serializer   OTL list serializer.
	 * @param OperatorTranslationDetailSerializer|null   $operations_detail_serializer  OTL detail serializer.
	 */
	public function __construct(
		WorkspaceService $workspace,
		WorkspaceSegmentSerializer $segment_serializer,
		WorkspacePageSummarySerializer $page_serializer,
		WorkspaceTranslationStatusSerializer $status_serializer,
		ReviewQueueItemSerializer $review_queue_serializer,
		?OperatorTranslationListItemSerializer $operations_list_serializer = null,
		?OperatorTranslationDetailSerializer $operations_detail_serializer = null
	) {
		$this->workspace                    = $workspace;
		$this->segment_serializer           = $segment_serializer;
		$this->page_serializer              = $page_serializer;
		$this->status_serializer            = $status_serializer;
		$this->review_queue_serializer      = $review_queue_serializer;
		$this->operations_list_serializer   = $operations_list_serializer ?? new OperatorTranslationListItemSerializer();
		$this->operations_detail_serializer = $operations_detail_serializer ?? new OperatorTranslationDetailSerializer();
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
	 * Registers workspace routes under aiml/v1.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => array( $this, 'can_translate' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/review-queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_review_queue' ),
				'permission_callback' => array( $this, 'can_review_queue' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/review-diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_review_diagnostics' ),
				'permission_callback' => array( $this, 'can_review_queue' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/operations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_operations' ),
				'permission_callback' => array( $this, 'can_access_operations' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/operations/attention-counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_operations_attention_counts' ),
				'permission_callback' => array( $this, 'can_access_operations' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/operations/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'operations_bulk' ),
				'permission_callback' => array( $this, 'can_access_operations' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/operations/(?P<translation_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_operation' ),
				'permission_callback' => array( $this, 'can_access_operations' ),
				'args'                => array(
					'translation_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_segments' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/translation',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_translation' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/preview-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_preview_url' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/languages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_object_languages' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_batch' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'suggest_segment' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'profile'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/batch-review',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_review' ),
				'permission_callback' => array( $this, 'can_batch_review' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/review/approve-object',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_object' ),
				'permission_callback' => array( $this, 'can_review' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/submit-review',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit_review' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_review' ),
				'permission_callback' => array( $this, 'can_review' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject_review' ),
				'permission_callback' => array( $this, 'can_review' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_segment' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/slug/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_slug_candidate' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/slug/ensure',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ensure_slug_candidate' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/slug',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_slug_route_view' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'language' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_slug_candidate' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'language' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clear_slug_candidate' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id'  => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'language' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/slug/publish-route',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_prepared_route' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/unpublish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unpublish_segment' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)/publication-explain',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explain_publication' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'for_automatic' => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => static function ( $value ): bool {
							return (bool) $value;
						},
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/segments/(?P<segment_key>.+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_segment' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'segment_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'language'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/suggestions/accept',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'accept_suggestions' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/qa',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_qa_batch' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<post_id>\d+)/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Lists posts for the workspace picker.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_posts( WP_REST_Request $request ) {
		$result = $this->workspace->list_pages(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'language' => (string) $request->get_param( 'language' ),
			)
		);

		return $this->respond(
			array(
				'items'       => $this->page_serializer->many_to_arrays( $result['items'] ),
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
			)
		);
	}

	/**
	 * Returns workspace segments for one post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_segments( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$segments = $this->workspace->load_segments( $post, (int) $language->language_id );

		return $this->respond(
			array(
				'post_id'     => (int) $post->ID,
				'language_id' => (int) $language->language_id,
				'segments'    => $this->segment_serializer->many_to_arrays( $segments ),
				'status'      => $this->status_serializer->from_dto(
					$this->workspace->page_status_for_segments(
						$post,
						(int) $language->language_id,
						$segments
					)
				)->to_array(),
			)
		);
	}

	/**
	 * Deletes every stored translation of one post in one language ("start over").
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_translation( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$result   = $this->workspace->delete_translation( $post, (int) $language->language_id );
		$segments = $result['segments'];

		return $this->respond(
			array(
				'post_id'     => (int) $post->ID,
				'language_id' => (int) $language->language_id,
				'segments'    => $this->segment_serializer->many_to_arrays( $segments ),
				'status'      => $this->status_serializer->from_dto(
					$this->workspace->page_status_for_segments( $post, (int) $language->language_id, $segments )
				)->to_array(),
			)
		);
	}

	/**
	 * Returns page-level translation status.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		return $this->respond(
			$this->status_serializer->from_dto(
				$this->workspace->page_status( $post, (int) $language->language_id )
			)->to_array()
		);
	}

	/**
	 * MLW1a — object × language completeness for one content object across
	 * every eligible configured target language (ADR-0034 D2/D3). Every
	 * canonical `state` is server-computed; the client renders it verbatim.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_object_languages( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$result = $this->workspace->object_languages_summary( $post );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond( $result );
	}

	/**
	 * Returns the production preview URL.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preview_url( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language_code = sanitize_key( (string) $request->get_param( 'language' ) );
		$url           = $this->workspace->preview_url( $post, $language_code );
		if ( $url instanceof WP_Error ) {
			return $url;
		}

		return $this->respond(
			array(
				'url' => $url,
			)
		);
	}

	/**
	 * Saves one workspace segment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_segment( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}
		$key = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		if ( ! array_key_exists( 'expected_translation_hash', $params ) ) {
			return new WP_Error(
				'aiml_invalid_segment',
				__( 'expected_translation_hash is required.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		try {
			$dto = $this->workspace->save_segment(
				$post,
				(int) $language->language_id,
				$key,
				(string) ( $params['translated_text'] ?? '' ),
				(string) ( $params['source_hash'] ?? '' ),
				(string) ( $params['status'] ?? '' ),
				'',
				0,
				(string) $params['expected_translation_hash']
			);
		} catch ( WorkspaceConflictException $conflict ) {
			return new WP_REST_Response(
				array(
					'code'     => 'aiml_source_hash_mismatch',
					'message'  => $conflict->getMessage(),
					'segments' => $this->segment_serializer->many_to_arrays( $conflict->segments() ),
				),
				409
			);
		} catch ( WorkspaceTranslationConflictException $conflict ) {
			return new WP_REST_Response(
				array(
					'code'     => 'aiml_translation_hash_mismatch',
					'message'  => $conflict->getMessage(),
					'segments' => $this->segment_serializer->many_to_arrays( $conflict->segments() ),
				),
				409
			);
		} catch ( WorkspaceQAException $qa_exception ) {
			return new WP_REST_Response(
				array(
					'code'    => 'aiml_qa_blocked',
					'message' => $qa_exception->getMessage(),
					'qa'      => $qa_exception->qa()->to_array(),
				),
				422
			);
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error(
				'aiml_invalid_segment',
				$exception->getMessage(),
				array( 'status' => 422 )
			);
		}

		return $this->respond( $this->segment_serializer->from_dto( $dto )->to_array() );
	}

	/**
	 * Requests AI/TM suggestions for one segment without persisting.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest_segment( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}

		$key     = rawurldecode( (string) $request->get_param( 'segment_key' ) );
		$profile = sanitize_key( (string) ( $params['profile'] ?? $request->get_param( 'profile' ) ?? '' ) );

		try {
			$dto = $this->workspace->request_suggestions(
				$post,
				(int) $language->language_id,
				$key,
				$profile
			);
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error(
				'aiml_invalid_segment',
				$exception->getMessage(),
				array( 'status' => 422 )
			);
		}

		return $this->respond( $this->segment_serializer->from_dto( $dto )->to_array() );
	}

	/**
	 * Accepts exact TM suggestions for selected segments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept_suggestions( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}
		$keys = is_array( $params['segment_keys'] ?? null ) ? $params['segment_keys'] : array();

		$result = $this->workspace->accept_tm_exact_batch(
			$post,
			(int) $language->language_id,
			array_map( 'strval', $keys )
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond(
			array(
				'status'   => $result['status'],
				'segments' => $this->segment_serializer->many_to_arrays( $result['segments'] ),
				'errors'   => $result['errors'],
			)
		);
	}

	/**
	 * Runs read-only batch QA for selected segments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_qa_batch( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}
		$keys = is_array( $params['segment_keys'] ?? null ) ? $params['segment_keys'] : array();

		$result = $this->workspace->qa_batch(
			$post,
			(int) $language->language_id,
			array_map( 'strval', $keys )
		);

		return $this->respond(
			array(
				'segments' => $this->segment_serializer->many_to_arrays( $result['segments'] ),
				'summary'  => $result['summary'],
			)
		);
	}

	/**
	 * Saves multiple workspace segments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_batch( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}
		$items = is_array( $params['segments'] ?? null ) ? $params['segments'] : array();

		$result = $this->workspace->save_batch( $post, (int) $language->language_id, $items );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond(
			array(
				'status'   => $result['status'],
				'segments' => $this->segment_serializer->many_to_arrays( $result['segments'] ),
				'errors'   => $result['errors'],
			)
		);
	}

	/**
	 * Submits (or resubmits) one segment for review.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_review( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = $this->body_params( $request );
		$key    = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		try {
			$dto = $this->workspace->submit_review(
				$post,
				(int) $language->language_id,
				$key,
				get_current_user_id(),
				$this->nullable_string( $params['expected_review_status'] ?? null )
			);
		} catch ( ReviewWorkflowException $exception ) {
			return $this->review_error_response( $exception );
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error(
				'aiml_invalid_segment',
				$exception->getMessage(),
				array( 'status' => 422 )
			);
		}

		return $this->respond( $this->segment_serializer->from_dto( $dto )->to_array() );
	}

	/**
	 * Approves one pending review.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_review( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = $this->body_params( $request );
		$key    = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		try {
			$dto = $this->workspace->approve_review(
				$post,
				(int) $language->language_id,
				$key,
				get_current_user_id(),
				$this->nullable_string( $params['expected_review_status'] ?? null ),
				$this->nullable_string( $params['submitted_translation_hash'] ?? null )
			);
		} catch ( ReviewWorkflowException $exception ) {
			return $this->review_error_response( $exception );
		} catch ( WorkspaceQAException $qa_exception ) {
			return new WP_REST_Response(
				array(
					'code'    => 'aiml_qa_blocked',
					'message' => $qa_exception->getMessage(),
					'qa'      => $qa_exception->qa()->to_array(),
				),
				422
			);
		} catch ( ReviewQAUnavailableException $exception ) {
			return new WP_Error(
				'aiml_review_qa_unavailable',
				$exception->getMessage(),
				array( 'status' => 503 )
			);
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error(
				'aiml_invalid_segment',
				$exception->getMessage(),
				array( 'status' => 422 )
			);
		}

		return $this->respond( $this->segment_serializer->from_dto( $dto )->to_array() );
	}

	/**
	 * Rejects one pending review with a required reason.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_review( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = $this->body_params( $request );
		$key    = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		try {
			$dto = $this->workspace->reject_review(
				$post,
				(int) $language->language_id,
				$key,
				get_current_user_id(),
				(string) ( $params['reason'] ?? '' ),
				$this->nullable_string( $params['expected_review_status'] ?? null ),
				$this->nullable_string( $params['submitted_translation_hash'] ?? null )
			);
		} catch ( ReviewWorkflowException $exception ) {
			return $this->review_error_response( $exception );
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error(
				'aiml_invalid_segment',
				$exception->getMessage(),
				array( 'status' => 422 )
			);
		}

		return $this->respond( $this->segment_serializer->from_dto( $dto )->to_array() );
	}

	/**
	 * Publishes one segment when eligible (TI.7 manual path).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish_segment( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = $this->body_params( $request );
		$key    = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		$result = $this->workspace->publish_segment(
			$post,
			(int) $language->language_id,
			$key,
			get_current_user_id(),
			$this->nullable_string( $params['expected_publish_status'] ?? null )
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$payload = $this->segment_serializer->from_dto( $result )->to_array();
		if ( isset( $result['publication_result'] ) && is_array( $result['publication_result'] ) ) {
			$payload['publication_result'] = $result['publication_result'];
		}

		return $this->respond( $payload );
	}

	/**
	 * Returns slug/route sync view (MSEO.1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_slug_route_view( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$result = $this->workspace->slug_route_view( $post, (int) $language->language_id );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Generates a slug candidate (MSEO.1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_slug_candidate( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$result = $this->workspace->generate_slug_candidate( $post, (int) $language->language_id );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Ensures a localized URL slug follows the translation (auto-generate).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ensure_slug_candidate( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$result = $this->workspace->ensure_slug_candidate( $post, (int) $language->language_id );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Saves a manual slug candidate (MSEO.1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_slug_candidate( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$params    = $this->body_params( $request );
		$candidate = (string) ( $params['slug_candidate'] ?? $params['translated_text'] ?? '' );
		$result    = $this->workspace->save_slug_candidate( $post, (int) $language->language_id, $candidate );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Clears the slug candidate (MSEO.1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_slug_candidate( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$result = $this->workspace->clear_slug_candidate( $post, (int) $language->language_id );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Publishes the prepared route (MSEO.1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish_prepared_route( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}
		$result = $this->workspace->publish_prepared_route( $post, (int) $language->language_id, get_current_user_id() );

		return $result instanceof WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * Unpublishes one segment (TI.7 manual path).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unpublish_segment( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$key = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		$result = $this->workspace->unpublish_segment(
			$post,
			(int) $language->language_id,
			$key,
			get_current_user_id()
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$payload = $this->segment_serializer->from_dto( $result )->to_array();
		if ( isset( $result['publication_result'] ) && is_array( $result['publication_result'] ) ) {
			$payload['publication_result'] = $result['publication_result'];
		}

		return $this->respond( $payload );
	}

	/**
	 * Explains publication eligibility without mutation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function explain_publication( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$key = rawurldecode( (string) $request->get_param( 'segment_key' ) );

		$result = $this->workspace->explain_publication(
			$post,
			(int) $language->language_id,
			$key,
			(bool) $request->get_param( 'for_automatic' )
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond( $result );
	}

	/**
	 * Applies one review action to multiple segments (bounded, partial success).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch_review( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = $this->body_params( $request );
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		$items  = is_array( $params['segments'] ?? null ) ? $params['segments'] : array();
		$reason = (string) ( $params['reason'] ?? '' );

		$result = $this->workspace->batch_review(
			$post,
			(int) $language->language_id,
			$action,
			$items,
			get_current_user_id(),
			$reason
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond(
			array(
				'status'   => $result['status'],
				'segments' => $this->segment_serializer->many_to_arrays( $result['segments'] ),
				'errors'   => $result['errors'],
			)
		);
	}

	/**
	 * Returns a filtered, paginated review queue (Store view; never persisted).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_review_queue( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$args     = array(
			'post_id'  => (int) $request->get_param( 'post_id' ),
			'language' => sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) ),
			'page'     => $page > 0 ? $page : 1,
			'per_page' => $per_page > 0 ? $per_page : 20,
		);

		$review_status = $request->get_param( 'review_status' );
		if ( null !== $review_status && '' !== $review_status ) {
			$args['review_status'] = sanitize_key( (string) $review_status );
		}

		$result = $this->workspace->review_queue_grouped( $args );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$objects = array();
		foreach ( $result['objects'] as $group ) {
			$group['items'] = $this->review_queue_serializer->many_to_arrays( $group['items'] );
			$objects[]      = $group;
		}

		return $this->respond(
			array(
				'items'    => $this->review_queue_serializer->many_to_arrays( $result['items'] ),
				'objects'  => $objects,
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
			)
		);
	}

	/**
	 * Approves every currently pending segment for one object + language (RVQ1).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_object( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$result = $this->workspace->approve_object( $post, (int) $language->language_id, get_current_user_id() );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$result['approved'] = $this->segment_serializer->many_to_arrays( $result['approved'] );

		return $this->respond( $result );
	}

	/**
	 * OTL operations list (cheap axis filters; optional attention preset; no QA/assessment/explain).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_operations( WP_REST_Request $request ) {
		$language = sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) );
		if ( '' === $language ) {
			return new WP_Error(
				'aiml_missing_language',
				__( 'language is required.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$lang = $this->workspace->resolve_language_for_operations( $language );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}

		$args = array(
			'language_id' => (int) $lang->language_id,
			'page'        => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'per_page'    => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
		);

		$attention = $request->get_param( 'attention' );
		if ( null !== $attention && '' !== (string) $attention ) {
			$attention_filters = OperationalAttention::preset_to_store_filters( (string) $attention );
			if ( $attention_filters instanceof WP_Error ) {
				return $attention_filters;
			}
			$args = array_merge( $args, $attention_filters );
		}

		$axis_conflict = false;
		foreach ( array( 'status', 'review_status', 'publish_status', 'source_type' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$sanitized = sanitize_key( (string) $value );
			if ( isset( $args[ $key ] ) && (string) $args[ $key ] !== $sanitized ) {
				$axis_conflict = true;
			}
			$args[ $key ] = $sanitized;
		}

		if ( null !== $request->get_param( 'is_stale' ) && '' !== $request->get_param( 'is_stale' ) ) {
			$raw   = $request->get_param( 'is_stale' );
			$stale = in_array( (string) $raw, array( '1', 'true', 'yes' ), true );
			if ( array_key_exists( 'is_stale', $args ) && (bool) $args['is_stale'] !== $stale ) {
				$axis_conflict = true;
			}
			$args['is_stale'] = $stale;
		}

		$source_id = (int) $request->get_param( 'source_id' );
		if ( $source_id > 0 ) {
			$args['source_id'] = $source_id;
		}

		if ( $axis_conflict ) {
			return $this->respond(
				array(
					'items'    => array(),
					'total'    => 0,
					'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
					'per_page' => max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) ),
				)
			);
		}

		$result = $this->workspace->list_operations( $args );

		return $this->respond(
			array(
				'items'    => $this->operations_list_serializer->many_to_arrays( $result['items'] ),
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
			)
		);
	}

	/**
	 * OTL.1 operational attention counts (same auth/visibility as operations list).
	 *
	 * Counts are language-wide independent buckets (not narrowed by non-attention
	 * axis filters on the list). `total` is the unfiltered list inventory.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_operations_attention_counts( WP_REST_Request $request ) {
		$language = sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) );
		if ( '' === $language ) {
			return new WP_Error(
				'aiml_missing_language',
				__( 'language is required.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$lang = $this->workspace->resolve_language_for_operations( $language );
		if ( $lang instanceof WP_Error ) {
			return $lang;
		}

		return $this->respond(
			$this->workspace->operations_attention_counts( (int) $lang->language_id )
		);
	}

	/**
	 * OTL.5 bounded Operations bulk mutations.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function operations_bulk( WP_REST_Request $request ) {
		$params = $this->body_params( $request );
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );
		$raw    = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();

		$items = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[] = array(
				'translation_id'          => (int) ( $row['translation_id'] ?? 0 ),
				'expected_publish_status' => isset( $row['expected_publish_status'] )
					? (string) $row['expected_publish_status']
					: null,
				'source_hash'             => isset( $row['source_hash'] ) ? (string) $row['source_hash'] : null,
				'translation_hash'        => isset( $row['translation_hash'] ) ? (string) $row['translation_hash'] : null,
			);
		}

		$result = $this->workspace->operations_bulk( $action, $items, get_current_user_id() );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond( $result );
	}

	/**
	 * OTL.0 operations detail by translation_id.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_operation( WP_REST_Request $request ) {
		$translation_id = (int) $request->get_param( 'translation_id' );
		$result         = $this->workspace->get_operation( $translation_id );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond( $this->operations_detail_serializer->to_array( $result ) );
	}

	/**
	 * Returns bounded, low-cardinality Review Workflow diagnostics
	 * (ADR-0015 §13). Never translation content — counts and timings only.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_review_diagnostics( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'post_id'  => (int) $request->get_param( 'post_id' ),
			'language' => sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) ),
		);

		return $this->respond( $this->workspace->review_diagnostics( $args ) );
	}

	/**
	 * Invokes auto-translate for selected segments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function translate( WP_REST_Request $request ) {
		$post = $this->resolve_post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$language = $this->resolve_language_param( $request );
		if ( $language instanceof WP_Error ) {
			return $language;
		}

		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}
		$keys = is_array( $params['segment_keys'] ?? null ) ? array_map( 'strval', $params['segment_keys'] ) : array();
		$mode = (string) ( $params['mode'] ?? 'sync' );

		$expected_hashes = array();
		if ( is_array( $params['expected_translation_hashes'] ?? null ) ) {
			foreach ( $params['expected_translation_hashes'] as $segment_key => $hash ) {
				$expected_hashes[ (string) $segment_key ] = (string) $hash;
			}
		}

		$result = $this->workspace->translate( $post, (int) $language->language_id, $keys, $mode, $expected_hashes );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->respond(
			array(
				'status'   => $result['status'],
				'job_id'   => $result['job_id'] ?? null,
				'segments' => $this->segment_serializer->many_to_arrays( $result['segments'] ),
				'errors'   => $result['errors'],
				'skipped'  => $result['skipped'] ?? array(),
			)
		);
	}

	/**
	 * Wraps a ViewModel payload in a versioned REST response.
	 *
	 * @param array<string, mixed> $payload Response payload.
	 * @return WP_REST_Response
	 */
	private function respond( array $payload ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'X-AIML-Workspace-Api-Version', '1' );

		return $response;
	}

	/**
	 * Checks the workspace capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_translate() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to access the translator workspace.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks workspace and edit_post capabilities.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function can_edit_post( WP_REST_Request $request ) {
		$allowed = $this->can_translate();
		if ( true !== $allowed ) {
			return $allowed;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'aiml_invalid_post',
				__( 'Invalid post id.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to edit this post.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks the review capability and edit_post for the target post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function can_review( WP_REST_Request $request ) {
		if ( ! current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to review translations.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'aiml_invalid_post',
				__( 'Invalid post id.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to edit this post.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks the review capability only, for the global (post-agnostic) queue.
	 *
	 * @return bool|WP_Error
	 */
	public function can_review_queue() {
		if ( current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS ) ) {
			return true;
		}

		return new WP_Error(
			'aiml_forbidden',
			__( 'You do not have permission to view the review queue.', 'universal-multilingual' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * OTL.0 operations access: translate OR review capability.
	 *
	 * @return bool|WP_Error
	 */
	public function can_access_operations() {
		if ( current_user_can( Plugin::CAPABILITY ) || current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS ) ) {
			return true;
		}

		return new WP_Error(
			'aiml_forbidden',
			__( 'You do not have permission to access translation operations.', 'universal-multilingual' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Checks the capability matching the batch's declared action.
	 *
	 * One `action` applies to the whole batch, so the required capability
	 * (translate for submit, review for approve/reject) is resolved once here
	 * rather than per item — mirroring how `can_edit_post()` already gates
	 * `segments/batch` as a single request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function can_batch_review( WP_REST_Request $request ) {
		$params = $this->body_params( $request );
		$action = sanitize_key( (string) ( $params['action'] ?? '' ) );

		if ( ReviewBatchCoordinator::ACTION_SUBMIT === $action ) {
			return $this->can_edit_post( $request );
		}

		if ( in_array( $action, array( ReviewBatchCoordinator::ACTION_APPROVE, ReviewBatchCoordinator::ACTION_REJECT ), true ) ) {
			return $this->can_review( $request );
		}

		return new WP_Error(
			'aiml_invalid_review_action',
			__( 'Unknown batch review action.', 'universal-multilingual' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Maps a domain review exception to a stable REST error response.
	 *
	 * @param ReviewWorkflowException $exception Domain exception.
	 * @return WP_REST_Response
	 */
	private function review_error_response( ReviewWorkflowException $exception ): WP_REST_Response {
		$status = $exception->getCode();
		if ( $status <= 0 ) {
			$status = $this->map_review_error_status( $exception->get_error_code() );
		}

		return new WP_REST_Response(
			array(
				'code'    => $exception->get_error_code(),
				'message' => $exception->getMessage(),
				'context' => $exception->get_context(),
			),
			$status
		);
	}

	/**
	 * Maps a stable review error code to its documented HTTP status
	 * (ADR-0015 §4.2) when the exception did not carry one explicitly.
	 *
	 * @param string $error_code Stable error code.
	 */
	private function map_review_error_status( string $error_code ): int {
		switch ( $error_code ) {
			case ReviewWorkflowService::CODE_CONFLICT:
				return 409;
			case ReviewWorkflowService::CODE_NO_TRANSLATION:
				return 404;
			case ReviewWorkflowService::CODE_PERMISSION_DENIED:
				return 403;
			case ReviewWorkflowService::CODE_NOT_PENDING:
			case ReviewWorkflowService::CODE_ALREADY_PENDING:
			case ReviewWorkflowService::CODE_TRANSLATION_CHANGED:
			case ReviewWorkflowService::CODE_REASON_REQUIRED:
			case ReviewWorkflowService::CODE_INVALID_TRANSITION:
			case ReviewWorkflowService::CODE_INVALID_TRANSLATION:
			case ReviewWorkflowService::CODE_QA_BLOCKED:
				return 422;
			default:
				return 500;
		}
	}

	/**
	 * Reads JSON body params, falling back to form-encoded body params.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, mixed>
	 */
	private function body_params( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}

		return $params;
	}

	/**
	 * Normalizes an optional client-supplied string, treating '' as absent.
	 *
	 * @param mixed $value Raw request value.
	 */
	private function nullable_string( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Resolves the target post for a REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_Post|WP_Error
	 */
	private function resolve_post( WP_REST_Request $request ) {
		return $this->workspace->get_post( (int) $request->get_param( 'post_id' ) );
	}

	/**
	 * Resolves the target language for a REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return object|WP_Error
	 */
	private function resolve_language_param( WP_REST_Request $request ) {
		$code     = sanitize_key( (string) $request->get_param( 'language' ) );
		$language = $this->workspace->resolve_language( $code );
		if ( null === $language ) {
			return new WP_Error(
				'aiml_invalid_language',
				__( 'Unknown language code.', 'universal-multilingual' ),
				array( 'status' => 404 )
			);
		}

		return $language;
	}
}
