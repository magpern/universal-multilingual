<?php
/**
 * Prepared route publication lifecycle (MSEO.1–MSEO.3).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Routing;

use AIMultilingual\Database\Schema;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Store;
use AIMultilingual\Translation\TermExtractor;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Sole FORMAT_SLUG publication + prepared-route authority.
 */
final class RoutePublicationService {

	public const HISTORY_MAX = 5;

	/**
	 * Constructs the service.
	 *
	 * @param Store                           $store         Store.
	 * @param PublicationService              $publication   Publication service.
	 * @param SlugRouteRepository             $routes        Routes.
	 * @param RouteHistoryRepository          $history       History.
	 * @param PathCanonicalizer               $paths         Paths.
	 * @param CanonicalPathCollisionChecker   $collisions    Collisions.
	 * @param ObjectLanguagePublicEligibility $eligibility   Eligibility.
	 * @param RoutingCapabilityRegistry       $capabilities  Capabilities.
	 * @param HierarchyPathBuilder|null       $hierarchy     Hierarchy path authority.
	 * @param RoutingCapabilityAdmission|null $admission     Public admission (optional).
	 * @param WooProductPathBuilder|null      $woo_products  Woo %product_cat% path authority (MSEO.4).
	 */
	public function __construct(
		private Store $store,
		private PublicationService $publication,
		private SlugRouteRepository $routes,
		private RouteHistoryRepository $history,
		private PathCanonicalizer $paths,
		private CanonicalPathCollisionChecker $collisions,
		private ObjectLanguagePublicEligibility $eligibility,
		private RoutingCapabilityRegistry $capabilities,
		private ?HierarchyPathBuilder $hierarchy = null,
		private ?RoutingCapabilityAdmission $admission = null,
		private ?WooProductPathBuilder $woo_products = null
	) {
	}

	/**
	 * Atomically publishes the slug candidate into a prepared active route.
	 *
	 * @param WP_Post $post        Source post.
	 * @param int     $language_id Language id.
	 * @param int     $user_id     Acting user.
	 * @return array<string, mixed>|WP_Error
	 */
	public function publish_route( WP_Post $post, int $language_id, int $user_id = 0 ) {
		$eligible = $this->eligibility->is_route_publishable( $post, $language_id );
		if ( $eligible instanceof WP_Error ) {
			return $eligible;
		}

		$boundary  = $this->store->begin_route_boundary();
		$committed = false;

		try {
			$candidate = $this->store->lock_segment_for_update(
				Store::SOURCE_POST,
				(int) $post->ID,
				$language_id,
				Extractor::FIELD_SLUG,
				Extractor::FIELD_SLUG
			);
			if ( null === $candidate ) {
				return new WP_Error( 'aiml_slug_candidate_missing', __( 'Slug candidate is missing.', 'universal-multilingual' ) );
			}

			$current = $this->routes->lock_by_object( Store::SOURCE_POST, (int) $post->ID, $language_id );

			$leaf   = trim( (string) ( $candidate->translated_text ?? '' ) );
			$origin = (string) ( $candidate->slug_origin ?? '' );
			if ( '' === $leaf || Store::STATUS_MISSING === (string) ( $candidate->status ?? '' ) ) {
				return new WP_Error( 'aiml_slug_candidate_missing', __( 'Slug candidate is missing.', 'universal-multilingual' ) );
			}

			$source_path = $this->source_path_for_post( $post );
			if ( $source_path instanceof WP_Error ) {
				return $source_path;
			}
			$source_path_str = $source_path->to_string();

			$publish_status = (string) ( $candidate->publish_status ?? Store::PUBLISH_UNPUBLISHED );
			$idempotent     = Store::PUBLISH_PUBLISHED === $publish_status
				&& null !== $current
				&& 'active' === (string) ( $current->route_status ?? '' );

			if ( $idempotent ) {
				$pub = $this->publication->publish_under_route_authority(
					Store::SOURCE_POST,
					(int) $post->ID,
					$language_id,
					Extractor::FIELD_SLUG,
					$candidate,
					$user_id
				);
				if ( $pub instanceof WP_Error ) {
					return $pub;
				}

				$this->store->commit_route_boundary( $boundary );
				$committed = true;

				$this->signal_hierarchy_reindex( Store::SOURCE_POST, (int) $post->ID, $post );

				return $this->result_payload( $post, $language_id, true );
			}

			$resolved = $this->resolve_post_effective_path(
				$post,
				$language_id,
				$source_path_str,
				$leaf,
				'' !== $origin ? $origin : 'generated'
			);
			if ( $resolved instanceof WP_Error ) {
				return $resolved;
			}

			$effective_leaf = $resolved['leaf'];
			$effective_path = $resolved['path'];

			$reuse = $this->reconcile_own_history( $language_id, $effective_path, Store::SOURCE_POST, (int) $post->ID );
			if ( $reuse instanceof WP_Error ) {
				return $reuse;
			}

			$check = $this->collisions->assert_available(
				$language_id,
				$effective_path,
				Store::SOURCE_POST,
				(int) $post->ID
			);
			if ( true !== $check ) {
				return $check;
			}

			$pub = $this->publication->publish_under_route_authority(
				Store::SOURCE_POST,
				(int) $post->ID,
				$language_id,
				Extractor::FIELD_SLUG,
				$candidate,
				$user_id
			);
			if ( $pub instanceof WP_Error ) {
				return $pub;
			}

			$history_err = $this->archive_prior_path_if_changed(
				$current,
				$effective_path,
				$language_id,
				Store::SOURCE_POST,
				(int) $post->ID,
				(string) $post->post_type
			);
			if ( $history_err instanceof WP_Error ) {
				return $history_err;
			}

			$now   = current_time( 'mysql', true );
			$saved = $this->routes->save(
				new RouteRecord(
					$language_id,
					Store::SOURCE_POST,
					(int) $post->ID,
					(string) $post->post_type,
					$source_path,
					$effective_path,
					$effective_leaf,
					'',
					'' !== $origin ? $origin : 'generated',
					'active',
					$now
				)
			);
			if ( $saved instanceof WP_Error ) {
				return $saved;
			}

			$this->history->delete_oldest_beyond( Store::SOURCE_POST, (int) $post->ID, $language_id, self::HISTORY_MAX );

			$this->store->commit_route_boundary( $boundary );
			$committed = true;

			$this->signal_hierarchy_reindex( Store::SOURCE_POST, (int) $post->ID, $post );

			return $this->result_payload( $post, $language_id, false );
		} finally {
			if ( ! $committed ) {
				$this->store->rollback_route_boundary( $boundary );
			}
		}
	}

	/**
	 * Atomically publishes a term slug candidate under term-compat authority.
	 *
	 * @param WP_Term $term        Source term.
	 * @param int     $language_id Language id.
	 * @param int     $user_id     Acting user.
	 * @return array<string, mixed>|WP_Error
	 */
	public function publish_term_route( WP_Term $term, int $language_id, int $user_id = 0 ) {
		$eligible = $this->eligibility->is_term_route_publishable( $term, $language_id );
		if ( $eligible instanceof WP_Error ) {
			return $eligible;
		}

		$ref = array(
			'term_id'            => (int) $term->term_id,
			'taxonomy'           => (string) $term->taxonomy,
			'language_id'        => $language_id,
			'native_field_key'   => TermExtractor::FIELD_SLUG,
			'native_segment_key' => TermExtractor::FIELD_SLUG,
		);

		return $this->store->with_term_compat_authority(
			$ref,
			function () use ( $term, $language_id, $user_id ) {
				$boundary  = $this->store->begin_route_boundary();
				$committed = false;

				try {
					$candidate = $this->store->lock_segment_for_update(
						Store::SOURCE_TERM,
						(int) $term->term_id,
						$language_id,
						TermExtractor::FIELD_SLUG,
						TermExtractor::FIELD_SLUG
					);
					if ( null === $candidate ) {
						return new WP_Error( 'aiml_slug_candidate_missing', __( 'Slug candidate is missing.', 'universal-multilingual' ) );
					}

					$current = $this->routes->lock_by_object( Store::SOURCE_TERM, (int) $term->term_id, $language_id );

					$leaf   = trim( (string) ( $candidate->translated_text ?? '' ) );
					$origin = (string) ( $candidate->slug_origin ?? '' );
					if ( '' === $leaf || Store::STATUS_MISSING === (string) ( $candidate->status ?? '' ) ) {
						return new WP_Error( 'aiml_slug_candidate_missing', __( 'Slug candidate is missing.', 'universal-multilingual' ) );
					}

					$source_path = $this->source_path_for_term( $term );
					if ( $source_path instanceof WP_Error ) {
						return $source_path;
					}

					$publish_status = (string) ( $candidate->publish_status ?? Store::PUBLISH_UNPUBLISHED );
					$idempotent     = Store::PUBLISH_PUBLISHED === $publish_status
						&& null !== $current
						&& 'active' === (string) ( $current->route_status ?? '' );

					if ( $idempotent ) {
						$pub = $this->publication->publish_under_route_authority(
							Store::SOURCE_TERM,
							(int) $term->term_id,
							$language_id,
							TermExtractor::FIELD_SLUG,
							$candidate,
							$user_id
						);
						if ( $pub instanceof WP_Error ) {
							return $pub;
						}

						$this->store->commit_route_boundary( $boundary );
						$committed = true;

						return $this->term_result_payload( $term, $language_id, true );
					}

					$resolved = $this->resolve_term_effective_path(
						$term,
						$language_id,
						$leaf,
						'' !== $origin ? $origin : 'generated'
					);
					if ( $resolved instanceof WP_Error ) {
						return $resolved;
					}

					$effective_leaf = $resolved['leaf'];
					$effective_path = $resolved['path'];

					$reuse = $this->reconcile_own_history( $language_id, $effective_path, Store::SOURCE_TERM, (int) $term->term_id );
					if ( $reuse instanceof WP_Error ) {
						return $reuse;
					}

					$check = $this->collisions->assert_available(
						$language_id,
						$effective_path,
						Store::SOURCE_TERM,
						(int) $term->term_id
					);
					if ( true !== $check ) {
						return $check;
					}

					$pub = $this->publication->publish_under_route_authority(
						Store::SOURCE_TERM,
						(int) $term->term_id,
						$language_id,
						TermExtractor::FIELD_SLUG,
						$candidate,
						$user_id
					);
					if ( $pub instanceof WP_Error ) {
						return $pub;
					}

					$history_err = $this->archive_prior_path_if_changed(
						$current,
						$effective_path,
						$language_id,
						Store::SOURCE_TERM,
						(int) $term->term_id,
						(string) $term->taxonomy
					);
					if ( $history_err instanceof WP_Error ) {
						return $history_err;
					}

					$now   = current_time( 'mysql', true );
					$saved = $this->routes->save(
						new RouteRecord(
							$language_id,
							Store::SOURCE_TERM,
							(int) $term->term_id,
							(string) $term->taxonomy,
							$source_path,
							$effective_path,
							$effective_leaf,
							'',
							'' !== $origin ? $origin : 'generated',
							'active',
							$now
						)
					);
					if ( $saved instanceof WP_Error ) {
						return $saved;
					}

					$this->history->delete_oldest_beyond( Store::SOURCE_TERM, (int) $term->term_id, $language_id, self::HISTORY_MAX );

					$this->store->commit_route_boundary( $boundary );
					$committed = true;

					$this->signal_hierarchy_reindex( Store::SOURCE_TERM, (int) $term->term_id );

					return $this->term_result_payload( $term, $language_id, false );
				} finally {
					if ( ! $committed ) {
						$this->store->rollback_route_boundary( $boundary );
					}
				}
			}
		);
	}

	/**
	 * Rematerializes an existing active route path from current hierarchy (maintenance).
	 *
	 * Does not mutate slug candidates. On collision, returns WP_Error without writing.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $language_id Language id.
	 * @return object|null|WP_Error Updated route, null when no active route, or error.
	 */
	public function rematerialize_route( string $source_type, int $source_id, int $language_id ) {
		$boundary  = $this->store->begin_route_boundary();
		$committed = false;

		try {
			$current = $this->routes->lock_by_object( $source_type, $source_id, $language_id );
			if ( null === $current || 'active' !== (string) ( $current->route_status ?? '' ) ) {
				$this->store->commit_route_boundary( $boundary );
				$committed = true;

				return null;
			}

			$leaf   = (string) ( $current->localized_slug ?? '' );
			$origin = (string) ( $current->slug_origin ?? 'generated' );
			if ( '' === $leaf ) {
				$parts = array_values( array_filter( explode( '/', trim( (string) ( $current->localized_path ?? '' ), '/' ) ) ) );
				$last  = end( $parts );
				$leaf  = is_string( $last ) ? $last : '';
			}
			if ( '' === $leaf ) {
				return new WP_Error( 'aiml_slug_empty_leaf', __( 'Active route has no localized leaf.', 'universal-multilingual' ) );
			}

			if ( Store::SOURCE_POST === $source_type ) {
				$post = get_post( $source_id );
				if ( ! $post instanceof WP_Post ) {
					return new WP_Error( 'aiml_slug_source_missing', __( 'Source post is missing.', 'universal-multilingual' ) );
				}
				$source_path = $this->source_path_for_post( $post );
				if ( $source_path instanceof WP_Error ) {
					return $source_path;
				}
				$new_path = $this->localized_path_for_post( $post, $language_id, $leaf );
				if ( $new_path instanceof WP_Error ) {
					return $new_path;
				}
				$subtype = (string) $post->post_type;
			} elseif ( Store::SOURCE_TERM === $source_type ) {
				$term = get_term( $source_id );
				if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
					return new WP_Error( 'aiml_slug_source_missing', __( 'Source term is missing.', 'universal-multilingual' ) );
				}
				$source_path = $this->source_path_for_term( $term );
				if ( $source_path instanceof WP_Error ) {
					return $source_path;
				}
				$new_path = $this->localized_path_for_term( $term, $language_id, $leaf );
				if ( $new_path instanceof WP_Error ) {
					return $new_path;
				}
				$subtype = (string) $term->taxonomy;
			} else {
				return new WP_Error( 'aiml_slug_unsupported_source', __( 'Unsupported source type.', 'universal-multilingual' ) );
			}

			$prior = (string) ( $current->localized_path ?? '' );
			if ( $prior === $new_path->to_string() ) {
				$prior_source = (string) ( $current->source_path ?? '' );
				if ( $prior_source === $source_path->to_string() ) {
					// Strict no-op: unchanged effective + source path.
					$this->store->commit_route_boundary( $boundary );
					$committed = true;

					return $current;
				}

				// Refresh source_path only when localized path is unchanged.
				$saved = $this->routes->save(
					new RouteRecord(
						$language_id,
						$source_type,
						$source_id,
						$subtype,
						$source_path,
						$new_path,
						$leaf,
						(string) ( $current->route_namespace ?? '' ),
						$origin,
						'active',
						isset( $current->activated_at ) ? (string) $current->activated_at : null
					)
				);
				if ( $saved instanceof WP_Error ) {
					return $saved;
				}
				$this->store->commit_route_boundary( $boundary );
				$committed = true;

				return $saved;
			}

			$reuse = $this->reconcile_own_history( $language_id, $new_path, $source_type, $source_id );
			if ( $reuse instanceof WP_Error ) {
				return $reuse;
			}

			$check = $this->collisions->assert_available( $language_id, $new_path, $source_type, $source_id );
			if ( true !== $check ) {
				return $check;
			}

			$history_err = $this->archive_prior_path_if_changed(
				$current,
				$new_path,
				$language_id,
				$source_type,
				$source_id,
				$subtype
			);
			if ( $history_err instanceof WP_Error ) {
				return $history_err;
			}

			$saved = $this->routes->save(
				new RouteRecord(
					$language_id,
					$source_type,
					$source_id,
					$subtype,
					$source_path,
					$new_path,
					$leaf,
					(string) ( $current->route_namespace ?? '' ),
					$origin,
					'active',
					isset( $current->activated_at ) ? (string) $current->activated_at : null
				)
			);
			if ( $saved instanceof WP_Error ) {
				return $saved;
			}

			$this->history->delete_oldest_beyond( $source_type, $source_id, $language_id, self::HISTORY_MAX );

			$this->store->commit_route_boundary( $boundary );
			$committed = true;

			return $saved;
		} finally {
			if ( ! $committed ) {
				$this->store->rollback_route_boundary( $boundary );
			}
		}
	}

	/**
	 * Refreshes source_path only for an existing prepared route (A4).
	 *
	 * @param WP_Post $post        Source post.
	 * @param int     $language_id Language id.
	 * @return object|null|WP_Error
	 */
	public function refresh_source_path( WP_Post $post, int $language_id ) {
		if ( ! $this->capabilities->supports_post( $post ) ) {
			return null;
		}

		$boundary  = $this->store->begin_route_boundary();
		$committed = false;

		try {
			$current = $this->routes->lock_by_object( Store::SOURCE_POST, (int) $post->ID, $language_id );
			if ( null === $current ) {
				$this->store->commit_route_boundary( $boundary );
				$committed = true;

				return null;
			}

			$source_path = $this->source_path_for_post( $post );
			if ( $source_path instanceof WP_Error ) {
				return $source_path;
			}

			$localized = (string) ( $current->localized_path ?? '' );
			$leaf      = (string) ( $current->localized_slug ?? '' );
			try {
				$localized_path = $this->paths->canonicalize( $localized );
			} catch ( InvalidPathException $e ) {
				return new WP_Error( 'aiml_slug_invalid_localized_path', $e->getMessage() );
			}

			$saved = $this->routes->save(
				new RouteRecord(
					$language_id,
					Store::SOURCE_POST,
					(int) $post->ID,
					(string) $post->post_type,
					$source_path,
					$localized_path,
					$leaf,
					(string) ( $current->route_namespace ?? '' ),
					(string) ( $current->slug_origin ?? 'generated' ),
					(string) ( $current->route_status ?? 'active' ),
					isset( $current->activated_at ) ? (string) $current->activated_at : null
				)
			);

			if ( $saved instanceof WP_Error ) {
				return $saved;
			}

			$this->store->commit_route_boundary( $boundary );
			$committed = true;

			return $saved;
		} finally {
			if ( ! $committed ) {
				$this->store->rollback_route_boundary( $boundary );
			}
		}
	}

	/**
	 * Marks prepared routes inactive on trash.
	 *
	 * @param int $post_id Post id.
	 */
	public function deactivate_for_source( int $post_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Schema table identifier only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Schema::slug_routes() . "
				SET route_status = 'inactive', updated_at = %s
				WHERE source_type = %s AND source_id = %d",
				current_time( 'mysql', true ),
				Store::SOURCE_POST,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Purges routes and history for permanent delete.
	 *
	 * @param int $post_id Post id.
	 */
	public function purge_for_source( int $post_id ): void {
		$this->routes->delete_by_source( Store::SOURCE_POST, $post_id );
		$this->history->delete_by_source( Store::SOURCE_POST, $post_id );
	}

	/**
	 * Purges term routes and history.
	 *
	 * @param int $term_id Term id.
	 */
	public function purge_for_term( int $term_id ): void {
		$this->routes->delete_by_source( Store::SOURCE_TERM, $term_id );
		$this->history->delete_by_source( Store::SOURCE_TERM, $term_id );
	}

	/**
	 * Purges the localized route and its history for one post/language.
	 *
	 * Used by "delete this translation / start over" — the slug candidate row
	 * itself is a translation segment removed by Store::delete_object().
	 *
	 * @param int $post_id     Post id.
	 * @param int $language_id Language id.
	 */
	public function purge_for_source_language( int $post_id, int $language_id ): void {
		$this->routes->delete_by_object( Store::SOURCE_POST, $post_id, $language_id );
		$this->history->delete_oldest_beyond( Store::SOURCE_POST, $post_id, $language_id, 0 );
	}

	/**
	 * Builds UI/REST sync facts for a post/language.
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @return array<string, mixed>
	 */
	public function sync_view( WP_Post $post, int $language_id ): array {
		$candidate = $this->store->get( Store::SOURCE_POST, (int) $post->ID, $language_id, Extractor::FIELD_SLUG );
		$route     = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, $language_id );

		$cand_text  = is_object( $candidate ) ? trim( (string) ( $candidate->translated_text ?? '' ) ) : '';
		$origin     = is_object( $candidate ) ? (string) ( $candidate->slug_origin ?? '' ) : '';
		$pub        = is_object( $candidate ) ? (string) ( $candidate->publish_status ?? Store::PUBLISH_UNPUBLISHED ) : Store::PUBLISH_UNPUBLISHED;
		$has_cand   = is_object( $candidate ) && '' !== $cand_text && Store::STATUS_MISSING !== (string) ( $candidate->status ?? '' );
		$active     = null !== $route && 'active' === (string) ( $route->route_status ?? '' );
		$route_slug = null !== $route ? (string) ( $route->localized_slug ?? '' ) : '';

		if ( ! $has_cand && ! $active ) {
			$sync = 'none';
		} elseif ( Store::PUBLISH_PUBLISHED === $pub && $active ) {
			$sync = 'synchronized';
		} elseif ( Store::PUBLISH_PUBLISHED === $pub && ! $active ) {
			$sync = 'inconsistent';
		} elseif ( $has_cand && Store::PUBLISH_PUBLISHED !== $pub ) {
			$sync = 'pending';
		} else {
			$sync = 'none';
		}

		$can_publish = true === $this->eligibility->is_route_publishable( $post, $language_id );
		$blocked     = '';
		if ( ! $can_publish ) {
			$err     = $this->eligibility->is_route_publishable( $post, $language_id );
			$blocked = $err instanceof WP_Error ? $err->get_error_message() : '';
		}

		return array(
			'slug_candidate'                   => $cand_text,
			'slug_origin'                      => $origin,
			'slug_candidate_publish_status'    => $pub,
			'active_route_slug'                => $route_slug,
			'active_route_status'              => null !== $route ? (string) ( $route->route_status ?? '' ) : '',
			'localized_path'                   => null !== $route ? (string) ( $route->localized_path ?? '' ) : '',
			'route_prepared'                   => $active,
			'route_sync_state'                 => $sync,
			'collision_adjusted'               => 'synchronized' === $sync && $cand_text !== $route_slug && '' !== $route_slug,
			'can_generate'                     => 'manual' !== $origin,
			'can_edit_slug'                    => true,
			'can_publish_route'                => $can_publish && $has_cand,
			'route_publication_blocked_reason' => $blocked,
		);
	}

	/**
	 * Operator sync view for a term slug/route (P0 thin seam; mirrors {@see sync_view}).
	 *
	 * Does not change publication or collision semantics.
	 *
	 * @param WP_Term $term        Term.
	 * @param int     $language_id Language id.
	 * @return array<string, mixed>
	 */
	public function sync_term_view( WP_Term $term, int $language_id ): array {
		$candidate = $this->store->get( Store::SOURCE_TERM, (int) $term->term_id, $language_id, TermExtractor::FIELD_SLUG );
		$route     = $this->routes->find_by_object( Store::SOURCE_TERM, (int) $term->term_id, $language_id );

		$cand_text  = is_object( $candidate ) ? trim( (string) ( $candidate->translated_text ?? '' ) ) : '';
		$origin     = is_object( $candidate ) ? (string) ( $candidate->slug_origin ?? '' ) : '';
		$pub        = is_object( $candidate ) ? (string) ( $candidate->publish_status ?? Store::PUBLISH_UNPUBLISHED ) : Store::PUBLISH_UNPUBLISHED;
		$has_cand   = is_object( $candidate ) && '' !== $cand_text && Store::STATUS_MISSING !== (string) ( $candidate->status ?? '' );
		$active     = null !== $route && 'active' === (string) ( $route->route_status ?? '' );
		$route_slug = null !== $route ? (string) ( $route->localized_slug ?? '' ) : '';

		if ( ! $has_cand && ! $active ) {
			$sync = 'none';
		} elseif ( Store::PUBLISH_PUBLISHED === $pub && $active ) {
			$sync = 'synchronized';
		} elseif ( Store::PUBLISH_PUBLISHED === $pub && ! $active ) {
			$sync = 'inconsistent';
		} elseif ( $has_cand && Store::PUBLISH_PUBLISHED !== $pub ) {
			$sync = 'pending';
		} else {
			$sync = 'none';
		}

		$can_publish = true === $this->eligibility->is_term_route_publishable( $term, $language_id );
		$blocked     = '';
		if ( ! $can_publish ) {
			$err     = $this->eligibility->is_term_route_publishable( $term, $language_id );
			$blocked = $err instanceof WP_Error ? $err->get_error_message() : '';
		}

		return array(
			'term_id'                          => (int) $term->term_id,
			'taxonomy'                         => (string) $term->taxonomy,
			'slug_candidate'                   => $cand_text,
			'slug_origin'                      => $origin,
			'slug_candidate_publish_status'    => $pub,
			'active_route_slug'                => $route_slug,
			'active_route_status'              => null !== $route ? (string) ( $route->route_status ?? '' ) : '',
			'localized_path'                   => null !== $route ? (string) ( $route->localized_path ?? '' ) : '',
			'route_prepared'                   => $active,
			'route_sync_state'                 => $sync,
			'collision_adjusted'               => 'synchronized' === $sync && $cand_text !== $route_slug && '' !== $route_slug,
			'can_generate'                     => 'manual' !== $origin,
			'can_edit_slug'                    => true,
			'can_publish_route'                => $can_publish && $has_cand,
			'route_publication_blocked_reason' => $blocked,
		);
	}

	/**
	 * Helper.
	 *
	 * @param int           $language_id Language id.
	 * @param CanonicalPath $path        Effective path.
	 * @param string        $source_type Source type.
	 * @param int           $source_id   Source id.
	 * @return true|WP_Error
	 */
	private function reconcile_own_history( int $language_id, CanonicalPath $path, string $source_type, int $source_id ) {
		$hist = $this->history->find_by_historical_path( $language_id, $path );
		if ( null === $hist ) {
			return true;
		}

		$same = (string) ( $hist->source_type ?? '' ) === $source_type
			&& (int) ( $hist->source_id ?? 0 ) === $source_id;
		if ( ! $same ) {
			return new WP_Error( 'aiml_slug_history_collision', __( 'Localized path is reserved in history by another object.', 'universal-multilingual' ), array( 'status' => 409 ) );
		}

		$this->history->delete_by_id( (int) $hist->history_id );

		return true;
	}

	/**
	 * Archives prior localized path into history when it changed.
	 *
	 * @param object|null   $current        Locked current route.
	 * @param CanonicalPath $effective_path New path.
	 * @param int           $language_id    Language id.
	 * @param string        $source_type    Source type.
	 * @param int           $source_id      Source id.
	 * @param string        $subtype        Source subtype.
	 * @return true|WP_Error
	 */
	private function archive_prior_path_if_changed(
		?object $current,
		CanonicalPath $effective_path,
		int $language_id,
		string $source_type,
		int $source_id,
		string $subtype
	) {
		if ( null === $current ) {
			return true;
		}

		$prior_path = (string) ( $current->localized_path ?? '' );
		if ( '' === $prior_path || $prior_path === $effective_path->to_string() ) {
			return true;
		}

		try {
			$hist_path = $this->paths->canonicalize( $prior_path );
		} catch ( InvalidPathException $e ) {
			return new WP_Error( 'aiml_slug_invalid_prior_path', $e->getMessage() );
		}

		$inserted = $this->history->insert(
			new HistoryRecord(
				$language_id,
				$hist_path,
				$source_type,
				$source_id,
				$subtype
			)
		);

		return $inserted instanceof WP_Error ? $inserted : true;
	}

	/**
	 * Source path for a post via HierarchyPathBuilder when available.
	 *
	 * @param WP_Post $post Post.
	 * @return CanonicalPath|WP_Error
	 */
	private function source_path_for_post( WP_Post $post ) {
		if ( 'product' === $post->post_type
			&& null !== $this->woo_products
			&& RoutingCapabilityRegistry::PRODUCT_CATEGORY_PERMALINK === $this->capabilities->capability_for_post( $post )
		) {
			return $this->woo_products->source_path( $post );
		}

		if ( null !== $this->hierarchy ) {
			return $this->hierarchy->source_path_for_post( $post );
		}

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return new WP_Error( 'aiml_slug_no_permalink', __( 'Could not resolve permalink.', 'universal-multilingual' ) );
		}

		$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$home = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		try {
			return $this->paths->canonicalize( $path );
		} catch ( InvalidPathException $e ) {
			return new WP_Error( 'aiml_slug_invalid_source_path', $e->getMessage() );
		}
	}

	/**
	 * Source path for a term.
	 *
	 * @param WP_Term $term Term.
	 * @return CanonicalPath|WP_Error
	 */
	private function source_path_for_term( WP_Term $term ) {
		if ( null !== $this->hierarchy ) {
			return $this->hierarchy->source_path_for_term( $term );
		}

		$link = get_term_link( $term );
		if ( $link instanceof WP_Error ) {
			return $link;
		}

		$path = (string) wp_parse_url( (string) $link, PHP_URL_PATH );
		try {
			return $this->paths->canonicalize( '/' . ltrim( $path, '/' ) );
		} catch ( InvalidPathException $e ) {
			return new WP_Error( 'aiml_hierarchy_invalid_term_source', $e->getMessage() );
		}
	}

	/**
	 * Localized path for a post.
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @param string  $leaf        Leaf slug.
	 * @return CanonicalPath|WP_Error
	 */
	private function localized_path_for_post( WP_Post $post, int $language_id, string $leaf ) {
		if ( 'product' === $post->post_type
			&& null !== $this->woo_products
			&& RoutingCapabilityRegistry::PRODUCT_CATEGORY_PERMALINK === $this->capabilities->capability_for_post( $post )
		) {
			$built = $this->woo_products->localized_path( $post, $language_id, $leaf );
			if ( $built instanceof WP_Error ) {
				return $built;
			}
			$outcome = (string) ( $built['outcome'] ?? '' );
			if ( WooProductPathBuilder::OUTCOME_SYNCHRONIZED !== $outcome || ! ( $built['path'] instanceof CanonicalPath ) ) {
				return new WP_Error(
					'aiml_woo_product_path_fallback',
					sprintf( 'Product path not rematerializable (%s).', $outcome ),
					array( 'outcome' => $outcome )
				);
			}

			return $built['path'];
		}

		if ( null !== $this->hierarchy && ( (int) $post->post_parent > 0 || 'page' === $post->post_type ) ) {
			return $this->hierarchy->localized_path_for_post( $post, $language_id, $leaf );
		}

		$source = $this->source_path_for_post( $post );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		return $this->collisions->replace_leaf( $source->to_string(), $leaf );
	}

	/**
	 * Localized path for a term.
	 *
	 * @param WP_Term $term        Term.
	 * @param int     $language_id Language id.
	 * @param string  $leaf        Leaf slug.
	 * @return CanonicalPath|WP_Error
	 */
	private function localized_path_for_term( WP_Term $term, int $language_id, string $leaf ) {
		if ( null !== $this->hierarchy ) {
			return $this->hierarchy->localized_path_for_term( $term, $language_id, $leaf );
		}

		$source = $this->source_path_for_term( $term );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		return $this->collisions->replace_leaf( $source->to_string(), $leaf );
	}

	/**
	 * Resolves effective leaf/path for a post (suffixing for generated).
	 *
	 * @param WP_Post $post            Post.
	 * @param int     $language_id     Language id.
	 * @param string  $source_path_str Source path string (flat fallback).
	 * @param string  $candidate_leaf  Candidate leaf.
	 * @param string  $slug_origin     Origin.
	 * @return array{leaf: string, path: CanonicalPath}|WP_Error
	 */
	private function resolve_post_effective_path(
		WP_Post $post,
		int $language_id,
		string $source_path_str,
		string $candidate_leaf,
		string $slug_origin
	) {
		$woo_cat = 'product' === $post->post_type
			&& null !== $this->woo_products
			&& RoutingCapabilityRegistry::PRODUCT_CATEGORY_PERMALINK === $this->capabilities->capability_for_post( $post );

		$hierarchical = (int) $post->post_parent > 0 && 'page' === $post->post_type && null !== $this->hierarchy;
		if ( ! $hierarchical && ! $woo_cat ) {
			return $this->collisions->resolve_effective(
				$source_path_str,
				$candidate_leaf,
				$language_id,
				Store::SOURCE_POST,
				(int) $post->ID,
				$slug_origin
			);
		}

		$attempt = $candidate_leaf;
		// Category/config rematerialization must not auto-suffix; publish may suffix only for generated origin on hierarchy.
		$max = $woo_cat
			? 1
			: ( 'manual' === $slug_origin ? 1 : CanonicalPathCollisionChecker::MAX_SUFFIX_ATTEMPTS );

		for ( $i = 0; $i < $max; $i++ ) {
			if ( $i > 0 ) {
				$attempt = $candidate_leaf . '-' . ( $i + 1 );
			}

			$path = $woo_cat
				? $this->localized_path_for_post( $post, $language_id, $attempt )
				: $this->hierarchy->localized_path_for_post( $post, $language_id, $attempt );
			if ( $path instanceof WP_Error ) {
				return $path;
			}

			$check = $this->collisions->assert_available(
				$language_id,
				$path,
				Store::SOURCE_POST,
				(int) $post->ID
			);
			if ( true === $check ) {
				return array(
					'leaf' => $attempt,
					'path' => $path,
				);
			}

			if ( 'manual' === $slug_origin || $woo_cat ) {
				return $check;
			}
		}

		return new WP_Error( 'aiml_slug_collision_exhausted', __( 'Could not resolve a free localized slug.', 'universal-multilingual' ), array( 'status' => 409 ) );
	}

	/**
	 * Resolves effective leaf/path for a term.
	 *
	 * @param WP_Term $term           Term.
	 * @param int     $language_id    Language id.
	 * @param string  $candidate_leaf Candidate leaf.
	 * @param string  $slug_origin    Origin.
	 * @return array{leaf: string, path: CanonicalPath}|WP_Error
	 */
	private function resolve_term_effective_path(
		WP_Term $term,
		int $language_id,
		string $candidate_leaf,
		string $slug_origin
	) {
		$attempt = $candidate_leaf;
		$max     = 'manual' === $slug_origin ? 1 : CanonicalPathCollisionChecker::MAX_SUFFIX_ATTEMPTS;

		for ( $i = 0; $i < $max; $i++ ) {
			if ( $i > 0 ) {
				$attempt = $candidate_leaf . '-' . ( $i + 1 );
			}

			$path = $this->localized_path_for_term( $term, $language_id, $attempt );
			if ( $path instanceof WP_Error ) {
				return $path;
			}

			$check = $this->collisions->assert_available(
				$language_id,
				$path,
				Store::SOURCE_TERM,
				(int) $term->term_id
			);
			if ( true === $check ) {
				return array(
					'leaf' => $attempt,
					'path' => $path,
				);
			}

			if ( 'manual' === $slug_origin ) {
				return $check;
			}
		}

		return new WP_Error( 'aiml_slug_collision_exhausted', __( 'Could not resolve a free localized slug.', 'universal-multilingual' ), array( 'status' => 409 ) );
	}

	/**
	 * Requests bounded descendant rematerialization after a hierarchical publish.
	 *
	 * @param string       $source_type Source type.
	 * @param int          $source_id   Source id.
	 * @param WP_Post|null $post        Post when known.
	 */
	private function signal_hierarchy_reindex( string $source_type, int $source_id, ?WP_Post $post = null ): void {
		if ( Store::SOURCE_POST === $source_type && $post instanceof WP_Post ) {
			if ( 'page' !== $post->post_type ) {
				return;
			}
			$children = get_pages(
				array(
					'parent'      => (int) $post->ID,
					'number'      => 1,
					'post_status' => array( 'publish', 'private', 'draft' ),
				)
			);
			if ( ! is_array( $children ) || array() === $children ) {
				return;
			}
		}

		if ( Store::SOURCE_TERM === $source_type ) {
			$term = get_term( $source_id );
			if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
				return;
			}
			$children = get_terms(
				array(
					'taxonomy'   => (string) $term->taxonomy,
					'parent'     => $source_id,
					'hide_empty' => false,
					'number'     => 1,
					'fields'     => 'ids',
				)
			);
			if ( ! is_array( $children ) || array() === $children ) {
				return;
			}
		}

		/**
		 * Fires after a prepared route change that may require descendant rematerialization.
		 *
		 * @since 1.4.0
		 *
		 * @param string $source_type Source type.
		 * @param int    $source_id   Source id.
		 */
		do_action( 'aiml_hierarchy_reindex_root', $source_type, $source_id );

		if ( Store::SOURCE_TERM === $source_type ) {
			$term = get_term( $source_id );
			if ( $term instanceof WP_Term && ! is_wp_error( $term ) && 'product_cat' === (string) $term->taxonomy ) {
				/**
				 * Fires when product_cat routes may require dependent product rematerialization.
				 *
				 * @since 1.4.0
				 *
				 * @param int $term_id product_cat term id.
				 */
				do_action( 'aiml_woo_product_dep_root', $source_id );
			}
		}
	}

	/**
	 * Helper.
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language.
	 * @param bool    $idempotent  Whether this was an idempotent re-publish.
	 * @return array<string, mixed>
	 */
	private function result_payload( WP_Post $post, int $language_id, bool $idempotent ): array {
		$view               = $this->sync_view( $post, $language_id );
		$view['idempotent'] = $idempotent;
		$view['status']     = 'published';

		return $view;
	}

	/**
	 * Term publish result payload.
	 *
	 * @param WP_Term $term        Term.
	 * @param int     $language_id Language.
	 * @param bool    $idempotent  Idempotent republish.
	 * @return array<string, mixed>
	 */
	private function term_result_payload( WP_Term $term, int $language_id, bool $idempotent ): array {
		$candidate = $this->store->get( Store::SOURCE_TERM, (int) $term->term_id, $language_id, TermExtractor::FIELD_SLUG );
		$route     = $this->routes->find_by_object( Store::SOURCE_TERM, (int) $term->term_id, $language_id );

		return array(
			'status'                        => 'published',
			'idempotent'                    => $idempotent,
			'slug_candidate'                => is_object( $candidate ) ? trim( (string) ( $candidate->translated_text ?? '' ) ) : '',
			'slug_origin'                   => is_object( $candidate ) ? (string) ( $candidate->slug_origin ?? '' ) : '',
			'slug_candidate_publish_status' => is_object( $candidate ) ? (string) ( $candidate->publish_status ?? '' ) : '',
			'active_route_slug'             => null !== $route ? (string) ( $route->localized_slug ?? '' ) : '',
			'active_route_status'           => null !== $route ? (string) ( $route->route_status ?? '' ) : '',
			'route_prepared'                => null !== $route && 'active' === (string) ( $route->route_status ?? '' ),
			'localized_path'                => null !== $route ? (string) ( $route->localized_path ?? '' ) : '',
			'source_path'                   => null !== $route ? (string) ( $route->source_path ?? '' ) : '',
		);
	}
}
