<?php
include_once('../includes/boot.php');
include_once('../includes/class-endpoint.php');

$api = new Multisite_JSON_API\Endpoint();

/*
 * Make sure we are given the correct JSON
 */
if(isset($api->json->blog_id) || isset($api->json->store_id)) {
	if(!isset($api->json->drop))
		$api->json->drop = false;

	/*
	 * Authenticate the user using WordPress
	 */
	$user = $api->authenticate();
	if($user) {
		/*
		 * Make sure user can actually create sites
		 */
		if($api->user_can_create_sites()) {
			error_log("Attempt to delete site with user '" . $_SERVER['HTTP_USER'] . "', but user does not have permission to manage sites in WordPress.");
			$api->error("You don't have permission to manage sites", 403);
			die();
		/*
		 * User can create sites
		 */
		} else {
			// Start killing stuff
			if (isset($api->json->blog_id)){
				$blog_id = $api->json->blog_id;
			} else if (isset($api->json->store_id)){
				$blog_id = $wpdb->get_var($wpdb->prepare("SELECT site_id FROM store_wp_site_mapping WHERE store_id = %d", $api->json->store_id));
				if (is_null($blog_id)){
					$api->error('Unable to find blog from store_id', 400);
					die();
				}
			} else {
				$api->error('No blog_id or store_id provided', 400);
				die();
			}
			try {
				$site = $api->delete_site($blog_id, $api->json->drop);
				
				try {
					$wpdb->delete('store_wp_site_mapping', 
						array('site_id' => $blog_id), 
						array('%d')
					);
				} catch (Exception $e){
					// Must not be used. Ignore.
				}
				
				$api->respond_with_json($site, 202);
			} catch(MultiSite_JSON_API\SiteNotFoundException $e) {
				$api->json_exception($e);
				die();
			}
		}
	} else {
		$api->error('Invalid Username or Password', 403);
		die();
	}
} else {
	$api->error('This endpoint needs a JSON payload of the form {"blog_id": 1, "drop": true}', 400);
}
?>
