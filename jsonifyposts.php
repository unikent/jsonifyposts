<?php
/*
Plugin Name: Jsonify Posts
Plugin URI: 
Description: Maintains a JSON encoded representation of all the posts in a blog. This is saved to a static file that can then be consumed by external sources, particularly the summary snippet in Pantheon. The JSON file generated can be found at [blog-home]/files/jsonfeeds/[blog-name].json
Version: 0.2
Author: Justice Addison
Author URI: http://blogs.kent.ac.uk/webdev/
License: 
*/

class JsonifyPosts {
	/**
	 * The maximum number of posts to retrieve
	 */
	private $number_of_posts = 250;
	
	/**
	 * Our constructor
	 */
	public function __construct() {
		// lets add our run function to the relevant hooks
		add_action( 'save_post', array(
			$this,
			'run' 
		) );
		add_action( 'trashed_post', array(
			$this,
			'run' 
		) );
        add_action( 'admin_menu', array(
        	$this,
        	'admin_menu'
        ) );
    }
       
    /**
     * When WordPress is setting up the admin menu, add an option for super admins to clear the json feed.
     */
    public function admin_menu() {
            add_utility_page('Re-Generating Json Feed', 'Regen Json Feed', 'manage_options', 'jsonifier-regenerate', array($this, 'regen_feed'));
    }
    
    /**
     * This is called when a super admin clicks 'clear json feed'.
     * We grab the filename and unlink it. Also post feedback to the user.
     */
    public function regen_feed() {
            if ( !current_user_can( 'manage_options' ) )  {
                    wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
            }
            print '<div class="wrap">';
            print '<p>Clearing Cache...</p>';
            $cache_file = $this->getJsonFileName();
            print '<p>Deleting ' . $cache_file . '... '; 
            if (unlink($cache_file)) {
                    print 'Success!';
            } else {
                    print 'Failed!';
            }
            print '</p></div>';

            print '<p>Re-Generating Cache...</p>';
            $this->run(null);
            print '<p>All finished!</p>';
    }
	
	/**
	 * The function that does all the work.
	 * It simply creates or updates a json representation of the posts in this blog 
	 * (as an alternative to an rss feed).
	 */
	public function run( $id ) {
		// If we are doing an auto-save we don't want to run this action.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		
		// our json file
		$blog_json_file = $this->getJsonFileName();
		
		// our posts
		$blog_data = array();
		
		// if our file exists, use that rather than getting the posts from the db
		if ( file_exists( $blog_json_file ) ) {
		
			// we dont want a revision, we want the main post
			$id = wp_is_post_revision( $id ) ? wp_is_post_revision( $id ) : $id;

			$blog_data = json_decode( file_get_contents( $blog_json_file ), true );
			
			global $post;
			
			// get the post
			$post = get_post( $id );
			
			setup_postdata( $post );
			
			// (auto)drafts or trashed files should be removed
			if ( strcmp( $_POST[ 'post_status' ], 'draft' ) == 0 || strcmp( $_GET[ 'action' ], 'trash' ) == 0 || strcmp( $_GET[ 'action' ], 'auto-draft' ) == 0 ) {
				unset( $blog_data[ 'posts' ][ $post->ID ] );
			}

			// expired posts should not b published
			elseif($this->post_expired()){
				unset( $blog_data[ 'posts' ][ $post->ID ] );
			}
			
			// otherwise add/ammend the post
			else {
				$blog_data[ 'posts' ][ $post->ID ] = array(
					 'id' => $post->ID,
					'title' => get_the_title(),
					'link' => get_permalink(),
					'body' => wpautop( get_the_content() ),
					'author' => get_the_author(),
					'categories' => $this->getPostCategories( $post->ID ),
					'pubDate' => mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ),
					'siteImage' => get_the_post_thumbnail( $post->ID, 'full' ),
					'excerpt' => get_the_excerpt(),
					'custom' => $this->getPostCustomFields( $post->ID ) 
				);
			}
			
		}
		
		// otherwise generate the file from scratch
		else {
			// get the posts
			$posts = get_posts( array(
				 'numberposts' => $this->number_of_posts,
				'orderby' => 'post_date',
				'post_type' => 'post' 
			) );
			
			// add in the posts
			global $post;
			foreach ( $posts as $post ) {
				setup_postdata( $post );

				// Check for custom expiry times (some themes/plugins use it.)
				if ($this->post_expired()) {
					continue;
				}
				
				$blog_data[ 'posts' ][ $post->ID ] = array(
					 'id' => $post->ID,
					'title' => get_the_title(),
					'link' => get_permalink(),
					'body' => wpautop( get_the_content() ),
					'author' => get_the_author(),
					'categories' => $this->getPostCategories( $post->ID ),
					'pubDate' => mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ),
					'siteImage' => get_the_post_thumbnail( $post->ID, 'full' ),
					'excerpt' => get_the_excerpt(),
					'custom' => $this->getPostCustomFields( $post->ID ) 
				);
			}
		}
		
		// some general blog data
		$blog_data[ 'title' ]       = get_bloginfo( 'name' );
		$blog_data[ 'link' ]        = get_bloginfo( 'wpurl' );
		$blog_data[ 'description' ] = get_bloginfo( 'description' );
		$blog_data[ 'language' ]    = get_bloginfo( 'language' );
		$blog_data[ 'slug' ]        = get_bloginfo( 'slug' );
		
		// convert to json
		$jsoned = json_encode( $blog_data );
		
		// The final flag locks the file to prevent foos problem - maybe wrap this to check file ain't locked?
		file_put_contents( $blog_json_file, $jsoned, LOCK_EX );
	}

	/**
	 * Returns True if the current post has expired. Else False.
	 */
	private function post_expired() {
		$custom_fields = get_post_custom_values('expiration-date');
		if ($custom_fields) {
			$expiry_date = reset($custom_fields);
			if ($expiry_date && $expiry_date < time()) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * get the complete file to our json path, creating the required folder in the process.
	 * 
	 * @return the complete file to our json path
	 */
	private function getJsonFileName() {
		$site_url_parts = explode( '/', get_option( 'home' ) );
		$blog_name      = $site_url_parts[ count( $site_url_parts ) - 1 ];
		
		$upload_dir = wp_upload_dir();
		
		// create the directory to store our json if its not there
		$json_dir = $upload_dir[ 'basedir' ] . '/jsonfeeds/';
		
		if ( !is_dir( $json_dir ) ) {
			mkdir( $json_dir );
		}
		
		return $json_dir . $blog_name . '.json';
	}
	
	/**
	 * get the custom fields for a given post
	 * 
	 * @param $id The ID of the post
	 * @return The custom fields for the given post
	 */
	private function getPostCustomFields( $id ) {
		$custom = array();
		$cust   = get_post_custom( $id );
		foreach ( $cust as $k => $v ) {
			$custom[ $k ] = $v[ 0 ];
		}
		
		return $custom;
	}
	
	/**
	 * get the category names for a given post
	 * 
	 * @param $id The ID of the post
	 * @return The category names for the given post
	 */
	private function getPostCategories( $id ) {
		$cats = array();
		
		$categories = get_the_category( $id );
		
		// get the category names
		if ( !empty( $categories ) )
			foreach ( (array) $categories as $category ) {
				$cats[] = $category->name;
			}
		
		return $cats;
	}
}

$jsonifier = new JsonifyPosts();