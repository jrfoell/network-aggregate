<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Plugin file.
/**
 * Plugin Name: Network Aggregate
 * Description: Changes home page to pull content from all sites
 * Author: Justin Foell
 * Version: 1.2
 * Author URI: https://www.foell.org/justin
 */

/**
 * Plugin class.
 *
 * @since 1.1
 */
class NetworkAggregate {

	/**
	 * Member var for WPDB.
	 *
	 * @var wpdb
	 * @since 1.0
	 */
	private $db;

	/**
	 * Blog IDs for this multi-site installation.
	 *
	 * @var array
	 * @since 1.0
	 */
	private $blog_ids;

	/**
	 * Blog ID for the current site being operated on in "The Loop".
	 *
	 * @var int
	 * @since 1.2
	 */
	private $current_blog;

	/**
	 * Array of WP_Rewrites per blog ID.
	 *
	 * @var array
	 * @since 1.2
	 */
	private $taxonomy_rewrites = array();

	/**
	 * Constructor.
	 *
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.0
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Hook for debugging.
	 *
	 * @param string $name Hook name.
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function hook_debug( $name ) {
		echo "<!-- HOOK {$name} -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug only.
	}

	/**
	 * Initialize this class by adding necessary hooks.
	 *
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function init() {
		add_filter( 'posts_request', array( $this, 'filter_query' ) );
		// phpcs:ignore -- Debug only.
		// add_action( 'all', array( $this, 'hook_debug' ) );
	}

	/**
	 * Load the blog IDs for this multi-site instance.
	 *
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	private function load_blog_ids() {
		$this->blog_ids = $this->db->get_col( "SELECT blog_id FROM {$this->db->blogs}" );
	}

	/**
	 * Filter the main SQL query to do something different.
	 *
	 * @param string $sql_query Main SQL query for "The Loop".
	 * @return string Altered main SQL query.
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function filter_query( $sql_query ) {
		$dummy_query = new WP_Query();  // The query isn't run if we don't pass any query vars.
		$dummy_query->parse_query( $sql_query );

		// This is where we do the actual manipulation.
		if ( $dummy_query->is_home() ) {
			$this->load_blog_ids();
			$sql_query = $this->parse_query( $sql_query );
		}

		return $sql_query;
	}

	/**
	 * Add hooks once the main query has been identified.
	 *
	 * @param WP_Query $query WP_Query (unused).
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function loop_start( $query ) {
		add_action( 'the_post', array( $this, 'the_post' ) );
		add_action( 'loop_end', array( $this, 'loop_end' ) );
		add_filter( 'pre_term_link', array( $this, 'pre_term_link' ), 10, 2 );

	}

	/**
	 * Make sure per-blog taxonomy term links are correct.
	 *
	 * @param string  $termlink Term link for the site (likely formatted for the parent site).
	 * @param WP_Term $term Term object.
	 * @return string Permalink structure for a term link.
	 * @author Justin Foell <justin@foell.org>
	 * @since  1.2
	 */
	public function pre_term_link( $termlink, $term ) {
		if ( BLOG_ID_CURRENT_SITE === $this->current_blog ) {
			return $termlink;
		}

		// Keep one set of taxonomy rewrites per blog ID.
		if ( empty( $this->taxonomy_rewrites[ $this->current_blog ] ) ) {
			$this->taxonomy_rewrites[ $this->current_blog ] = new WP_Rewrite();
		}

		$wp_rewrite = $this->taxonomy_rewrites[ $this->current_blog ];

		// Add taxonomies to the per-site rewrite rules as we encounter them.
		if ( false === $wp_rewrite->get_extra_permastruct( $term->taxonomy ) ) {
			$taxonomy = get_taxonomy( $term->taxonomy );
			$wp_rewrite->add_permastruct( $taxonomy->name, "{$taxonomy->rewrite['slug']}/%$taxonomy->name%", $taxonomy->rewrite );
		}

		return $wp_rewrite->get_extra_permastruct( $term->taxonomy );
	}

	/**
	 * Switch to the correct blog ID before rendering each post.
	 *
	 * @param WP_Post $post Post currently being operated on in "The Loop".
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function the_post( $post ) {
		restore_current_blog(); // Reset to center so we remember for loop_end.
		switch_to_blog( $post->blog_id );
		$this->current_blog = $post->blog_id;
	}

	/**
	 * Reset to original blog ID after "The Loop".
	 *
	 * @param WP_Query $query WP_Query (unused).
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	public function loop_end( $query ) {
		restore_current_blog();
	}

	/**
	 * Parse the main query from "The Loop".
	 *
	 * @param WP_Query $query Main WP_Query for content.
	 * @return WP_Query
	 * @author Justin Foell <justin@foell.org>
	 * @since 1.1
	 */
	private function parse_query( $query ) {
		if ( preg_match( '/(SELECT SQL_CALC_FOUND_ROWS)(.*?)(ORDER.*?)$/', $query, $matches ) ) {

			$post_table = $this->db->posts;
			$new_query  = array();

			$new_query[ BLOG_ID_CURRENT_SITE ] = $matches[1] . ' ' . BLOG_ID_CURRENT_SITE . ' as blog_id, ' . $matches[2];

			$prefix_len   = strlen( $this->db->prefix );
			$posts_suffix = substr( $post_table, $prefix_len );
			$order        = str_replace( $post_table . '.', '', $matches[3] );

			foreach ( $this->blog_ids as $blog_id ) {
				if ( BLOG_ID_CURRENT_SITE != $blog_id ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Comparison OK.
					$blog_post_table       = $this->db->prefix . $blog_id . '_' . $posts_suffix;
					$new_query[ $blog_id ] = "SELECT {$blog_id} as blog_id, " . str_replace( $post_table, $blog_post_table, $matches[2] );
				}
			}
			// Hook it up!
			add_action( 'loop_start', array( $this, 'loop_start' ) );
			$new_query = implode( ' UNION ', $new_query ) . $order;
			// phpcs:ignore -- Debug only.
			// file_put_contents( '/tmp/request.txt', print_r( $new_query, true ) );
			return $new_query;
		}
		return $query;
	}
}

if ( defined( 'BLOG_ID_CURRENT_SITE' ) ) {
	$na_plugin = new NetworkAggregate();
	add_action( 'init', array( $na_plugin, 'init' ) );
}
