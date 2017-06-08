<?php
/**
 * Media Library List Table class.
 *
 */
class FU_WP_Media_List_Table extends WP_Media_List_Table {

	function __construct() {
		parent::__construct();
	}

	/**
	* WP_Media_List_Table is loaded in a different matter and WP_Media_List::prepare_items() calls wp
	* And we don't want that, so the query is set with query_posts in Frontend_Uploader::_set_global_query_for_tables()
	*/
	function prepare_items() {
		global $lost, $wpdb, $wp_query, $post_mime_types, $avail_post_mime_types;

		$this->items = $wp_query->posts;
		/* -- Register the Columns -- */
		$columns = $this->get_columns();
		$hidden = array(
			'id',
		);

		$this->_column_headers = array( $columns, $hidden, $this->get_sortable_columns() ) ;

		add_filter( 'media_row_actions', array( $this, 'filter_media_row_actions' ), 9, 3);
	}

	function filter_media_row_actions( $actions, $post, $detached ) {
		$detached = $post->post_parent === 0;
		$actions['pass'] = '<a href="' . esc_url( admin_url( 'admin-ajax.php' ).'?action=approve_ugc&id=' . $post->ID . '&fu_nonce=' . wp_create_nonce( FU_NONCE ) ). '">'. __( 'Approve', 'frontend-custom-post' ) .'</a>';

		if ( ! $detached ) {
			$actions['re-attach'] = sprintf( '<a class="hide-if-no-js" onclick="findPosts.open( \'media[]\', \'%d\' );return false;" href="#the-list">%s</a>',
				$post->ID, esc_html( __( 'Re-Attach', 'frontend-custom-post' ) )
			);
		}

		return $actions;
	}
}