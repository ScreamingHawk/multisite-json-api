<?php

include_once('../includes/boot.php');
include_once('../includes/class-endpoint.php');

$api = new Multisite_JSON_API\Endpoint();

/*
 * Make sure we are given the correct JSON
 */
if(isset($api->json->title) && isset($api->json->email) && isset($api->json->site_name)) {
	/*
	 * Authenticate the user using WordPress
	 */
	$user = $api->authenticate();
	if($user) {
		/*
		 * Make sure user can actually create sites
		 */
		if($api->user_can_create_sites()) {
			error_log("Attempt to create site via Multisite JSON API with user '" . $_SERVER['HTTP_USER'] . "', but user does not have permission to manage sites in WordPress.");
			$api->error("You don't have permission to manage sites", 403);
			die();
		/*
		 * User can create sites
		 */
		} else {
			/*
			 * Start validating input
			 */
			$errors = array();
			// Domain is valid?
			if(!$api->is_valid_sitename($api->json->site_name)) {
				$api->error("invalid_site_name", "Invalid site_name '" . $api->json->site_name . "'", 400);
				die();
			}
			// Next check Email is valid
			if(!$api->is_valid_email($api->json->email)) {
				$api->error("invalid_email", "Invalid email address: '" . $api->json->email . "'");
				die();
			}
			// Make sure Title is valid
			if(!$api->is_valid_site_title($api->json->title)) {
				$api->error("invalid_site_title", "Invalid site title '" . $api->json->title . "'");
				die();
			}
			// Store id valid (OPTIONAL)
			if (isset($api->json->store_id)){
				if (!$api->is_valid_store_id($api->json->store_id)) {
					$api->error("invalid_store_id", "Invalid store id '" . $api->json->store_id . "'");
					die();
				}
			}

			// Start creating stuff
			try {
				$user = $api->get_or_create_user_by_email($api->json->email, $api->json->site_name);
			} catch(MultiSite_JSON_API\UserCreationError $e) {
				$api->json_exception($e);
				die();
			}
			try {
				$site = $api->create_site($api->json->title,
					$api->json->site_name,
					$user->ID);
			} catch(MultiSite_JSON_API\SiteCreationException $e) {
				$api->json_exception($e);
				die();
			}
			// Custom table for mapping sites to external information (OPTIONAL)
			if (isset($api->json->store_id)){
				try {
					$store_id = (int)$api->json->store_id;
					
					$sql = "CREATE TABLE IF NOT EXISTS store_wp_site_mapping (
							store_id BIGINT NOT NULL UNIQUE,
							site_id BIGINT NOT NULL UNIQUE);";
					require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
					dbDelta($sql);

					$wpdb->insert( 
						'store_wp_site_mapping', 
						array('store_id' => $store_id,'site_id' => $site->blog_id), 
						array('%d', '%d') 
					);
					$site->store_id = $store_id;
					
					// Create index
					$wp_db_prefix = 'wp_';
					if ($site->blog_id > 1){
						$wp_db_prefix .= $site->blog_id + '_';
					}
					$sql = "CREATE INDEX "+$wp_db_prefix+"_posts_idx_1 ON "+$wp_db_prefix+"_posts (post_password);";
					dbDelta($sql);
				} catch(MultiSite_JSON_API\SiteCreationException $e) {
					$api->json_exception($e);
					die();
				}
			}
			$api->send_site_creation_notifications($site->blog_id, $api->json->email);
			$api->respond_with_json($site, 201);
		}
	} else {
		$api->error('Invalid Username or Password', 'access_denied', 403);
		die();
	}
} else {
	$api->error('This endpoint needs a JSON payload of the form {"title": "My New Blog", "email": "user@email.com", "site_name": "my-new-blog"}',
		'invalid_parameters',
		400);
}
?>
