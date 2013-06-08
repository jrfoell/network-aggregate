<?php
/*
Plugin Name: Network Aggregate
Description: Changes home page to pull content from all sites
Author: Justin Foell
Version: 1.0
Author URI: http://www.foell.org/justin
*/

class NetworkAggregate {

	private $db;
	private $blog_ids;
	private $last_blog_id = BLOG_ID_CURRENT_SITE;
	
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public function hookDebug( $name ) {
		echo "<!-- HOOK {$name} -->\n";
	}
	
	public function onInit() {
		add_filter( 'posts_request', array( $this, 'filterQuery' ) );
		//add_action( 'all', array( $this, 'hookDebug' ) );
		//add_filter( 'all', array( $this, 'hookDebug' ) );
	}

	private function loadBlogIds() {
		$this->blog_ids = $this->db->get_col( "SELECT blog_id FROM {$this->db->blogs}" );
	}
	
	public function filterQuery( $query ) {
		$dummy_query = new WP_Query();  // the query isn't run if we don't pass any query vars
		$dummy_query->parse_query( $query );

		// this is the actual manipulation; do whatever you need here
		if ( $dummy_query->is_home() ) {
			$this->loadBlogIds();
			$query = $this->parseQuery( $query );
		}
		
		return $query;
	}

	public function onThePost( $post ) {
		if ( $post->blog_id != $this->last_blog_id ) {
			restore_current_blog(); //reset to center so we remember for loop_end
			$this->last_blog_id = $post->blog_id;
			switch_to_blog( $post->blog_id );
		}
	}

	public function onLoopEnd( $query ) {
		restore_current_blog();
	}
	
	private function parseQuery( $query ) {
		file_put_contents('/tmp/request.txt', print_r($query, true));
		if( preg_match( '/(SELECT SQL_CALC_FOUND_ROWS)(.*?)(ORDER.*?)$/', $query, $matches ) ) {

			$post_table = $this->db->posts;
			$query = array();
			$query[BLOG_ID_CURRENT_SITE] = $matches[1] . ' ' . BLOG_ID_CURRENT_SITE . ' as blog_id, ' . $matches[2];
			$prefix_len = strlen( $this->db->prefix );
			$posts_suffix = substr( $post_table, $prefix_len );
			$order = str_replace( $post_table . '.', '', $matches[3] );
				
			foreach ( $this->blog_ids as $blog_id ) {
				if ( $blog_id != BLOG_ID_CURRENT_SITE ) {
					$blog_post_table = $this->db->prefix . $blog_id . '_' . $posts_suffix;
					$query[$blog_id] = "SELECT {$blog_id} as blog_id, " . str_replace( $post_table, $blog_post_table, $matches[2] );
				}
			}
			add_action( 'the_post', array( $this, 'onThePost' ) );
			add_action( 'loop_end', array( $this, 'onLoopEnd' ) );
			return implode(' UNION ', $query) . $order;
		}
		return $query;
	}
}

$na_plugin = new NetworkAggregate();
add_action( 'init', array( $na_plugin, 'onInit' ) );
