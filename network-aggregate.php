<?php
/*
Plugin Name: Network Aggregate
Description: Changes home page to pull content from all sites
Author: Justin Foell
Version: 1.0
Author URI: http://www.foell.org/justin
*/

class NetworkAggregatePlugin {
	private $db;
	public function __construct($db) {
		$this->db = $db;
	}
	public function onInit() {
		$settings = NetworkAggregateSettings::read();
		if (empty($settings['enabled']))
			return;
		add_filter('posts_request', array($this, 'filterQuery'));
	}
	public function onAdminInit() {
		$s = new NetworkAggregateSettings();
		$s->register('reading');
	}
	public function filterQuery($query) {
		global $wp_query;
		
		if ($wp_query->is_home()) {
			$net = new NetworkAggregate($this->db);
			$net->hook();

			return $net->filterQuery($query);
		} else {
			remove_filter('posts_request', array($this, 'filterQuery'));
		}
		return $query;
	}
}

class NetworkAggregate {

	private $db;
	private $blog_ids;
	private $last_blog_id = BLOG_ID_CURRENT_SITE;
	
	public function __construct($wpdb) {
		$this->db = $wpdb;
	}

	public function hook() {
		add_filter('posts_request', array($this, 'filterQuery'));
		add_action( 'the_post', array( $this, 'onThePost' ) );
		add_action( 'loop_end', array( $this, 'onLoopEnd' ) );
	}

	public function unhook() {
		remove_filter('posts_request', array($this, 'filterQuery'));
		remove_action( 'the_post', array( $this, 'onThePost' ) );
		remove_action( 'loop_end', array( $this, 'onLoopEnd' ) );
	}

	private function loadBlogIds() {
		$this->blog_ids = $this->db->get_col( "SELECT blog_id FROM {$this->db->blogs} WHERE deleted=0 AND spam=0 AND archived='0'" );
	}
	
	public function filterQuery( $query ) {
		$this->loadBlogIds();
		return $this->parseQuery( $query );
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
		$this->unhook();
	}
	
	private function parseQuery( $query ) {
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
			return implode(' UNION ', $query) . $order;
		}
		return $query;
	}
}

class NetworkAggregateSettings {
	const NAME = 'network_aggregate';
	
	public static function read() {
		return (array)get_option(self::NAME);
	}

	public function validate($in) {
		return array(
			'enabled' => !empty($in['enabled']),
		);
	}

	public function renderSection() {
		echo "<p>Control the display of posts from the entire network on your front page.</p>";
	}

	public function renderEnabled($setting) {
		$set = !empty($setting['enabled']);
		printf('<input type="checkbox" id="%s" name="%s" %s/>',
			'network_aggregate_enabled', self::NAME.'[enabled]', $set ? 'checked' : '');
	}

	public function register($section) {
		register_setting($section, self::NAME, array($this, 'validate'));
		add_settings_section(self::NAME, 'Network Posts', array($this, 'renderSection'), $section);

		$setting = get_option(self::NAME);
		add_settings_field('network_aggregate_enabled',
			'Enabled',
			array($this, 'renderEnabled'),
			$section,
			self::NAME,
			$setting);
	}
}

global $wpdb;
$na_plugin = new NetworkAggregatePlugin($wpdb);
add_action('init', array($na_plugin, 'onInit'));
add_action('admin_init', array($na_plugin, 'onAdminInit'));

function network_aggregate_get_posts($q) {
	global $wpdb;

	$net = new NetworkAggregate($wpdb);
	$net->hook();
	
	$q = new WP_Query($q);
	$posts = $q->get_posts();

	$net->unhook();
	return $posts;
}

function network_aggregate_query($q) {
	global $wp_query, $wpdb;

	$net = new NetworkAggregate($wpdb);
	$net->hook();
	
	$q = new WP_Query($q);
	$q->set('paged', $wp_query->get('paged'));
	$q->get_posts();
	
	return $wp_query = $q;
}
