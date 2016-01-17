<?php
/**
 * Plugin Name: Network Aggregate
 * Description: Changes home page to pull content from all sites
 * Author: Justin Foell
 * Version: 1.1
 * Author URI: http://www.foell.org/justin
 */

class NetworkAggregate {

	private $db;
	private $blog_ids;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public function hook_debug( $name ) {
		echo "<!-- HOOK {$name} -->\n";
	}

	public function init() {
		add_filter( 'posts_request', array( $this, 'filter_query' ) );
		//add_action( 'all', array( $this, 'hook_debug' ) );
		//add_filter( 'all', array( $this, 'hook_debug' ) );
	}

	private function load_blog_ids() {
		$this->blog_ids = $this->db->get_col( "SELECT blog_id FROM {$this->db->blogs}" );
	}

	public function filter_query( $query ) {
		$dummy_query = new WP_Query();  // the query isn't run if we don't pass any query vars
		$dummy_query->parse_query( $query );

		// this is the actual manipulation; do whatever you need here
		if ( $dummy_query->is_home() ) {
			$this->load_blog_ids();
			$query = $this->parse_query( $query );
		}

		return $query;
	}

	public function loop_start( $query ) {
		add_action( 'the_post', array( $this, 'the_post' ) );
		add_action( 'loop_end', array( $this, 'loop_end' ) );
	}

	public function the_post( $post ) {
		restore_current_blog(); //reset to center so we remember for loop_end
		switch_to_blog( $post->blog_id );
	}

	public function loop_end( $query ) {
		restore_current_blog();
	}

	private function parse_query( $query ) {
		if( preg_match( '/(SELECT SQL_CALC_FOUND_ROWS)(.*?)(ORDER.*?)$/', $query, $matches ) ) {

			$post_table = $this->db->posts;
			$new_query = array();
			$new_query[BLOG_ID_CURRENT_SITE] = $matches[1] . ' ' . BLOG_ID_CURRENT_SITE . ' as blog_id, ' . $matches[2];
			$prefix_len = strlen( $this->db->prefix );
			$posts_suffix = substr( $post_table, $prefix_len );
			$order = str_replace( $post_table . '.', '', $matches[3] );

			foreach ( $this->blog_ids as $blog_id ) {
				if ( $blog_id != BLOG_ID_CURRENT_SITE ) {
					$blog_post_table = $this->db->prefix . $blog_id . '_' . $posts_suffix;
					$new_query[$blog_id] = "SELECT {$blog_id} as blog_id, " . str_replace( $post_table, $blog_post_table, $matches[2] );
				}
			}
			// Hook it up!
			add_action( 'loop_start', array( $this, 'loop_start' ) );
			$new_query = implode(' UNION ', $new_query) . $order;
			//file_put_contents('/tmp/request.txt', print_r($new_query, true));
			return $new_query;
		}
		return $query;
	}
}

$na_plugin = new NetworkAggregate();
add_action( 'init', array( $na_plugin, 'init' ) );
