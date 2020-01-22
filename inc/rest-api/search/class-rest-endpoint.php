<?php
/**
 * Custom REST API endpoint for reusable block search functionality.
 */

namespace EnhancedReusableBlocks\REST_API\Search;

use EnhancedReusableBlocks\Connections;

use WP_Error;
use WP_Query;
use WP_REST_Blocks_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class REST_Endpoint
 */
class REST_Endpoint {

	/**
	 * Namespace for the endpoint.
	 *
	 * @var string
	 */
	protected $namespace;

	/**
	 * Base URL for endpoint.
	 *
	 * @var string
	 */
	protected $rest_base;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'altis-erb/v1';
		$this->rest_base = 'search';
	}

	/**
	 * Register Routes for Search Endpoint Request.
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			$this->rest_base,
			[
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ $this, 'get_items' ],
				'schema'   => ( new WP_REST_Blocks_Controller( 'wp_block' ) )->get_item_schema(),
				'args'     => [
					'context' => [
						'default'  => 'view',
					],
					'searchID' => [
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Fetches the reusable blocks of a single post by ID or a single reusable block by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error|Array REST_Response, WP_Error, or empty array.
	 */
	public function get_items( $request ) {
		$search_id = $request->get_param( 'searchID' );

		if ( empty( $search_id ) ) {
			return new WP_Error(
				'erb.no_search_id_provided',
				__( 'No `searchID` parameter provided.', 'enhanced-reusable-blocks' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! is_numeric( $search_id ) ) {
			return new WP_Error(
				'erb.invalid_search_id_provided',
				__( 'Invalid `searchID` parameter provided.', 'enhanced-reusable-blocks' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $post = get_post( intval( $search_id ) ) ) {
			// translators: %d is the search ID requested via REST API.
			return new WP_Error(
				'erb.not_post_found',
				sprintf( __( 'The requested post ID of %d not found.', 'enhanced-reusable-blocks' ), $search_id ),
				[ 'status' => 404 ]
			);
		}

		/**
		 * If queried post is a reusable block, send that.
		 *
		 * Else, if the post type of the queried post supports reusable blocks, send all reusable blocks within that post.
		 *
		 * Otherwise, return empty array.
		 */
		if ( $post->post_type === 'wp_block' ) {
			$block_ids = [ $post->ID ];
		} else if ( in_array( $post->post_type, Connections\get_post_types_with_reusable_blocks(), true ) ) {
			$blocks = array_filter( parse_blocks( $post->post_content ), function( $block ) {
				return $block['blockName'] === 'core/block';
			} );

			$block_ids = array_map( function( $block ) {
				return $block['attrs']['ref'];
			}, $blocks );
		} else {
			return [];
		}

		$blocks_request = new WP_REST_Request( 'GET', '/wp/v2/blocks' );
		$blocks_request->set_query_params( [
			'include'        => $block_ids,
			'posts_per_page' => 100
		] );

		// Parse request to get data.
		$response = rest_do_request( $blocks_request );

		// Handle if error.
		if ( $response->is_error() ) {
			return new WP_Error(
				'erb.rest_error_blocks_by_id',
				__( 'There was an error encountered when retrieving blocks from ID.', 'enhanced-reusable-blocks' ),
				[ 'status' => 404 ]
			);
		}

		return $response;
	}
}