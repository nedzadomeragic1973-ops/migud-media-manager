<?php
/**
 * REST endpoints for folder management and assignment.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes in the migud-mm/v1 namespace, also served under the former fmm/v1
 * namespace (Migud_MM_Legacy). Every route checks capabilities server-side.
 */
class Migud_MM_REST {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'migud-mm/v1';

	/**
	 * Prefix of the plugin error codes.
	 */
	const ERROR_PREFIX = 'migud_mm_';

	/**
	 * Registers the routes in the new and in the former namespace. Both use
	 * the same handlers, so a request is handled exactly once.
	 */
	public static function register_routes() {
		self::register_namespace( self::NAMESPACE_V1 );
		// Legacy adapter: integrations and scripts loaded before the update call fmm/v1.
		self::register_namespace( Migud_MM_Legacy::REST_NAMESPACE );
	}

	/**
	 * Route handler: pauses while the data migration is pending and keeps the
	 * former error codes in the former namespace. Runs after the permission callback.
	 *
	 * @param string $method Handler method of this class.
	 * @param bool   $legacy Whether the route belongs to the former namespace.
	 * @return Closure
	 */
	private static function handler( $method, $legacy ) {
		return static function ( WP_REST_Request $request ) use ( $method, $legacy ) {
			$response = Migud_MM_Maintenance::is_active() ? Migud_MM_Maintenance::error() : call_user_func( array( __CLASS__, $method ), $request );

			return $legacy && is_wp_error( $response ) ? Migud_MM_Legacy::rest_error( $response ) : $response;
		};
	}

	/**
	 * Registers the routes of one namespace.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	private static function register_namespace( $rest_namespace ) {
		$legacy   = Migud_MM_Legacy::REST_NAMESPACE === $rest_namespace;
		$name_arg = array(
			'name' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			$rest_namespace,
			'/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => self::handler( 'get_folders', $legacy ),
					'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => self::handler( 'create_folder', $legacy ),
					'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_manage' ),
					'args'                => array_merge(
						$name_arg,
						array(
							'parent' => array(
								'type'    => 'integer',
								'minimum' => 0,
								'default' => 0,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => self::handler( 'get_summary', $legacy ),
				'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_read' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => self::handler( 'update_folder', $legacy ),
					'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_manage' ),
					'args'                => array(
						'name'   => array( 'type' => 'string' ),
						'parent' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'color'  => array( 'type' => 'string' ),
						'icon'   => array( 'type' => 'string' ),
						'locked' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => self::handler( 'delete_folder', $legacy ),
					'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => self::handler( 'reorder_folders', $legacy ),
				'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_manage' ),
				'args'                => array(
					'parent' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'ids'    => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 2000,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/preferences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => self::handler( 'set_preferences', $legacy ),
				'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_read' ),
				'args'                => array(
					'sort' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Migud_MM_Taxonomy::SORT_MODES,
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/assign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => self::handler( 'assign', $legacy ),
				'permission_callback' => array( 'Migud_MM_Taxonomy', 'can_assign_any' ),
				'args'                => array(
					'attachment_ids' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 500,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'folder_ids'     => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'mode'           => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'add', 'remove', 'move' ),
					),
					'source_folder'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * GET /folders.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_folders() {
		return rest_ensure_response( Migud_MM_Taxonomy::get_folders() );
	}

	/**
	 * GET /summary: folders plus "All Media" and "Unassigned" counts.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_summary() {
		return rest_ensure_response( Migud_MM_Library::get_summary() );
	}

	/**
	 * POST /folders. An optional `parent` creates a subfolder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_folder( WP_REST_Request $request ) {
		$name  = Migud_MM_Taxonomy::sanitize_name( $request['name'] );
		$valid = Migud_MM_Taxonomy::validate_name( $name );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$parent = (int) $request['parent'];
		if ( $parent && ! Migud_MM_Taxonomy::get_folder( $parent ) ) {
			return self::not_found();
		}
		if ( Migud_MM_Taxonomy::is_protected( $parent ) ) {
			return self::locked_error( __( 'A locked folder cannot receive folders.', 'migud-media-manager' ) );
		}
		if ( Migud_MM_Taxonomy::name_exists( $name, $parent ) ) {
			return self::term_error( new WP_Error( 'term_exists' ) );
		}

		$order  = Migud_MM_Taxonomy::next_order( $parent );
		$result = wp_insert_term( $name, Migud_MM_Taxonomy::NAME, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return self::term_error( $result );
		}
		update_term_meta( $result['term_id'], Migud_MM_Taxonomy::ORDER_META, $order );

		$response = rest_ensure_response( Migud_MM_Taxonomy::prepare_folder( Migud_MM_Taxonomy::get_folder( $result['term_id'] ) ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * POST /folders/order. Stores the manual order of the folders below `parent`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reorder_folders( WP_REST_Request $request ) {
		$parent = (int) $request['parent'];
		if ( $parent && ! Migud_MM_Taxonomy::get_folder( $parent ) ) {
			return self::not_found();
		}

		if ( Migud_MM_Taxonomy::is_protected( $parent ) ) {
			return self::locked_error( __( 'The folders inside a locked folder cannot be reordered.', 'migud-media-manager' ) );
		}

		// A locked folder keeps its position among its siblings.
		$ids     = array_map( 'intval', (array) $request['ids'] );
		$current = Migud_MM_Taxonomy::child_ids( $parent, Migud_MM_Taxonomy::get_folders( 'manual' ) );
		foreach ( array_intersect( $current, Migud_MM_Taxonomy::locked_ids() ) as $index => $locked_id ) {
			if ( in_array( $locked_id, $ids, true ) && array_search( $locked_id, $ids, true ) !== $index ) {
				return self::locked_error( __( 'A locked folder keeps its position; the order cannot be changed here.', 'migud-media-manager' ) );
			}
		}

		$result = Migud_MM_Taxonomy::reorder( $parent, $request['ids'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( Migud_MM_Library::get_summary() );
	}

	/**
	 * POST /preferences. Saves the folder sort mode of the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function set_preferences( WP_REST_Request $request ) {
		Migud_MM_Taxonomy::set_sort_mode( $request['sort'] );

		return rest_ensure_response( Migud_MM_Library::get_summary() );
	}

	/**
	 * PATCH /folders/<id>. Renames, moves, recolors, sets the icon of and/or
	 * locks or unlocks a folder (`locked`: boolean; rename and move are checked
	 * against the lock state before the request; `color`: preset key, #rrggbb, or ''/'gray' to reset; `icon`: a key
	 * of Migud_MM_Taxonomy::ICONS, or ''/'folder' to reset); the slug stays stable; media assignments are untouched because the term_taxonomy_id
	 * does not change. A moved folder goes to the end of its new level and
	 * takes its subfolders along.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_folder( WP_REST_Request $request ) {
		$folder = Migud_MM_Taxonomy::get_folder( $request['id'] );
		if ( ! $folder ) {
			return self::not_found();
		}

		if ( ! $request->has_param( 'name' ) && ! $request->has_param( 'parent' ) && ! $request->has_param( 'color' ) && ! $request->has_param( 'icon' ) && ! $request->has_param( 'locked' ) ) {
			return new WP_Error( self::ERROR_PREFIX . 'nothing_to_update', __( 'Nothing to update.', 'migud-media-manager' ), array( 'status' => 400 ) );
		}

		$args   = array();
		$name   = wp_specialchars_decode( $folder->name, ENT_QUOTES );
		$parent = (int) $folder->parent;
		$moved  = false;

		if ( $request->has_param( 'name' ) ) {
			$name  = Migud_MM_Taxonomy::sanitize_name( $request['name'] );
			$valid = Migud_MM_Taxonomy::validate_name( $name );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$args['name'] = $name;
		}

		if ( $request->has_param( 'parent' ) ) {
			$target = (int) $request['parent'];
			if ( $target && ! Migud_MM_Taxonomy::get_folder( $target ) ) {
				return self::not_found();
			}
			if ( ! Migud_MM_Taxonomy::is_valid_parent( $folder->term_id, $target ) ) {
				return new WP_Error( self::ERROR_PREFIX . 'invalid_parent', __( 'A folder cannot be moved into itself or one of its subfolders.', 'migud-media-manager' ), array( 'status' => 400 ) );
			}
			if ( $target !== $parent ) {
				$parent         = $target;
				$moved          = true;
				$args['parent'] = $target;
			}
		}

		// A locked folder (or one inside a locked folder) keeps name and place; a move must not drag a locked folder along or into one.
		$protected = Migud_MM_Taxonomy::is_protected( $folder->term_id );
		if ( $protected && ( ( isset( $args['name'] ) && wp_specialchars_decode( $folder->name, ENT_QUOTES ) !== $args['name'] ) || $moved ) ) {
			return self::locked_error( __( 'A locked folder cannot be renamed or moved.', 'migud-media-manager' ) );
		}
		if ( $moved && Migud_MM_Taxonomy::is_protected( $parent ) ) {
			return self::locked_error( __( 'A locked folder cannot receive folders.', 'migud-media-manager' ) );
		}
		if ( $moved && Migud_MM_Taxonomy::has_locked_descendant( $folder->term_id ) ) {
			return self::locked_error( __( 'A folder that contains a locked folder cannot be moved.', 'migud-media-manager' ) );
		}

		// wp_update_term() only rejects duplicate slugs, not duplicate names. A move is checked in the target level.
		if ( ( isset( $args['name'] ) || $moved ) && Migud_MM_Taxonomy::name_exists( $name, $moved ? $parent : self::effective_parent( $folder ), $folder->term_id ) ) {
			return self::term_error( new WP_Error( 'term_exists' ) );
		}

		$color = null;
		if ( $request->has_param( 'color' ) ) {
			$raw   = $request['color'];
			$reset = in_array( $raw, array( '', 'gray' ), true );
			$color = $reset ? '' : Migud_MM_Taxonomy::sanitize_color( $raw );
			if ( ! $reset && '' === $color ) {
				return new WP_Error( self::ERROR_PREFIX . 'invalid_color', __( 'Invalid folder color.', 'migud-media-manager' ), array( 'status' => 400 ) );
			}
		}

		$icon = null;
		if ( $request->has_param( 'icon' ) ) {
			$raw   = $request['icon'];
			$reset = in_array( $raw, array( '', 'folder' ), true );
			$icon  = $reset ? '' : Migud_MM_Taxonomy::sanitize_icon( $raw );
			if ( ! $reset && '' === $icon ) {
				return new WP_Error( self::ERROR_PREFIX . 'invalid_icon', __( 'Invalid folder icon.', 'migud-media-manager' ), array( 'status' => 400 ) );
			}
		}

		$order = $moved ? Migud_MM_Taxonomy::next_order( $parent ) : 0;
		if ( $args ) {
			$result = wp_update_term( $folder->term_id, Migud_MM_Taxonomy::NAME, $args );
			if ( is_wp_error( $result ) ) {
				return self::term_error( $result );
			}
		}
		if ( $moved ) {
			update_term_meta( $folder->term_id, Migud_MM_Taxonomy::ORDER_META, $order );
		}
		if ( '' === $color ) {
			delete_term_meta( $folder->term_id, Migud_MM_Taxonomy::COLOR_META );
		} elseif ( null !== $color ) {
			update_term_meta( $folder->term_id, Migud_MM_Taxonomy::COLOR_META, $color );
		}
		if ( '' === $icon ) {
			delete_term_meta( $folder->term_id, Migud_MM_Taxonomy::ICON_META );
		} elseif ( null !== $icon ) {
			update_term_meta( $folder->term_id, Migud_MM_Taxonomy::ICON_META, $icon );
		}
		if ( $request->has_param( 'locked' ) ) {
			if ( rest_sanitize_boolean( $request['locked'] ) ) {
				update_term_meta( $folder->term_id, Migud_MM_Taxonomy::LOCK_META, '1' );
			} else {
				delete_term_meta( $folder->term_id, Migud_MM_Taxonomy::LOCK_META );
			}
			Migud_MM_Taxonomy::reset_lock_cache();
		}

		return rest_ensure_response( Migud_MM_Taxonomy::prepare_folder( Migud_MM_Taxonomy::get_folder( $folder->term_id ) ) );
	}

	/**
	 * Error for an action a folder lock forbids.
	 *
	 * @param string $message Translated message.
	 * @return WP_Error
	 */
	private static function locked_error( $message ) {
		return new WP_Error( self::ERROR_PREFIX . 'folder_locked', $message, array( 'status' => 403 ) );
	}

	/**
	 * Effective parent ID of a folder: orphans count as top level.
	 *
	 * @param WP_Term $folder Folder term.
	 * @return int
	 */
	private static function effective_parent( WP_Term $folder ) {
		return Migud_MM_Taxonomy::get_folder( $folder->parent ) ? (int) $folder->parent : 0;
	}

	/**
	 * DELETE /folders/<id>. Removes the folder only; media stays untouched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_folder( WP_REST_Request $request ) {
		$folder = Migud_MM_Taxonomy::get_folder( $request['id'] );
		if ( ! $folder ) {
			return self::not_found();
		}

		if ( Migud_MM_Taxonomy::is_protected( $folder->term_id ) || Migud_MM_Taxonomy::has_locked_descendant( $folder->term_id ) ) {
			return self::locked_error( __( 'A locked folder, or a folder that contains one, cannot be deleted. Unlock it first.', 'migud-media-manager' ) );
		}

		$result = wp_delete_term( $folder->term_id, Migud_MM_Taxonomy::NAME );
		if ( true !== $result ) {
			return new WP_Error( self::ERROR_PREFIX . 'delete_failed', __( 'The folder could not be deleted.', 'migud-media-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (int) $folder->term_id,
			)
		);
	}

	/**
	 * POST /assign. Adds or removes folders for many attachments, or moves
	 * them from `source_folder` to the given folders; each attachment is
	 * checked individually with edit_post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function assign( WP_REST_Request $request ) {
		$folder_ids = array_values( array_unique( array_map( 'intval', $request['folder_ids'] ) ) );
		$is_move    = 'move' === $request['mode'];
		if ( Migud_MM_Taxonomy::filter_folder_ids( $folder_ids ) !== $folder_ids || ( $is_move && ! Migud_MM_Taxonomy::get_folder( (int) $request['source_folder'] ) ) ) {
			return new WP_Error( self::ERROR_PREFIX . 'invalid_folder', __( 'One or more folders do not exist.', 'migud-media-manager' ), array( 'status' => 400 ) );
		}

		$result = $is_move
			? Migud_MM_Taxonomy::move( $request['attachment_ids'], $folder_ids, (int) $request['source_folder'] )
			: Migud_MM_Taxonomy::assign( $request['attachment_ids'], $folder_ids, 'add' === $request['mode'] );

		if ( empty( $result['updated'] ) ) {
			return new WP_Error(
				self::ERROR_PREFIX . 'nothing_updated',
				__( 'None of the selected media files could be updated.', 'migud-media-manager' ),
				array(
					'status'  => 403,
					'skipped' => $result['skipped'],
				)
			);
		}

		$counts = array();
		foreach ( $folder_ids as $folder_id ) {
			$folder                        = Migud_MM_Taxonomy::get_folder( $folder_id );
			$counts[ (string) $folder_id ] = $folder ? (int) $folder->count : 0;
		}

		return rest_ensure_response(
			array(
				'updated' => $result['updated'],
				'skipped' => $result['skipped'],
				'counts'  => $counts,
			)
		);
	}

	/**
	 * 404 error for unknown folders.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( self::ERROR_PREFIX . 'folder_not_found', __( 'Folder not found.', 'migud-media-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * Maps term API errors to REST errors.
	 *
	 * @param WP_Error $error Term error.
	 * @return WP_Error
	 */
	private static function term_error( WP_Error $error ) {
		if ( in_array( $error->get_error_code(), array( 'term_exists', 'duplicate_term_slug' ), true ) ) {
			return new WP_Error( self::ERROR_PREFIX . 'folder_exists', __( 'A folder with this name already exists.', 'migud-media-manager' ), array( 'status' => 409 ) );
		}
		return new WP_Error( self::ERROR_PREFIX . 'term_error', $error->get_error_message(), array( 'status' => 400 ) );
	}
}
