<?php
/*
	Plugin Name: Jsonify Posts
	Plugin URI: 
	Description: Maintains a JSON encoded representation of all the posts in a blog. This is saved to a static file that can then be consumed by external sources, particularly the summary snippet in Pantheon. The JSON file generated can be found at [blog-home]/files/jsonfeeds/[blog-name].json
	Version: 0.3
	Author: Justice Addison, Tom Gillett
	Author URI: http://blogs.kent.ac.uk/webdev/
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
		// This is run every time a post is saved/updated
		add_action('save_post', array(
			$this,
			'run' 
		));

		// This responds to posts being trashed but not deleted (default interface behaviour)
		add_action('trashed_post', array(
			$this,
			'run' 
		));

		// This is required by the expires module (it seems to jump straight to deletion)
		add_action('delete_post', array(
			$this,
			'run' 
		));

        add_action('admin_menu', array(
        	$this,
        	'admin_menu'
        ));
    }
       
    /**
     * When WordPress is setting up the admin menu, add an option for super admins to clear the json feed.
     */
    public function admin_menu() {
        add_utility_page('Re-Generating Json Feed', 'Regen Json Feed', 'manage_options', 'jsonifier-regenerate', array($this, 'rebuild_feed'));
    }
    
    /**
     * This is called when a super admin clicks 'clear json feed'.
     * We grab the filename and unlink it. Also post feedback to the user.
     */
    public function rebuild_feed() {
            if ( !current_user_can( 'manage_options' ) )  {
                wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
            }

            print '<div class="wrap">';
            print '<p>Clearing Cache...</p>';
            print '<p>Deleting ' . $cache_file . '... '; 

            if($this->delete_cache()) {
                print 'Success!';
            } else {
                print 'Failed!';
            }

            print '</p></div>';

            print '<p>Re-Generating Cache...</p>';
            $this->write_cache($this->get_published_posts());
            print '<p>All finished!</p>';
    }
	

	/**
	 * The function that does all the work.
	 * Builds the cache.
	 * If an ID is specified, it attempts to add/update only the specified post.
	 * @return the outcome of $this->write_cache(), or false
	 * @author tg79
	 */
	public function run($id){
		$posts = $this->read_cache();
		$posts = $posts['posts'];

		if($id && $posts){
			switch(get_post_status($id)){
				case 'publish':
					if(($pid = wp_is_post_revision($id)) === true){
						$posts[$pid] = $this->format_post(get_post($pid));
					} else {
						$posts[$id] = $this->format_post(get_post($id));
					}
					break;

				case 'trash': // TODO: is this still necessary, given the catchall?
					if(!wp_is_post_revision($id)){
						if(isset($posts[$id])){
							unset($posts[$id]);
						}
					}
					break;

				default:
					if(isset($posts[$id])){
						unset($posts[$id]);
					}
					break;
			}
		} else {
			$posts = $this->get_published_posts();
		}

		return $this->write_cache($posts);
	}


	/**
	 * Fetches all published posts from the database.
	 * @return an array of posts, keyed by ID
	 * @author tg79
	 */
	private function get_published_posts(){
        $items = get_posts(array('post_status' => 'publish', 'numberposts' => $this->number_of_posts));

        $posts = array();
        foreach($items as $item){
        	$posts[$item->ID] = $this->format_post($item);
        }

        return $posts;
	}


	/**
	 * Writes blog info and posts to a cache file.
	 * @return return value of file_put_contents()
	 * @author tg79
	 */
	private function write_cache($posts){
		$data = array(
			'title' => get_bloginfo('name'),
			'link' => get_bloginfo('wpurl'),
			'description' => get_bloginfo('description'),
			'language' => get_bloginfo('language'),
			'slug' => get_bloginfo('slug'),

			'posts' => $posts,
		);
		
		// Convert to JSON and save to file
		$blog_json_file = $this->getJsonFileName();

		$this->delete_cache();
		return file_put_contents($blog_json_file, json_encode($data), LOCK_EX);
	}


	/**
	 * Reads blog info and posts to a cache file.
	 * @return decoded return value of file_get_contents(), or false
	 * @author tg79
	 */
	private function read_cache(){
		$blog_json_file = $this->getJsonFileName();
		if(file_exists($blog_json_file)) {
			return json_decode(file_get_contents($blog_json_file), true);
		}

		return false;
	}


	/**
	 * Deletes the cache file.
	 * @return return value of unlink()
	 * @author tg79
	 */
	private function delete_cache(){
		$blog_json_file = $this->getJsonFileName();
		return unlink($blog_json_file);
	}


	/**
	 * Formats a Post into the format expected in the JSON output
	 * @return return array representing a particular post
	 * @author tg79
	 */
	private function format_post($post){
		
		setup_postdata( $post );
		
    		$post = array(
			'id' => $post->ID,
			'title' => get_the_title($post->ID),
			'link' => get_permalink($post->ID),
			'body' => wpautop($post->post_content),
			'author' => get_the_author_meta('display_name', $post->post_author),
			'categories' => $this->getPostCategories($post->ID),
			'pubDate' => mysql2date('D, d M Y H:i:s +0000', get_post_time('Y-m-d H:i:s', true, $post->ID)),
			'siteImage' => get_the_post_thumbnail($post->ID, 'full'),
			'siteImage-thumbnail' => get_the_post_thumbnail($post->ID, 'thumbnail'),
			'excerpt' => get_the_excerpt(),
			'custom' => $this->getPostCustomFields($post->ID) 
    		);

		return $post;
	}

	
	/**
	 * get the complete file to our json path, creating the required folder in the process.
	 * 
	 * @return the complete file to our json path
	 */
	private function getJsonFileName() {
		$site_url_parts = explode( '/', get_option( 'home' ) );
		$blog_name = $site_url_parts[ count( $site_url_parts ) - 1 ];
		
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
	private function getPostCategories($id){
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
