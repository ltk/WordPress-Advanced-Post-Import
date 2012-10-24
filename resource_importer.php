<?php
/**
 * @package Resources Importer
 * @version 0.1
 */
/*
Plugin Name: Resources Importer
Plugin URI: http://thejakegroup.com
Description: Import complicated posts
Author: Lawson Kurtz
Version: 0.1
Author URI: http://thejakegroup.com/
*/
class Resource_Importer {
	private $displayName = 'Import Resources';
	public $errors = array();
	private $csv_path = false;

	public function __construct( $csv_path ) {
		$this->csv_path = $csv_path;
	}

	public function addActions() {
	    add_action('admin_menu', array(&$this, 'createAdminMenu'));
	}

	private function getPluginDisplayName() {
	    return $this->displayName;
	}

	private function getRoleOption($optionName) {
	    $roleAllowed = $this->getOption($optionName);
	    if (!$roleAllowed || $roleAllowed == '') {
	        $roleAllowed = 'Administrator';
	    }
	    return $roleAllowed;
	}

	private function getOption($optionName, $default = null) {
	    $prefixedOptionName = $this->prefix($optionName); // how it is stored in DB
	    $retVal = get_option($prefixedOptionName);
	    if (!$retVal && $default) {
	        $retVal = $default;
	    }
	    return $retVal;
	}

	protected function roleToCapability($roleName) {
	    switch ($roleName) {
	        case 'Super Admin':
	            return 'manage_options';
	        case 'Administrator':
	            return 'manage_options';
	        case 'Editor':
	            return 'publish_pages';
	        case 'Author':
	            return 'publish_posts';
	        case 'Contributor':
	            return 'edit_posts';
	        case 'Subscriber':
	            return 'read';
	        case 'Anyone':
	            return 'read';
	    }
	    return '';
	}

	private function getOptionNamePrefix() {
	    return get_class($this) . '_';
	}

	private function prefix($name) {
	    $optionNamePrefix = $this->getOptionNamePrefix();
	    if (strpos($name, $optionNamePrefix) === 0) { // 0 but not false
	        return $name; // already prefixed
	    }
	    return $optionNamePrefix . $name;
	}

	private function getDBPageSlug() {
	    return get_class($this) . 'Options';
	}

	public function createAdminMenu() {
	    $displayName = $this->getPluginDisplayName();
	    $roleAllowed = $this->getRoleOption('Administrator');
	    
	    add_submenu_page('options-general.php',
	                     'resources_importer',
	                     $displayName,
	                     $this->roleToCapability($roleAllowed),
	                     $this->getDBPageSlug(),
	                     array(&$this, 'settingsPage'));
	}

	public function settingsPage() {
	        if (!current_user_can('manage_options')) {
	            wp_die('You do not have sufficient permissions to access this page.', 'resources_importer');
	        }

	        if( isset($_GET['importing']) ) {
	        	echo "<h1>Resource Importer</h1>";
	        	$this->add_all_resources();
	        	echo "<h2>Import Errors</h2>";
	        	echo "<pre>" . print_r($this->errors, true) . "</pre>";
	        } else {
	        	echo "<h1>Resource Importer</h1>";
	        	echo "<h2><a href='?page=Resource_ImporterOptions&importing=yes' title='Start Importing'>Start Import</a></h2>";
	        }
	        
	    }

	private function csv_to_array() {
		$csv_handle = fopen( $this->csv_path, 'r' );
		$header_info = fgetcsv( $csv_handle );
		$resources_info = array();
		$resources_info[0] = $header_info;
		while( $resource_data = fgetcsv( $csv_handle ) ) {
			array_push( $resources_info, $resource_data );
		}
		
		fclose( $csv_handle );

		$resources_info = $this->prep_array_for_wp( $resources_info );

		return $resources_info;
	}

	private function prep_array_for_wp( $raw_array ) {
		$post_fields = array(
			'ID',             
			'menu_order',     
			'comment_status', 
			'ping_status',    
			'pinged',         
			'post_author',    
			'post_category',  
			'post_content',   
			'post_date',      
			'post_date_gmt',  
			'post_excerpt',   
			'post_name',      
			'post_parent',    
			'post_password',  
			'post_status',    
			'post_title',     
			'post_type',
			'tags_input',     
			'to_ping',        
			'tax_input'  
		);

		$output_array = array();

		$headers = array_shift( $raw_array );

		foreach( $raw_array as $entry ){
			$resource_array = array(
				'post' => array(),
				'post_meta' => array()
			);

			foreach( $entry as $entry_index => $entry_value ) {

				if( in_array( $headers[$entry_index], $post_fields ) ) {
					$resource_array['post'][$headers[$entry_index]] = $entry_value;
				} elseif( $headers[$entry_index] == '_attachment') {
					$resource_array['attachment'] = $entry_value;
				} elseif( $headers[$entry_index] == '_tag') {
					$resource_array['tags'][] = $entry_value;
				} else {
					$resource_array['post_meta'][$headers[$entry_index]] = $entry_value;
				}	
			}
			array_push( $output_array, $resource_array);
		}
		return $output_array;
	}

	public function add_all_resources() {
		$resources_array = $this->csv_to_array();
		if( $resources_array && ! empty( $resources_array ) ) {
			foreach( $resources_array as $resource ) {
				$this->add_resource( $resource );
			}
		} else {
			array_push( $this->errors, "No resources array could be created.");
		}
	}

	private function add_resource( $resource_data ) {
		$resource = new Resource_Record( $resource_data );
		$resource_added = $resource->insert_post();

		if( $resource_added ) {
			$resource->add_all_post_meta();
			$resource->insert_attachment();
			$resource->add_tags();
		}
		$resource_errors = $resource->errors;
		if( ! empty( $resource_errors ) ) array_push( $this->errors, $resource_errors );
	}
}

class Resource_Record extends Resource_Importer {
	private $post = false;
	private $post_meta = false;
	private $attachment = false;
	private $tags = false;
	private $post_id = 0;
	public $errors = array();

	public function __construct( $resource_info_array ) {

		if(isset( $resource_info_array['post'])) {
			$this->post = $resource_info_array['post'];

			if(isset( $resource_info_array['post_meta'])) {
				$this->post_meta = $resource_info_array['post_meta'];
			}

			if(isset( $resource_info_array['attachment'])) {
				$this->attachment = $resource_info_array['attachment'];
			}

			if(isset( $resource_info_array['tags'])) {
				$this->tags = $resource_info_array['tags'];
			}
		}
	}

	protected function insert_post() {
		$errors = array();

		if( $this->post ){
			$this->post_id = wp_insert_post( $this->post );
			if( !$this->post_id ) array_push( $this->errors, "Post titled: {$this->post['post_title']} failed to insert.");
			return ($this->post_id != 0 ? true : false);	
		}
		return false;	
	}

	protected function add_all_post_meta() {
		$errors = array();

		if( $this->post_meta && $this->post_id != 0 ) {
			foreach( $this->post_meta as $meta_key => $meta_value ) {
				$meta_added = $this->add_single_post_meta( $meta_key, $meta_value );

				if( !$meta_added ) array_push( $errors, "$meta_key meta failed for post with ID={$this->post_id}");
			}
		} else {
			array_push( $errors, "No metadata found for post with ID={$this->post_id}");
		}
		array_push( $this->errors, $errors );
	}

	private function add_single_post_meta( $meta_key, $meta_value ) {
		if( $this->post_id ){
			$meta_added = add_post_meta( $this->post_id, $meta_key, $meta_value);
			return $meta_added;
		}
		return false;
	}

	protected function insert_attachment() {
		if( $this->attachment && is_file( __DIR__ . "/attachments/" . $this->attachment ) ){

			require_once(ABSPATH . "wp-admin" . '/includes/image.php');
			require_once(ABSPATH . "wp-admin" . '/includes/file.php');
			require_once(ABSPATH . "wp-admin" . '/includes/media.php');
			

			$filepath = __DIR__ . "/attachments/" . $this->attachment;
			$wp_filetype = wp_check_filetype( basename( $filepath ), null );
			$aFile["name"] = basename( $filepath );
			$aFile["type"] = $wp_filetype;
			$aFile["tmp_name"] = $filepath;

			$attach_id = media_handle_sideload( $aFile, $this->post_id );

			return update_post_meta($this->post_id, '_thumbnail_id', $attach_id);
			
		} else {
			array_push( $this->errors, "Image file not found for post ID={$this->post_id}");
		}	

		return false;
	}

	protected function add_tags() {
		if($this->tags){
			return wp_set_object_terms( $this->post_id, $this->tags, 'post_tag' );
		}
		return false;
	}
}

function boot_up_that_resource_importer() {
	$importer = new Resource_Importer(__DIR__ . "/resources.csv");
	$importer->addActions();

	// $importer->add_all_resources();
	// echo "<pre>" . print_r($importer->errors, true) . "</pre>";
}

boot_up_that_resource_importer();


/*
// Sample post data array

$post = array(
  'ID'             => [ <post id> ] //Are you updating an existing post?
  'menu_order'     => [ <order> ] //If new post is a page, it sets the order in which it should appear in the tabs.
  'comment_status' => [ 'closed' | 'open' ] // 'closed' means no comments.
  'ping_status'    => [ 'closed' | 'open' ] // 'closed' means pingbacks or trackbacks turned off
  'pinged'         => [ ? ] //?
  'post_author'    => [ <user ID> ] //The user ID number of the author.
  'post_category'  => [ array(<category id>, <...>) ] //post_category no longer exists, try wp_set_post_terms() for setting a post's categories
  'post_content'   => [ <the text of the post> ] //The full text of the post.
  'post_date'      => [ Y-m-d H:i:s ] //The time post was made.
  'post_date_gmt'  => [ Y-m-d H:i:s ] //The time post was made, in GMT.
  'post_excerpt'   => [ <an excerpt> ] //For all your post excerpt needs.
  'post_name'      => [ <the name> ] // The name (slug) for your post
  'post_parent'    => [ <post ID> ] //Sets the parent of the new post.
  'post_password'  => [ ? ] //password for post?
  'post_status'    => [ 'draft' | 'publish' | 'pending'| 'future' | 'private' | custom registered status ] //Set the status of the new post.
  'post_title'     => [ <the title> ] //The title of your post.
  'post_type'      => [ 'post' | 'page' | 'link' | 'nav_menu_item' | custom post type ] //You may want to insert a regular post, page, link, a menu item or some custom post type
  'tags_input'     => [ '<tag>, <tag>, <...>' ] //For tags.
  'to_ping'        => [ ? ] //?
  'tax_input'      => [ array( 'taxonomy_name' => array( 'term', 'term2', 'term3' ) ) ] // support for custom taxonomies. 
);  
 */
?>