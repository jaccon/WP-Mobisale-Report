<?php
/*
Plugin Name: Mobisale Ecommerce Reports 
Plugin URI: https://www.mobisale.com.br/
Description: Script para visualizacao de relatorios do ecommerce. 
Version: 1.1.1 
Author: Jaccon 
Author URI: https://www.github.com/jaccon/
Copyright (c) 2018  All rights reserved.
Text Domain: mobisale-reports
Domain Path: /languages/
*/

//Hooks
register_activation_hook ( __FILE__, 'sr_activate' );
register_deactivation_hook ( __FILE__, 'sr_deactivate' );

//Defining globals

$active_plugins = (array) get_option('active_plugins', array());

if (is_multisite()) {
	$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
}

if ( in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins) ) {

	$woo_version = get_option('woocommerce_version');

	if (version_compare ( $woo_version, '3.0.0', '<' )) {
		if (version_compare ( $woo_version, '2.2.0', '<' )) {

			if (version_compare ( $woo_version, '2.0', '<' )) { // Flag for Handling Woo 2.0 and above

				if (version_compare ( $woo_version, '1.4', '<' )) {
					define ( 'SR_IS_WOO13', "true" );
					define ( 'SR_IS_WOO16', "false" );
				} else {
					define ( 'SR_IS_WOO13', "false" );
					define ( 'SR_IS_WOO16', "true" );
				}
	        } else {
	        	define ( 'SR_IS_WOO16', "false" );
	        }

	        define ( 'SR_IS_WOO22', "false" );
	    } else {
	    	define ( 'SR_IS_WOO13', "false" );
	    	define ( 'SR_IS_WOO16', "false" );
	    	define ( 'SR_IS_WOO22', "true" );
	    }
	    define ( 'SR_IS_WOO30', "false" );
	} else {
		define ( 'SR_IS_WOO13', "false" );
		define ( 'SR_IS_WOO16', "false" );
		define ( 'SR_IS_WOO22', "false" );
		define ( 'SR_IS_WOO30', "true" );
	}
}

//Language loader

define ( 'SR_TEXT_DOMAIN', 'smart-reporter-for-wp-e-commerce' );
define ( 'SR_PREFIX', 'sa_smart_reporter' );
define ( 'SR_SKU', 'sr' );
define ( 'SR_PLUGIN_NAME', 'Smart Reporter for e-commerce' );

if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr.js' )) {
	define ( 'SRPRO', true );
} else {
	define ( 'SRPRO', false );
}

add_action( 'init', 'localize_smart_reporter' ); // for localization
add_action( 'init', 'sr_schedule_daily_summary_mails' ); // for summary mails
add_action( 'init', 'sr_install' ); // for creating tables

add_action('woocommerce_cart_updated', 'sr_abandoned_cart_updated'); // Action on cart updation
add_action('woocommerce_before_cart_item_quantity_zero', 'sr_abandoned_remove_cart_item'); // Action on removal of order Item
add_filter('woocommerce_order_details_after_order_table', 'sr_abandoned_order_placed'); // Action on order creation

add_filter( 'site_transient_update_plugins', 'sr_overwrite_site_transient',11,1);

add_action( 'woocommerce_order_status_changed', 'sr_woo_add_order',10,1 );	

add_action( 'activate_blog', 'sr_on_activate_blog' ); //for multisite network activate

if ( is_admin () || ( is_multisite() && is_network_admin() ) ) {
	add_action( 'woocommerce_order_actions_start', 'sr_woo_refresh_order' ); // Action to be performed on clicking 'Save Order' button from Order panel
	add_action ( 'woocommerce_order_refunded' , 'sr_woo_add_order',10,2 ); // added for handling manual refunds

	add_action( 'deleted_post', 'sr_woo_delete_order' );
	add_action( 'trashed_post', 'sr_woo_trash_order' );
	add_action( 'untrashed_post', 'sr_woo_untrash_order' );

	add_action( 'plugins_loaded', 'sr_upgrade' );

	if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr.js' )) {
		add_action( 'admin_footer', 'sr_add_support_ticket_content' );
		add_action( 'admin_footer', 'sr_add_plugin_style_script' ); //For handling media links on plugins page
	}
}

if ( defined('SRPRO') && SRPRO === true ) {
	add_action( 'admin_init', 'sa_sr_activated' );
}

function sa_sr_activated() {
    $is_check = get_option( SR_PREFIX . '_check_update', 'no' );
    if ( $is_check === 'no' ) {
      $response = wp_remote_get( 'https://www.storeapps.org/wp-admin/admin-ajax.php?action=check_update&plugin='.SR_SKU );
      update_option( SR_PREFIX . '_check_update', 'yes' );
    }
}

function sr_overwrite_site_transient( $plugin_info ) {

	$sr_license_key = get_site_option( SR_PREFIX.'_license_key' );
	$sr_download_url = get_site_option( SR_PREFIX.'_download_url' );

	if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr.js' ) && (empty($sr_license_key) || empty($sr_download_url)) ) {
		$plugin_base_file = plugin_basename( __FILE__ );

		$live_version = get_site_option( SR_PREFIX.'_live_version' );
        $installed_version = get_site_option( SR_PREFIX.'_installed_version' );

        if (version_compare( $live_version, $installed_version, '>' )) {
        	$plugin_info->response[$plugin_base_file]->package = '';
        }		
	}

	return $plugin_info;
}

// Find latest StoreApps Upgrade file
function sr_get_latest_upgrade_class() {

	$available_classes = get_declared_classes();
    $available_upgrade_classes = array_filter( $available_classes, function ( $class_name ) {
    																	return strpos( $class_name, 'StoreApps_Upgrade_' ) === 0;
																    } );
    $latest_class = 'StoreApps_Upgrade_2_2';
    $latest_version = 0;
    foreach ( $available_upgrade_classes as $class ) {
    	$exploded = explode( '_', $class );
    	$get_numbers = array_filter( $exploded, function ( $value ) {
    												return is_numeric( $value );
										    	} );
    	$version = implode( '.', $get_numbers );
    	if ( version_compare( $version, $latest_version, '>' ) ) {
    		$latest_version = $version;
    		$latest_class = $class;
    	}
    }

    return $latest_class;
}

function sr_upgrade() {
	if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr.js' )) {
		if ( ! class_exists( 'StoreApps_Upgrade_2_2' ) ) {
			require_once 'pro/class-storeapps-upgrade-2-2.php';
		}

		$latest_upgrade_class = sr_get_latest_upgrade_class();

		$sku = SR_SKU;
		$prefix = SR_PREFIX;
		$plugin_name = SR_PLUGIN_NAME;
		$documentation_link = 'https://www.storeapps.org/knowledgebase_category/smart-reporter/';
		$GLOBALS['smart_reporter_upgrade'] = new $latest_upgrade_class( __FILE__, $sku, $prefix, $plugin_name, SR_TEXT_DOMAIN, $documentation_link );

		//filters for handling quick_help_widget
		add_filter( 'sa_active_plugins_for_quick_help', 'sr_quick_help_widget', 10, 2 );
		add_filter( 'sa_is_page_for_notifications', 'sr_sa_is_page_for_notifications', 10, 2 );
	}
}

function sr_add_support_ticket_content() {

	$tab = (!empty($_GET['tab'])) ? $_GET['tab'] : '';

	if ( empty($_GET['page']) || (!empty($_GET['page']) && $_GET['page'] != 'wc-reports' && $tab != 'smart_reporter' && $tab != 'smart_reporter_old'
			&& $_GET['page'] != 'smart-reporter-wpsc') ) {
		return;
	}

	if ( class_exists( 'StoreApps_Upgrade_2_2' ) ) {
        $sr_license_key_option = get_site_option( SR_PREFIX.'_license_key' );
        $sr_license_key = (!empty($sr_license_key_option)) ? $sr_license_key_option : '';
        $sr_plugin_data = get_plugin_data( __FILE__ );
		StoreApps_Upgrade_2_2::support_ticket_content( SR_PREFIX, SR_SKU, $sr_plugin_data, $sr_license_key, SR_TEXT_DOMAIN );
	}
}

function localize_smart_reporter() {

    $text_domain = SR_TEXT_DOMAIN;

    $plugin_dirname = dirname( plugin_basename(__FILE__) );

    $locale = apply_filters( 'plugin_locale', get_locale(), $text_domain );

    $loaded = load_textdomain( $text_domain, WP_LANG_DIR . '/' . $plugin_dirname . '/' . $text_domain . '-' . $locale . '.mo' );    

    if ( ! $loaded ) {
        $loaded = load_plugin_textdomain( $text_domain, false, $plugin_dirname . '/languages/' );
    }

}

// function to handle the display of quick help widget
function sr_quick_help_widget( $active_plugins, $upgrader ) {
	
	$tab = (!empty($_GET['tab'])) ? $_GET['tab'] : '';

	if ( !empty($_GET['page']) && ( ( $_GET['page'] == 'wc-reports' && ( $tab == 'smart_reporter' || $tab == 'smart_reporter_old' ) )
			|| $_GET['page'] == 'smart-reporter-wpsc') ) {
		$active_plugins[SR_SKU] = 'smart-reporter';
	} elseif ( array_key_exists( SR_SKU, $active_plugins ) ) {
        unset( $active_plugins[SR_SKU] );
    }
        
    return $active_plugins;
}

function sr_sa_is_page_for_notifications( $is_page, $upgrader ) {

	$tab = (!empty($_GET['tab'])) ? $_GET['tab'] : '';
	
	if ( !empty($_GET['page']) && ( ( $_GET['page'] == 'wc-reports' && ( $tab == 'smart_reporter' || $tab == 'smart_reporter_old' ) ) || $_GET['page'] == 'smart-reporter-wpsc') ) {
		return true;
	}
        
    return $is_page;
}

//handle creating or tables and constants for the new network site
function sr_on_activate_blog( $site_id ) {

	global $wpdb;

	$current_blog = $wpdb->blogid;
	switch_to_blog($site_id);

	$table_name = "{$wpdb->prefix}woo_sr_cart_items";

	$active_plugins = (array) get_option('active_plugins', array());

	if (is_multisite()) {
		$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
	}

	if (function_exists('is_multisite') && is_multisite()) {
		// check if it is a network activation - if so, run the activation function for each blog id
	    if ( is_plugin_active_for_network(plugin_basename ( __FILE__ )) ) {
			if ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
				&& $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
				sr_create_tables();
			}

			sr_update_site_options();
		}
	}

	switch_to_blog($current_blog);
}

/*
* Function to to handle media links on plugin page
*/ 
function sr_add_plugin_style_script() {
?>
<script type="text/javascript">
    jQuery(function() {
        jQuery(document).ready(function() {
            jQuery('tr[id="smart-reporter-for-e-commerce"]').find( 'div.plugin-version-author-uri' ).addClass( 'sa_smart_reporter_social_links' );
        })
    });
</script>
<?php
}


// Code for custom order searches

function sr_search_join($join) {
	global $wpdb;

	if( !empty($_GET['source']) && $_GET['source'] == 'sr' ) {
    	if ( !empty($_GET['s_col']) && $_GET['s_col'] == 'order_item_name' ) {
			$join .= " JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON ($wpdb->posts.ID = oi.order_id AND oi.order_item_type = 'coupon') ";
		} else {

			$table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
				$join .= " JOIN {$wpdb->prefix}woo_sr_orders AS sro ON ($wpdb->posts.ID = sro.order_id) ";
			}
		}
	}

	return $join;
}
add_filter('posts_join_request', 'sr_search_join');


function sr_search_where($where) {
	global $wpdb;

	if( !empty($_GET['source']) && $_GET['source'] == 'sr' ) {
    	$where = " AND ( DATE($wpdb->posts.post_date) BETWEEN '". $_GET['sdate'] ."' AND '". $_GET['edate'] ."')";

		if ( !empty($_GET['s_col']) && $_GET['s_col'] == 'order_item_name' ) {
			$where .= " AND oi.". $_GET['s_col'] ." = '". $_GET['s_val'] ."'";
		} else {
			$where .= " AND sro.". $_GET['s_col'] ." = '". $_GET['s_val'] ."'";
		}
	}

	return $where;
}
add_filter('posts_where_request', 'sr_search_where');

/**
 * Registers a plugin function to be run when the plugin is activated.
 */
function sr_activate() {

    sr_update_site_options();

	if (function_exists('is_multisite') && is_multisite()) { //for multisite
		update_option( 'sr_network_activate', 1 );
	}

	// Redirect to SR
    if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
        set_transient( '_sr_activation_redirect', 1, 30 );
    }

}

/**
 * Registers a plugin function to be run when the plugin is deactivated.
 */
function sr_deactivate() {
	wp_clear_scheduled_hook( 'sr_send_summary_mails' ); //For clearing the scheduled daily summary mails event
}

function get_latest_version($plugin_file) {

	$latest_version = '';

	$sr_plugin_info = get_site_transient ( 'update_plugins' );
	// if ( property_exists($sr_plugin_info, 'response [$plugin_file]') && property_exists('response [$plugin_file]', 'new_version') ) {
	if ( property_exists($sr_plugin_info, 'response [$plugin_file]') ) {
		$latest_version = $sr_plugin_info->response [$plugin_file]->new_version;	
	}
	return $latest_version;
}

function get_user_sr_version($plugin_file) {
	$sr_plugin_info = get_plugins ();
	$user_version = $sr_plugin_info [$plugin_file] ['Version'];
	return $user_version;
}

function is_pro_updated() {
	$user_version = get_user_sr_version (SR_PLUGIN_FILE);
	$latest_version = get_latest_version (SR_PLUGIN_FILE);
	return version_compare ( $user_version, $latest_version, '>=' );
}


function sr_install() {

	global $wpdb;

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
	}


	$table_name = "{$wpdb->prefix}woo_sr_cart_items";

	$sr_network_active = (array) get_option('sr_network_activate', array());

	$active_plugins = (array) get_option('active_plugins', array());

	if (is_multisite()) {
		$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
	}

	if (function_exists('is_multisite') && is_multisite()) {
        // check if it is a network activation - if so, run the activation function for each blog id
        if ( is_plugin_active_for_network(plugin_basename ( __FILE__ )) ) { //chk for network wide active
        	$current_blog = $wpdb->blogid;
        	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}", 0 );

        	foreach ( $blog_ids as $blog_id ) {
        		switch_to_blog($blog_id);
        		if ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
        			&& $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
        			sr_create_tables();
        		}

        		if( !empty($sr_network_active) ) {
        			sr_update_site_options();
        		}
        	}
        	switch_to_blog($current_blog);

        	if( !empty($sr_network_active) ) {
    			delete_option('sr_network_activate');
    		}

        	return;
        }
    }

    if ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
    && $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
    	sr_create_tables(); // if not network_activate
	}
	
	return;
}

//function to update sites options for sync & refresh
function sr_update_site_options() {
	if ( false === get_option( 'sr_is_auto_refresh' ) ) {
        update_option( 'sr_is_auto_refresh', 'no' );
        update_option( 'sr_what_to_refresh', 'all' );
        update_option( 'sr_refresh_duration', '5' );
    }
        
	update_option( 'sr_data_sync', 1 );
	update_option( 'sr_old_data_sync', 1 );
}

//function for hadnling creation of tables
function sr_create_tables() {
	
	global $wpdb;		

	$collate = '';

	if ( $wpdb->has_cap( 'collation' ) ) {
		$collate = $wpdb->get_charset_collate();
	}

	$table_name_old = "{$wpdb->prefix}sr_woo_abandoned_items";
	$table_name_new = "{$wpdb->prefix}woo_sr_cart_items";

	if( $wpdb->get_var("SHOW TABLES LIKE '$table_name_old'") == $table_name_old ) {

		// code for renaming the 'sr_woo_abandoned_items' table
		if(  $wpdb->get_var("SHOW TABLES LIKE '$table_name_new'") == $table_name_new) {
			$wpdb->query( "DROP TABLE ".$table_name_new);
		}

		$wpdb->query("RENAME TABLE ".$table_name_old." TO ".$table_name_new.";");

		$wpdb->query("ALTER TABLE ".$table_name_new."
						CHANGE quantity qty int(10),
						CHANGE abandoned_cart_time last_update_time int(11),
						CHANGE product_abandoned cart_is_abandoned int(1);");
		
	} else {

		$query = "CREATE TABLE IF NOT EXISTS ".$table_name_new." (
						  `id` int(11) NOT NULL AUTO_INCREMENT,
						  `user_id` bigint(20) unsigned NOT NULL default '0',
						  `product_id` bigint(20) unsigned NOT NULL default '0',
						  `qty` int(10) unsigned NOT NULL default '0',
						  `cart_id` bigint(20),
						  `last_update_time` int(11) unsigned NOT NULL,
						  `cart_is_abandoned` int(1) unsigned NOT NULL default '0',
						  `order_id` bigint(20),
						  PRIMARY KEY (`id`),
						  KEY `product_id` (`product_id`),
						  KEY `user_id` (`user_id`)
						) $collate;";
		$wpdb->query( $query );
	}
}

	// function to get the compare time for cart abandonment	
	function sr_get_compare_time() {
		
		$current_time = current_time('timestamp');
		$cut_off_time = (get_option('sr_abandoned_cutoff_time')) ? get_option('sr_abandoned_cutoff_time') : 24 * 60; // 24 hours

		$cut_off_period = (get_option('sr_abandoned_cutoff_period')) ? get_option('sr_abandoned_cutoff_period') : 'minutes';

		if($cut_off_period == "hours") {
            $cut_off_time = $cut_off_time * 60;
        } elseif ($cut_off_period == "days") {
        	$cut_off_time = $cut_off_time * 24 * 60;
        }

		$cart_cut_off_time = $cut_off_time * 60;
		$compare_time = $current_time - $cart_cut_off_time;

		return $compare_time;
	}


	function sr_abandoned_remove_cart_item ($cart_item_key) {

		global $woocommerce, $wpdb;

		$table_name = "{$wpdb->prefix}woo_sr_cart_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
			return;
		}

		$user_id = get_current_user_id();
		
		$car_items_count = $woocommerce->cart->get_cart_contents_count();
		
		$cart_contents = $woocommerce->cart->cart_contents[$cart_item_key];

		$product_id = (!empty($cart_contents['variation_id'])) ? $cart_contents['variation_id'] : ((version_compare ( WOOCOMMERCE_VERSION, '2.0', '<' )) ? $cart_contents['id'] : $cart_contents['product_id']);

		$cart_update = "";

		if($car_items_count > 1) {

			$query_cart_id = "SELECT MAX(cart_id) FROM {$wpdb->prefix}woo_sr_cart_items";
			$results_cart_id = $wpdb->get_col( $query_cart_id );
			$rows_cart_id = $wpdb->num_rows;			

			if ($rows_cart_id > 0) {
				$cart_id = $results_cart_id[0] + 1;
			} else {
				$cart_id = 1;
			}

			$cart_update = ",cart_id	= ".$cart_id."";

		}

		//Updating the cart id for the removed item

		$query_max_id = "SELECT MAX(id) 
						FROM {$wpdb->prefix}woo_sr_cart_items
						WHERE user_id = ".$user_id."
						AND product_id = ".$product_id;
		$results_max_id = $wpdb->get_col( $query_max_id );				
		$results_max_id = implode (",", $results_max_id);

		$query_update_cart_id = "UPDATE {$wpdb->prefix}woo_sr_cart_items
								SET cart_is_abandoned = 1
									$cart_update
								WHERE user_id = ".$user_id."
									AND product_id = ".$product_id."
									AND id IN (".$results_max_id.")";

		$wpdb->query ($query_update_cart_id);
	}


	function sr_abandoned_order_placed($order) {
		global $woocommerce, $wpdb;

		$table_name = "{$wpdb->prefix}woo_sr_cart_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
			return;
		}

		$user_id = get_current_user_id();
		$oi_data = array();

		if( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) {
			$order_data = $order->get_data();
			$items = $order->get_items();

			if( !empty($items) ) {
				foreach ($items as $item) {
					$order_items[] = $item->get_data();
				}	
			}

			$order_id = (!empty($order_data['id'])) ? $order_data['id'] : '';

		} else {
			$order_id = $order->id;
			$order_items = $order->get_items();
		}

		if (empty($order_items)) return;

		$compare_time = sr_get_compare_time();

		foreach ( $order_items as $item ) {

			$product_id = (!empty($item['variation_id'])) ? $item['variation_id'] : ((version_compare ( WOOCOMMERCE_VERSION, '2.0', '<' )) ? $item['id'] : $item['product_id']);

			$query_abandoned = "SELECT * FROM {$wpdb->prefix}woo_sr_cart_items
								WHERE user_id = ".$user_id."
								AND product_id IN (". $product_id .")
								AND cart_is_abandoned = 0";

			$results_abandoned = $wpdb->get_results( $query_abandoned, 'ARRAY_A' );
			$rows_abandoned = $wpdb->num_rows;

			if( $compare_time > $results_abandoned[0]['last_update_time'] ) {
				$cart_is_abandoned = 1;
			} else {
				$cart_is_abandoned = 0;
			}

			if ($rows_abandoned > 0) {
				$query_update_order = "UPDATE {$wpdb->prefix}woo_sr_cart_items
									SET cart_is_abandoned = ". $cart_is_abandoned .",
										order_id = ". $order_id ."
									WHERE user_id=".$user_id."
										AND product_id IN (". $product_id .")
										AND cart_is_abandoned='0'";
				$wpdb->query( $query_update_order );
			}
		}
	}


	function sr_abandoned_cart_updated() {

		global $woocommerce, $wpdb;

		$table_name = "{$wpdb->prefix}woo_sr_cart_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
			return;
		}

		$user_id = get_current_user_id();
		$current_time = current_time('timestamp');
		
		$compare_time = sr_get_compare_time();

		$cart_contents = array();
		$cart_contents = $woocommerce->cart->cart_contents;

		//Query to get the max cart id

		$query_cart_id = "SELECT cart_id, last_update_time
							FROM {$wpdb->prefix}woo_sr_cart_items
							WHERE cart_is_abandoned = 0
								AND user_id=".$user_id;
		$results_cart_id = $wpdb->get_results( $query_cart_id, 'ARRAY_A' );
		$rows_cart_id = $wpdb->num_rows;
		
		if ($rows_cart_id > 0 && $compare_time < $results_cart_id[0]['last_update_time']) {
			$cart_id = $results_cart_id[0]['cart_id'];	
		} else {
			$query_cart_id = "SELECT MAX(cart_id) FROM {$wpdb->prefix}woo_sr_cart_items";
			$results_cart_id_max = $wpdb->get_col( $query_cart_id );
			$rows_cart_id = $wpdb->num_rows;			

			if ($rows_cart_id > 0) {
				$cart_id = $results_cart_id_max[0] + 1;
			} else {
				$cart_id = 1;
			}
		}

		foreach ($cart_contents as $key => $cart_content) {

			$product_id = ( $cart_content['variation_id'] > 0 ) ? $cart_content['variation_id'] : $cart_content['product_id'];
			
            $query_abandoned = "SELECT * FROM {$wpdb->prefix}woo_sr_cart_items
					WHERE user_id = ".$user_id."
						AND product_id IN (". $product_id .")
						AND cart_is_abandoned = 0";

			$results_abandoned = $wpdb->get_results( $query_abandoned, 'ARRAY_A' );
			$rows_abandoned = $wpdb->num_rows;


			$insert_query = "INSERT INTO {$wpdb->prefix}woo_sr_cart_items
						(user_id, product_id, qty, cart_id, last_update_time, cart_is_abandoned)
						VALUES ('".$user_id."', '".$product_id."', '".$cart_content['quantity']."','".$cart_id."', '".$current_time."', '0')";


			if ($rows_abandoned == 0) {
				
				$wpdb->query( $insert_query );

			} else if ($compare_time > $results_abandoned[0]['last_update_time']) {

				$query_ignored = "UPDATE {$wpdb->prefix}woo_sr_cart_items
						SET cart_is_abandoned = 1
						WHERE user_id=".$user_id."
							AND product_id IN (". $product_id .")";

				$wpdb->query( $query_ignored );

				//Inserting a new entry
				$wpdb->query( $insert_query );

			} else {
				$query_update = "UPDATE {$wpdb->prefix}woo_sr_cart_items
						SET qty = ". $cart_content['quantity'] .",
							last_update_time = ". $current_time ."
						WHERE user_id=".$user_id."
							AND product_id IN (". $product_id .")
							AND cart_is_abandoned='0'";
				$wpdb->query( $query_update );
			}
		}    	
    }

	function sr_schedule_daily_summary_mails() {

        global $wpdb;

        $active_plugins = (array) get_option('active_plugins', array());

		if (is_multisite()) {
			$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
		}

		if ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
        	|| (in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins)) ) {

            if ( !defined('SR_NONCE') ) {
                define ( 'SR_NONCE', wp_create_nonce( 'smart-reporter-security' ));
            }

            if ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins)) ) {
                if ( !defined('SR_NUMBER_FORMAT') ) {
                    define ( 'SR_NUMBER_FORMAT', get_option( 'sr_number_format' ));
                }
                
                if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr-summary-mails.php' )) {
                    include ('pro/sr-summary-mails.php');
                }    
            }
        }
    }

	// function to update the trash status
	function sr_woo_untrash_order( $id ) {

		$sr_nonce = (defined('SR_NONCE')) ? SR_NONCE : '';

		if ( ! wp_verify_nonce( $sr_nonce, 'smart-reporter-security' ) ) {
	 		die( 'Security check' );
	 	}

	 	global $wpdb;

		if( empty($id) ) {
			return;
		}

		$post_type = get_post_type( $id );

		if ( empty($post_type) || $post_type != 'shop_order' ) {
			return;
		}
		
		//check if the sr snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_orders";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" UPDATE {$wpdb->prefix}woo_sr_orders
										SET trash = 0
										WHERE order_id = %d OR parent_id = %d", $id, $id);

			$wpdb->query($query);
		}

		//check if the sr snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_order_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" UPDATE {$wpdb->prefix}woo_sr_order_items
										SET trash = 0
										WHERE order_id = %d", $id);

			$wpdb->query($query);
		}

	}

	// function to update the trash status
	function sr_woo_trash_order( $id ) {

		$sr_nonce = (defined('SR_NONCE')) ? SR_NONCE : '';

		if ( ! wp_verify_nonce( $sr_nonce, 'smart-reporter-security' ) ) {
	 		die( 'Security check' );
	 	}

	 	global $wpdb;

		if( empty($id) ) {
			return;
		}

		$post_type = get_post_type( $id );

		if ( empty($post_type) || $post_type != 'shop_order' ) {
			return;
		}

		//check if the sr snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_orders";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" UPDATE {$wpdb->prefix}woo_sr_orders
										SET trash = 1
										WHERE order_id = %d OR parent_id = %d", $id, $id);

			$wpdb->query($query);
		}

		//check if the sr snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_order_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" UPDATE {$wpdb->prefix}woo_sr_order_items
										SET trash = 1
										WHERE order_id = %d", $id);

			$wpdb->query($query);
		}
	}

	// function to delete the order
	function sr_woo_delete_order( $id ) {

		$sr_nonce = (defined('SR_NONCE')) ? SR_NONCE : '';

		if ( ! wp_verify_nonce( $sr_nonce, 'smart-reporter-security' ) ) {
	 		die( 'Security check' );
	 	}

	 	global $wpdb;

		if( empty($id) ) {
			return;
		}

		$post_type = get_post_type( $id );

		if ( empty($post_type) || $post_type != 'shop_order' ) {
			return;
		}

		//check if the snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_orders";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" DELETE FROM {$wpdb->prefix}woo_sr_orders WHERE order_id = %d OR parent_id = %d", $id, $id);
			$wpdb->query($query);
		}

		//check if the snapshot table exists or not
		$table_name = "{$wpdb->prefix}woo_sr_order_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$query = $wpdb->prepare(" DELETE FROM {$wpdb->prefix}woo_sr_order_items WHERE order_id = %d", $id);
			$wpdb->query($query);
		}

	}


	function sr_get_attributes_name_to_slug() {
        global $wpdb;
        
        $attributes_name_to_slug = array();
        
        $query = "SELECT DISTINCT meta_value AS product_attributes,
                         post_id AS product_id
                  FROM {$wpdb->prefix}postmeta
                  WHERE meta_key LIKE '_product_attributes'
                ";
        $results = $wpdb->get_results( $query, 'ARRAY_A' );
        $num_rows = $wpdb->num_rows;

        if ($num_rows > 0) {
        	foreach ( $results as $result ) {
                $attributes = maybe_unserialize( $result['product_attributes'] );
                if ( is_array($attributes) && !empty($attributes) ) {
                    foreach ( $attributes as $slug => $attribute ) {
                        $attributes_name_to_slug[ $result['product_id'] ][ $attribute['name'] ] = $slug;
                    }
                }
            }	
        }
        
        return $attributes_name_to_slug;
    }


	function sr_items_to_values( $all_order_items = array() ) {
        global $wpdb;

        if ( count( $all_order_items ) <= 0 || !defined( 'SR_IS_WOO16' ) || !defined( 'SR_IS_WOO22' ) || !defined( 'SR_IS_WOO30' ) ) return $all_order_items;
        $values = array();
        $attributes_name_to_slug = sr_get_attributes_name_to_slug();
        $prefix = ( (defined( 'SR_IS_WOO16' ) && SR_IS_WOO16 == "true") ) ? '' : '_';
        
        if( !empty( $all_order_items['order_date'] ) ){
        	$order_date = $all_order_items['order_date'];
        }
        
        if( !empty( $all_order_items['order_status'] ) ){
       		$order_status = $all_order_items['order_status'];
        }

        unset($all_order_items['order_date']);
        unset($all_order_items['order_status']);

        if( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) {

        	if( isset($all_order_items['post_parent']) ) {
				unset($all_order_items['post_parent']);
        	}

        	if( isset($all_order_items['post_type']) ) {
				unset($all_order_items['post_type']);
        	}
		}

        foreach ( $all_order_items as $order_id => $order_items ) {
            foreach ( $order_items as $item ) {
                    $order_item = array();

                    $order_item['order_id'] = $order_id;

                    if( ! function_exists( 'get_product' ) ) {
                        $product_id = ( !empty( $prefix ) && (!empty( $item[$prefix.'id'])) ) ? $item[$prefix.'id'] : $item['id'];
                    } else {
                    	$product_id = ( !empty($item['product_id']) ) ? $item['product_id'] : '';
                        $product_id = ( !empty( $prefix ) && ( !empty($item[$prefix.'product_id']) ) ) ? $item[$prefix.'product_id'] : $product_id;
                    }// end if

                    $order_item['product_name'] = get_the_title( $product_id );
                    $variation_id 				= ( !empty( $item['variation_id'] ) ) ? $item['variation_id'] : '';
                    $variation_id 				= ( !empty( $prefix ) && ( !empty($item[$prefix.'variation_id']) ) ) ? $item[$prefix.'variation_id'] : $variation_id;
                    $order_item['product_id'] 	= ( $variation_id > 0 ) ? $variation_id : $product_id;

                    if ( $variation_id > 0 ) {
                            $variation_name = array();
                            if( ! function_exists( 'get_product' ) && count( $item['item_meta'] ) > 0 ) {
                                foreach ( $item['item_meta'] as $items ) {
                                    $variation_name[ 'attribute_' . $items['meta_name'] ] = $items['meta_value'];
                                }
                            } else {

                            	$att_name_to_slug_prod = (!empty($attributes_name_to_slug[$product_id])) ? $attributes_name_to_slug[$product_id] : array();

                                foreach ( $item as $item_meta_key => $item_meta_value ) {
                                    if ( array_key_exists( $item_meta_key, $att_name_to_slug_prod ) ) {
                                        $variation_name[ 'attribute_' . $item_meta_key ] = ( is_array( $item_meta_value ) && ( !empty( $item_meta_value[0] ) ) ) ? $item_meta_value[0] : $item_meta_value;
                                    } elseif ( in_array( $item_meta_key, $att_name_to_slug_prod ) ) {
                                        $variation_name[ 'attribute_' . $item_meta_key ] = ( is_array( $item_meta_value ) && ( !empty( $item_meta_value[0] ) ) ) ? $item_meta_value[0] : $item_meta_value;
                                    }
                                }
                            }

                            if( defined( 'SR_IS_WOO30' ) && SR_IS_WOO30 == "true" ) {
                            	$product = wc_get_product($order_item['product_id']);	
                            	$order_item['product_name'] .= ' (' . wc_get_formatted_variation( $product, true ) . ')';
                            } else {
                            	$order_item['product_name'] .= ' (' . woocommerce_get_formatted_variation( $variation_name, true ) . ')';
                            }
                    }

                    $qty 						= ( !empty( $item['qty'] ) ) ? $item['qty']: '';
                    $qty 						= ( (defined( 'SR_IS_WOO30' ) && SR_IS_WOO30 == "true") && !empty( $item['quantity'] ) ) ? $item['quantity']: $qty;
                    $order_item['quantity'] 	= ( !empty( $prefix ) && ( !empty($item[$prefix.'qty']) ) ) ? $item[$prefix.'qty'] : $qty;
                    $line_total             	= ( !empty( $item['line_total'] ) ) ? $item['line_total'] : '' ;
                    $line_total             	= ( (defined( 'SR_IS_WOO30' ) && SR_IS_WOO30 == "true") && !empty( $item['total'] ) ) ? $item['total']: $line_total;
                    $line_total             	= ( !empty( $prefix ) && ( !empty($item[$prefix.'line_total']) ) ) ? $item[$prefix.'line_total'] : $line_total;
                    $order_item['sales']    	= $line_total;
                    $line_subtotal          	= ( !empty( $item['line_subtotal'] ) ) ? $item['line_subtotal'] : '';
                    $line_subtotal          	= ( (defined( 'SR_IS_WOO30' ) && SR_IS_WOO30 == "true") && !empty( $item['subtotal'] ) ) ? $item['subtotal']: $line_subtotal;
                    $line_subtotal              = ( !empty( $prefix ) && ( !empty($item[$prefix.'line_subtotal']) ) ) ? $item[$prefix.'line_subtotal'] : $line_subtotal;
                    $order_item['order_date']   = ( !empty($item['order_date'])) ? $item['order_date'] : $order_date;
                    $order_item['order_status'] = ( !empty($item['order_status'])) ? $item['order_status'] : $order_status;
                    $order_item['discount']     = $line_subtotal - $line_total;

                    if(!empty($item['sku'])) {
                    	$order_item['sku'] = $item['sku'];
                    } else {
                		$prod_sku = get_post_meta($product_id, '_sku' , true);
                	    $order_item['sku'] = !empty($prod_sku) ? $prod_sku: '';
                	}

                    if(!empty($item['category'])) {
                    	$order_item['category'] = $item['category'];
                    } else {
                		$category = get_the_terms($product_id, 'product_cat');
                	    $order_item['category'] = !empty( $category ) ? $category[0]->name : '';
                    }

                    if ( empty( $order_item['product_id'] ) || empty( $order_item['order_id'] ) || empty( $order_item['quantity'] ) ) 
                        continue;
                    $values[] = "( " .$wpdb->_real_escape($order_item['product_id']). ", " .$wpdb->_real_escape($order_item['order_id']). ",'" .$wpdb->_real_escape($order_item['order_date']). "', '" .$wpdb->_real_escape($order_item['order_status']). "', '" .$wpdb->_real_escape($order_item['product_name']). "', '" .$wpdb->_real_escape($order_item['sku']). "' , '" .$wpdb->_real_escape($order_item['category']). "' , " .$wpdb->_real_escape($order_item['quantity']). ", " . (empty($order_item['sales']) ? 0 : $wpdb->_real_escape($order_item['sales']) ) . ", " . (empty($order_item['discount']) ? 0 : $wpdb->_real_escape($order_item['discount']) ) . " )";
            }
        }

        return $values;
    }

	function sr_woo_add_order( $order_id, $refund_id = '' ) {

       global $wpdb;

		$oi_data = array();

		// For handling manual refunds
    	if(!empty( $refund_id )) {
    		$order_id = $refund_id;
    		$order = new WC_Order( $order_id );
    	} else {
    		$order = new WC_Order( $order_id );
    	}

		if( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) {
			$order_data = $order->get_data();
			$order_status = (!empty($order_data['status'])) ? $order_data['status'] : '';
			$order_date = $order->get_date_created()->date('Y-m-d H:i:s');

			$items = $order->get_items();

			if( !empty($items) ) {
				foreach ($items as $item) {
					$oi_data[] = $item->get_data();
				}	
			}
		} else {
			$oi_data = $order->get_items();
			$order_status = $order->post_status;
			$order_date = $order->order_date;
		}

		$order_items = array( $order_id => $oi_data );

		$order_items['order_date'] = $order_date;
		$order_items['order_status'] = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? 'wc-'. $order_status : $order_status;
		
		if( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) {
			$post_type = get_post_type($order_id);
			$order_items['post_parent'] = (!empty($order_data['parent_id'])) ? $order_data['parent_id'] : 0;
			$order_items['post_type'] = (!empty($post_type)) ? $post_type : 'shop_order';
		}

		$order_is_sale = 1;

		//Condn for woo 2.2 compatibility
		if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") ) {
			$order_status = substr($order_status, 3);
 		} else if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") ) {
			$order_status = wp_get_object_terms( $order_id, 'shop_order_status', array('fields' => 'slugs') );
			$order_status = (!empty($order_status)) ? $order_status[0] : '';
		}

		//chk if the SR Beta Snapshot table exists or not
	    $table_name = "{$wpdb->prefix}sr_woo_order_items";
	    if(  $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
			if ( $order_status == 'on-hold' || $order_status == 'processing' || $order_status == 'completed' ) {
				$insert_query = "REPLACE INTO {$wpdb->prefix}sr_woo_order_items 
							( `product_id`, `order_id`, `order_date`, `order_status`, `product_name`, `sku`, `category`, `quantity`, `sales`, `discount` ) VALUES ";
	            
	            $values = sr_items_to_values( $order_items );
	            if ( count( $values ) > 0 ) {
	            	$insert_query .= implode(",",$values);
	                $wpdb->query( $insert_query );
	            }

			} else {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}sr_woo_order_items WHERE order_id = {$order_id}" );
				$order_is_sale = 0;
			}
		}

        //chk if the SR Beta Snapshot table exists or not
	    $orders_table_name = "{$wpdb->prefix}woo_sr_orders";
	    $items_table_name = "{$wpdb->prefix}woo_sr_order_items";
	    if( $wpdb->get_var("SHOW TABLES LIKE '$orders_table_name'") == $orders_table_name 
	    	&& $wpdb->get_var("SHOW TABLES LIKE '$items_table_name'") == $items_table_name ) {

	    	$oi_type = (!empty( $refund_id )) ? 'R' : 'S';

	    	if( defined('SR_IS_WOO30') && SR_IS_WOO30 == "false" ) {
	    		$order_items = $order->get_items( array('line_item', 'shipping') );
	    	}

	    	$order_meta = get_post_meta($order_id);
	    	$order_sm = $order->get_shipping_methods();

	    	if( (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") && (is_object($order_sm) && (count(get_object_vars($order_sm)) > 0)) ) {
	    		$order_sm_data = $order_sm->get_data();
	    		if( !empty($order_sm_data) ) {
	    			foreach ($order_sm_data as $sm_data) {

	    				$o_id = (!empty($sm_data['order_id'])) ? $sm_data['order_id'] : '';

	    				if( empty($order_items[$o_id]) ) {
	    					continue;
	    				}

	    				$order_items[$o_id][] = array( 'type' => 'shipping',
	    												'sm_id' => (!empty($sm_data['method_id'])) ? $sm_data['method_id'] : '' );
	    			}
	    		}
	    	}

	    	$oi_values = array();
	    	$t_qty = 0;
	    	$sr_id = '';

    		$order_date = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($order_items['order_date'])) ? $order_items['order_date'] : '' ) : $order->order_date;
    		$post_status = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($order_items['order_status'])) ? $order_items['order_status'] : '' ) : $order->post_status;
    		$post_type = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($order_items['post_type'])) ? $order_items['post_type'] : '' ) : $order->post->post_type;
    		$post_parent = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($order_items['post_parent'])) ? $order_items['post_parent'] : 0 ) : $order->post->post_parent;

	    	$order_items = ( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) ? ( (!empty($order_items[$order_id])) ? $order_items[$order_id] : array()) : $order_items;

	    	foreach ( $order_items as $oi_id => $item ) {

	    		$qty = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($item['quantity'])) ? $item['quantity'] : 0 ) : ( (!empty($item['qty'])) ? $item['qty'] : 0 );
	    		$total = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($item['total'])) ? $item['total'] : 0 ) : ( (!empty($item['line_total'])) ? $item['line_total'] : 0 );
	    		$item_id = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ? ( (!empty($item['id'])) ? $item['id'] : 0 ) : $oi_id;

	    		if ( !empty($item['type']) && $item['type'] == 'shipping' ) {
	    			$sr_id = ( !empty($item['item_meta']['method_id'][0]) ) ? $item['item_meta']['method_id'][0] : '';

	    			if ( defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) {
						$sr_id = ( !empty($item['sm_id']) ) ? $item['sm_id'] : $sr_id;
	    			}

	    		} else {
	    			$t_qty += $qty;
		    		
		    		$oi_values[] = "( ". $wpdb->_real_escape($item_id) .", '". $wpdb->_real_escape(substr($order_date,0,10) ) ."',
		    							'". $wpdb->_real_escape(substr($order_date,12) ) ."', ". $wpdb->_real_escape($order_is_sale) .", 
		    							". $wpdb->_real_escape($item['product_id']) .", ". $wpdb->_real_escape(!empty($item['variation_id']) ? $item['variation_id'] : 0) .",
		    						 	". $wpdb->_real_escape($order_id) .", '". $wpdb->_real_escape($oi_type) ."', ". $wpdb->_real_escape($qty) .",
		    						 	". $wpdb->_real_escape($total) ." )";
	    		}
	    	}

	    	$query = "REPLACE INTO {$wpdb->prefix}woo_sr_orders 
						( `order_id`, `created_date`, `created_time`, `status`, `type`, `parent_id`, `total`, `currency`, `discount`, `cart_discount`, `shipping`, 
							`shipping_tax`, `shipping_method`, `tax`, `qty`, `payment_method`, `user_id`, `billing_email`,
							`billing_country`, `customer_name` ) VALUES
							( ". $wpdb->_real_escape($order_id) .", '". $wpdb->_real_escape(substr($order_date,0,10) ) ."',
							'". $wpdb->_real_escape(substr($order_date,12) ) ."', '". $wpdb->_real_escape($post_status) ."',
							'". $wpdb->_real_escape($post_type) ."', ". $wpdb->_real_escape($post_parent) .", 
							". $wpdb->_real_escape( !empty($order_meta['_order_total'][0]) ? $order_meta['_order_total'][0] : 0) .",
							'". $wpdb->_real_escape(!empty($order_meta['_order_currency'][0]) ? $order_meta['_order_currency'][0] : '') ."', 
							". $wpdb->_real_escape(!empty($order_meta['_order_discount'][0]) ? $order_meta['_order_discount'][0] : 0) .",
							". $wpdb->_real_escape(!empty($order_meta['_cart_discount'][0]) ? $order_meta['_cart_discount'][0] : 0) .",
							". $wpdb->_real_escape(!empty($order_meta['_order_shipping'][0]) ? $order_meta['_order_shipping'][0] : 0) .", 
							". $wpdb->_real_escape(!empty($order_meta['_order_shipping_tax'][0]) ? $order_meta['_order_shipping_tax'][0] : 0) .",
							'". $wpdb->_real_escape( $sr_id ) ."', 
							". $wpdb->_real_escape(!empty($order_meta['_order_tax'][0]) ? $order_meta['_order_tax'][0] : 0) .", 
							". $wpdb->_real_escape((!empty($t_qty)) ? $t_qty : 1) .",
							'". $wpdb->_real_escape(!empty($order_meta['_payment_method'][0]) ? $order_meta['_payment_method'][0] : '') ."', 
							". $wpdb->_real_escape(!empty($order_meta['_customer_user'][0]) ? $order_meta['_customer_user'][0] : 0) .",
							'". $wpdb->_real_escape(!empty($order_meta['_billing_email'][0]) ? $order_meta['_billing_email'][0] : '') ."', 
							'". $wpdb->_real_escape(!empty($order_meta['_billing_country'][0]) ? $order_meta['_billing_country'][0] : '') ."',
							'". $wpdb->_real_escape(!empty($order_meta['_billing_first_name'][0]) ? $order_meta['_billing_first_name'][0] : '') .' '. $wpdb->_real_escape(!empty($order_meta['_billing_last_name'][0]) ? $order_meta['_billing_last_name'][0] : '') ."' ) ";	

			$wpdb->query( $query );

			$query = "REPLACE INTO {$wpdb->prefix}woo_sr_order_items
							( `order_item_id`, `order_date`, `order_time`, `order_is_sale`, `product_id`, `variation_id`, `order_id`, `type`,
							`qty`, `total` ) VALUES ";

			if ( count($oi_values) > 0 ) {
				$query .= implode(',',$oi_values);
				$wpdb->query( $query );
			}
	    }
    }

/**
 * Throw an error on admin page when WP e-Commerece plugin is not activated.
 */
if ( is_admin () || ( is_multisite() && is_network_admin() ) ) {
	// BOF automatic upgrades
	// if (!function_exists('wp_get_current_user')) {
 //        require_once (ABSPATH . 'wp-includes/pluggable.php'); // Sometimes conflict with SB-Welcome Email Editor
 //    }
	
	$plugin = plugin_basename ( __FILE__ );
	define ( 'SR_PLUGIN_DIR',dirname($plugin));
	define ( 'SR_PLUGIN_DIR_ABSPATH', dirname(__FILE__) );
	define ( 'SR_PLUGIN_FILE', $plugin );
	if (!defined('STORE_APPS_URL')) {
		define ( 'STORE_APPS_URL', 'https://www.storeapps.org/' );	
	}
	
	define ( 'SR_ADMIN_URL', get_admin_url () ); //defining the admin url
	define ( 'SR_PLUGIN_DIRNAME', plugins_url ( '', __FILE__ ) );
	define ( 'SR_IMG_URL', SR_PLUGIN_DIRNAME . '/resources/themes/images/' );        
	
	add_action ( 'admin_notices', 'sr_admin_notices' );
	add_action ( 'admin_init', 'sr_admin_init' );
	add_action ( 'admin_enqueue_scripts', 'sr_admin_scripts' );
	add_action ( 'admin_enqueue_scripts', 'sr_admin_styles' );
	add_action ( 'wp_ajax_sr_get_stats', 'sr_get_stats' );
	add_action ( 'wp_ajax_sr_klawoo_subscribe', 'sr_klawoo_subscribe' );

	if ( is_multisite() && is_network_admin() ) {
		
		function sr_add_license_key_page() {
			$page = add_submenu_page ('settings.php', 'Smart Reporter', 'Smart Reporter', 'manage_options', 'sr-settings', 'sr_settings_page' );
			add_action ( 'admin_print_styles-' . $page, 'sr_admin_styles' );
		}
		
		if (file_exists ( (dirname ( __FILE__ )) . '/pro/sr.js' ))
			add_action ('network_admin_menu', 'sr_add_license_key_page', 11);
			
	}


	// add_action('woocommerce_cart_updated', 'sr_demo');

	$sr_plugin_info = $ext_version ='';

	function sr_admin_init() {

		global $wpdb;

		$plugin_info 	= get_plugins ();
		$sr_plugin_info = $plugin_info [SR_PLUGIN_FILE];
		$ext_version 	= '4.0.1';

		$active_plugins = (array) get_option('active_plugins', array());

		if (is_multisite()) {
			$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
		}

		if ( ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
        	&& ( defined('WPSC_URL') && (in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins)) ) ) 
			|| (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins)) 
			) {
				define('SR_WOO_ACTIVATED', true);
		} elseif ( defined('WPSC_URL') && (in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins)) ) {
			define('SR_WPSC_ACTIVATED',true);
		}

		if ( ( isset($_GET['post_type']) && $_GET['post_type'] == 'wpsc-product') || ( isset($_GET['page']) && $_GET['page'] == 'smart-reporter-wpsc')) {
			if (!defined('SR_WPSC_RUNNING')) {
				define('SR_WPSC_RUNNING', true);	
			}
			
			if (!defined('SR_WOO_RUNNING')) {
				define('SR_WOO_RUNNING', false);
			}
			// checking the version for WPSC plugin

			if (!defined('SR_IS_WPSC37')) {
				define ( 'SR_IS_WPSC37', version_compare ( WPSC_VERSION, '3.8', '<' ) );
			}

			if (!defined('SR_IS_WPSC38')) {
				define ( 'SR_IS_WPSC38', version_compare ( WPSC_VERSION, '3.8', '>=' ) );
			}

			if ( SR_IS_WPSC38 ) {		// WPEC 3.8.7 OR 3.8.8
				if (!defined('SR_IS_WPSC387')) {
					define('SR_IS_WPSC387', version_compare ( WPSC_VERSION, '3.8.8', '<' ));
				}

				if (!defined('SR_IS_WPSC388')) {
					define('SR_IS_WPSC388', version_compare ( WPSC_VERSION, '3.8.8', '>=' ));
				}
			}
		} else if ( ( isset($_GET['page']) && $_GET['page'] == 'wc-reports') )  {
			
			if (!defined('SR_WPSC_RUNNING')) {
				define('SR_WPSC_RUNNING', false);
			}

			if (!defined('SR_WOO_RUNNING')) {
				define('SR_WOO_RUNNING', true);
			}
			
		}

		$json_filename = '';

		if ( defined('SR_WPSC_ACTIVATED') && SR_WPSC_ACTIVATED === true ) {
			$json_filename = 'json';
		} else if ( defined('SR_WOO_ACTIVATED') && SR_WOO_ACTIVATED === true ) {
			if (isset($_GET['view']) && $_GET['view'] == "smart_reporter_old") {
				$json_filename = 'json-woo';
			} else {
				$json_filename = 'json-woo-beta';
			}

			//WooCommerce Currency Constants
			define ( 'SR_CURRENCY_SYMBOL', get_woocommerce_currency_symbol());
			define ( 'SR_CURRENCY_POS' , get_woocommerce_price_format());
			define ( 'SR_DECIMAL_PLACES', get_option( 'woocommerce_price_num_decimals' ));
		}
		define ( 'SR_JSON_FILE_NM', $json_filename );

		if ( defined('SRPRO') && SRPRO === true ) {
			include ('pro/sr-settings.php');

			//wp-ajax action
			if (is_admin() ) {
	            add_action ( 'wp_ajax_top_ababdoned_products_export', 'sr_top_ababdoned_products_export' );
	            add_action ( 'wp_ajax_sr_save_settings', 'sr_save_settings' );
	            add_action ( 'wp_ajax_sr_send_test_mail', 'sr_send_summary_mails' );
	        }			
		} else {
			if ( is_admin() ) {
				if(isset($_GET['sr_dismiss_admin_notice']) && $_GET['sr_dismiss_admin_notice'] == '1'){
		            update_option('sr_dismiss_admin_notice', true);
		            wp_redirect($_SERVER['HTTP_REFERER']);
		        } else if ( !get_option('sr_dismiss_admin_notice') ) { // Code to handle SM IN App Promo
				}
		    }
		}

		//adding the SR dashboard widget
	    $table_name = "{$wpdb->prefix}woo_sr_orders";
		if ( defined('SR_WOO_ACTIVATED') && SR_WOO_ACTIVATED === true && ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) && current_user_can( 'view_woocommerce_reports' ) ) {
	    	add_action( 'wp_dashboard_setup', 'sr_wp_dashboard_widget' );
	    }

	    // code for handling redirection on activation
		if ( get_transient( '_sr_activation_redirect' ) ) {

	    	// Delete the redirect transient
			delete_transient( '_sr_activation_redirect' );

	    	if ( defined('SR_WOO_ACTIVATED') && SR_WOO_ACTIVATED === true ) {
	    		
	    		if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ) {
	    			wp_redirect( admin_url('admin.php?page=wc-reports&tab=smart_reporter') );
	    		} else if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") ) {
	    			wp_redirect( admin_url('admin.php?page=wc-reports&tab=smart_reporter&view=smart_reporter_old') );
	    		}
	    		
	    	} else if ( defined('SR_WPSC_ACTIVATED') && SR_WPSC_ACTIVATED === true ) {
	    		wp_redirect( admin_url('edit.php?post_type=wpsc-product&page=smart-reporter-wpsc') );
	    	}
	    }
	}
	

	// Function for klawoo subscribe
	function sr_klawoo_subscribe() {
        $url = 'http://app.klawoo.com/subscribe';

        if( !empty( $_POST ) ) {
            $params = $_POST;
        } else {
            exit();
        }

        if( empty($params['name']) ) {
        	$params['name'] = '';
        }

        $method = 'POST';
        $qs = http_build_query( $params );

        $options = array(
            'timeout' => 15,
            'method' => $method
        );

        if ( $method == 'POST' ) {
            $options['body'] = $qs;
        } else {
            if ( strpos( $url, '?' ) !== false ) {
                $url .= '&'.$qs;
            } else {
                $url .= '?'.$qs;
            }
        }

        $response = wp_remote_request( $url, $options );
        if ( wp_remote_retrieve_response_code( $response ) == 200 ) {
            $data = $response['body'];
            if ( $data != 'error' ) {
                $message_start = substr( $data, strpos( $data,'<body>' ) + 6 );
                $remove = substr( $message_start, strpos( $message_start,'</body>' ) );
                $message = trim( str_replace( $remove, '', $message_start ) );

                update_option('sr_dismiss_admin_notice', true); // for hiding the promo message

                echo ( $message );
                exit();                
            }
        }
        exit();
    }

	// in_array( 'wp-e-commerce/wp-shopping-cart.php', apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ) )
	function sr_admin_notices() {

		$active_plugins = (array) get_option('active_plugins', array());

		if (is_multisite()) {
			$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
		}

		if ( ! ( (in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins))
        	|| (in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins)) ) ) {

			echo '<div id="notice" class="error"><p>';
			_e ( '<b>Smart Reporter</b> add-on requires <a href="https://www.storeapps.org/wpec/">WP e-Commerce</a> plugin or <a href="https://www.storeapps.org/woocommerce/">WooCommerce</a> plugin. Please install and activate it.' );
			echo '</p></div>', "\n";
		}
	}
	
	function sr_admin_scripts() {

		global $sr_plugin_info, $ext_version;

		if ( !wp_script_is( 'jquery' ) ) {
            wp_enqueue_script( 'jquery' );
        }

        $enqueue = 0; //flag for handling enqueue of scripts

        $ver = (!empty($sr_plugin_info ['Version'])) ? $sr_plugin_info ['Version'] : '';

        // condition for SR_Beta
		if ( ( isset($_GET['page']) && $_GET['page'] == 'wc-reports') && empty($_GET['view']) && ((defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true")) ) {

	        wp_register_script ( 'sr_jvectormap', plugins_url ( 'resources/jvectormap/jquery-jvectormap-1.2.2.min.js', __FILE__ ), array ('jquery' ));
	        wp_register_script ( 'sr_jvectormap_world_map', plugins_url ( 'resources/jvectormap/jquery-jvectormap-world-mill-en.js', __FILE__ ), array ('sr_jvectormap' ));
	        wp_register_script ( 'sr_magnific_popup', plugins_url ( 'resources/magnific-popup/jquery.magnific-popup.js', __FILE__ ), array ('sr_jvectormap_world_map' ));
	        wp_register_script ( 'sr_main', plugins_url ( 'resources/chartjs/Chart.min.js', __FILE__ ), array ('sr_magnific_popup' ), $ver);

	        $enqueue = 1;

		} elseif ( ((defined('SR_WOO_RUNNING') && SR_WOO_RUNNING === true) && ((!empty($_GET['view']) && $_GET['view'] == "smart_reporter_old") || ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") )) ) 
				|| ((defined('SR_WPSC_RUNNING') && SR_WPSC_RUNNING === true) && ( isset($_GET['page']) && $_GET['page'] == 'smart-reporter-wpsc')) ) {

			wp_register_script ( 'sr_ext_all', plugins_url ( 'resources/ext/ext-all.js', __FILE__ ), array ('jquery'), $ext_version );

			if ( defined('SR_WPSC_RUNNING') && SR_WPSC_RUNNING === true ) {
				wp_register_script ( 'sr_main', plugins_url ( '/sr/smart-reporter.js', __FILE__ ), array ('sr_ext_all' ), $ver );
			} else if ( (defined('SR_WOO_RUNNING') && SR_WOO_RUNNING === true) && ( (!empty($_GET['view']) && $_GET['view'] == "smart_reporter_old") || ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") ) ) ) {
				wp_register_script ( 'sr_main', plugins_url ( '/sr/smart-reporter-woo.js', __FILE__ ), array ('sr_ext_all' ), $ver );	
			}

			$enqueue = 1;

		}

		if( $enqueue == 1 ) {
			if ( defined('SRPRO') && SRPRO === true ) {
				wp_register_script ( 'sr_functions', plugins_url ( '/pro/sr.js', __FILE__ ), array ('sr_main' ), $ver );
				wp_enqueue_script ( 'sr_functions' );
			} else {
				wp_enqueue_script ( 'sr_main' );
			}	
		}
		
	}
	
	function sr_admin_styles() {

		global $sr_plugin_info, $ext_version;

		$deps = '';
		$enqueue = 0; //flag for handling enqueue of styles

		// condition for SR_Beta
		if ( ( isset($_GET['page']) && $_GET['page'] == 'wc-reports') && empty($_GET['view']) && ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ) ) {
			wp_register_style ( 'font_awesome', plugins_url ( "resources/font-awesome/css/font-awesome.min.css", __FILE__ ), array ());
			wp_register_style ( 'sr_jvectormap', plugins_url ( 'resources/jvectormap/jquery-jvectormap-1.2.2.css', __FILE__ ), array ('font_awesome'));
			wp_register_style ( 'sr_magnific_popup', plugins_url ( 'resources/magnific-popup/magnific-popup.css', __FILE__ ), array ('sr_jvectormap'));

			$deps = array('sr_magnific_popup');

			$enqueue = 1;

		} elseif ( ((defined('SR_WOO_RUNNING') && SR_WOO_RUNNING === true) && 
				((!empty($_GET['view']) && $_GET['view'] == "smart_reporter_old") || ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") ) ) )
				 || (defined('SR_WPSC_RUNNING') && SR_WPSC_RUNNING === true) ) {
			wp_register_style ( 'sr_ext_all', plugins_url ( 'resources/css/ext-all.css', __FILE__ ), array (), $ext_version );
			$deps = array('sr_ext_all');

			$enqueue = 1;
		}
			
		$ver = (!empty($sr_plugin_info ['Version'])) ? $sr_plugin_info ['Version'] : '';

		if ( $enqueue == 1 ) {
			wp_register_style ( 'sr_main', plugins_url ( '/sr/smart-reporter.css', __FILE__ ), $deps, $ver );
			wp_enqueue_style ( 'sr_main' );	

			echo '<style>
					/*For hiding the update notices on Smart Reporter page*/
					.update-nag, .updated, .error { 
					  display: none; 
					}
				</style>';

		}
		
	}
	
	function woo_add_modules_sr_admin_pages($wooreports) {

		$reports = array();
		$reports['smart_reporter'] = array( 
											'title'  	=> __( 'Relatórios'),
											'reports' 	=> array(
																"smart_reporter" => array(
																									'title'       => '',
																									'description' => '',
																									'hide_title'  => true,
																									'callback'    => 'sr_admin_page'
																								)
																)
										);

		$wooreports = array_merge($reports,$wooreports);
		return $wooreports;

	}
	add_filter( 'woocommerce_admin_reports', 'woo_add_modules_sr_admin_pages', 10, 1 );



	function sr_customize_tab(){
		?>
		<script type="text/javascript">
			jQuery(function($) {
				$('.icon32-woocommerce-reports').parent().find('.nav-tab-wrapper').find('a[href$="tab=smart_reporter"]').prepend('<img alt="Smart Reporter" src="<?php echo SR_IMG_URL."logo.png";?>" style="width:23px;height:23px;margin-right:4px;vertical-align:middle">');
			});

		</script>
		<?php
	}

	// add_action('wc_reports_tabs','sr_customize_tab');
	
	function sr_admin_page(){
        global $woocommerce;

    	$view = ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "false") && (defined('SR_IS_WOO30') && SR_IS_WOO30 == "false") ) ? 'smart_reporter_old' : ( !empty($_GET['view'] )  ? ( $_GET['view'] ) : 'smart_reporter_beta' );

        switch ($view) {
            case "smart_reporter_old" :
                sr_console_common();
            break;
            default :
            	sr_beta_show_console();
            break;
        }
    }
    

	function wpsc_add_modules_sr_admin_pages($page_hooks, $base_page) {
		$page = add_submenu_page ( $base_page, 'Smart Reporter', 'Smart Reporter', 'manage_options', 'smart-reporter-wpsc', 'sr_console_common' );
		add_action ( 'admin_print_styles-' . $page, 'sr_admin_styles' );
		// if ( $_GET ['action'] != 'sr-settings') { // not be include for settings page
		if ( !isset($_GET ['action']) ) { // not be include for settings page
			add_action ( 'admin_print_scripts-' . $page, 'sr_admin_scripts' );
		}
		$page_hooks [] = $page;
		return $page_hooks;
	}
	add_filter ( 'wpsc_additional_pages', 'wpsc_add_modules_sr_admin_pages', 10, 2 );

	function sr_woo_refresh_order( $order_id ) {
		sr_woo_remove_order( $order_id );

		//Condn for woo 2.2 compatibility
		if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ) {
			$order_status = substr(get_post_status( $order_id ), 3);
		} else {
			$order_status = wp_get_object_terms( $order_id, 'shop_order_status', array('fields' => 'slugs') );
			$order_status = (!empty($order_status)) ? $order_status[0] : '';
		}

		if ( $order_status == 'on-hold' || $order_status == 'processing' || $order_status == 'completed' ) {
			sr_woo_add_order( $order_id );
		}
	}
        
        
        function sr_get_term_name_to_slug( $taxonomy_prefix = '' ) {
            global $wpdb;
            
            if ( !empty( $taxonomy_prefix ) ) {
                $where = "WHERE term_taxonomy.taxonomy LIKE '$taxonomy_prefix%'";
            } else {
                $where = '';
            }
            
            $query = "SELECT terms.slug, terms.name, term_taxonomy.taxonomy
                      FROM {$wpdb->prefix}terms AS terms
                          LEFT JOIN {$wpdb->prefix}term_taxonomy AS term_taxonomy USING ( term_id )
                      $where
                    ";
            $results = $wpdb->get_results( $query, 'ARRAY_A' );
            $num_rows = $wpdb->num_rows;

            $term_name_to_slug = array();

            if ($num_rows > 0) {
            	foreach ( $results as $result ) {
	                if ( count( $result ) <= 0 ) continue;
	                if ( !isset( $term_name_to_slug[ $result['taxonomy'] ] ) ) {
	                    $term_name_to_slug[ $result['taxonomy'] ] = array();
	                }
	                $term_name_to_slug[ $result['taxonomy'] ][ $result['name'] ] = $result['slug'];
	            }	
            }
            
            return $term_name_to_slug;
        }
	
        function sr_get_variation_attribute( $order_id ) {
            
                global  $wpdb;
                $query_variation_ids = "SELECT order_itemmeta.meta_value
                                        FROM {$wpdb->prefix}woocommerce_order_items AS order_items
                                        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta
                                        ON (order_items.order_item_id = order_itemmeta.order_item_id)
                                        WHERE order_itemmeta.meta_key LIKE '_variation_id'
                                        AND order_itemmeta.meta_value > 0
                                        AND order_items.order_id IN ($order_id)";
                                        
                $result_variation_ids  = $wpdb->get_col ( $query_variation_ids );

                $query_variation_att = "SELECT postmeta.post_id AS post_id,
                                        GROUP_CONCAT(postmeta.meta_value
                                        ORDER BY postmeta.meta_id
                                        SEPARATOR ', ' ) AS meta_value
                                        FROM {$wpdb->prefix}postmeta AS postmeta
                                        WHERE postmeta.meta_key LIKE 'attribute_%'
                                        AND postmeta.post_id IN (". implode(",",$result_variation_ids) .")
                                        GROUP BY postmeta.post_id";

                $results_variation_att  = $wpdb->get_results ( $query_variation_att , 'ARRAY_A');

                $variation_att_all = array(); 

                for ( $i=0;$i<sizeof($results_variation_att);$i++ ) {
                    $variation_att_all [$results_variation_att [$i]['post_id']] = $results_variation_att [$i]['meta_value'];
                }
        }

	function sr_woo_remove_order( $order_id ) {
		global $wpdb;

		$table_name = "{$wpdb->prefix}sr_woo_order_items";
		if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}sr_woo_order_items WHERE order_id = {$order_id}" );
		}
	}

	$support_func_flag = 0;

	function sr_console_common() {

		?>
		<div class="wrap">
		<!-- <div id="icon-smart-reporter" class="sr_icon32"><br /> -->
		</div>
		<style>
		    div#TB_window {
		        background: lightgrey;
		    }
		</style>    
		<?php 


		//set the number of days data to show in lite version.
		define ( 'SR_AVAIL_DAYS', 30);
		
		$latest_version = get_latest_version (SR_PLUGIN_FILE );
		$is_pro_updated = is_pro_updated ();
		
		if ( isset($_GET ['action']) && $_GET ['action'] == 'sr-settings') {
			sr_settings_page (SR_PLUGIN_FILE);
		} else {
			$base_path = WP_PLUGIN_DIR . '/' . str_replace ( basename ( __FILE__ ), "", plugin_basename ( __FILE__ ) ) . 'sr/';

			if ( SRPRO === false && get_option('sr_dismiss_admin_notice') == '1' ) { ?>
					<div id="message" class="updated fade" style="display:block !important;">
						<p><?php
							printf( ('<b>' . __( 'Important:', SR_TEXT_DOMAIN ) . '</b> ' . __( 'To get the sales and sales KPI\'s for more than 30 days upgrade to Pro', SR_TEXT_DOMAIN ) . " " . '<br /><a href="%1s" target=_storeapps>' . " " .__( 'Learn more about Pro version', SR_TEXT_DOMAIN ) . '</a> ' . __( 'or take a', SR_TEXT_DOMAIN ) . " " . '<a href="%2s" target=_livedemo>' . " " . __( 'Live Demo', SR_TEXT_DOMAIN ) . '</a>'), 'https://www.storeapps.org/product/smart-reporter', 'http://demo.storeapps.org/?demo=sr-woo' );
							?>
						</p>
					</div>
				<?php
			}
			?>

			<div id="sr_header" class="wrap" style="height:1em;">

			<?php

			if ( !empty($_GET['page']) && $_GET['page'] != 'wc-reports') {
			?>
				<div id="icon-smart-reporter" class="sr_icon32"><img alt="Smart Reporter"
					src="<?php echo SR_IMG_URL.'/logo.png'; ?>"></div>
				<h2><?php
				echo _e ( 'Smart Reporter' );
				echo ' ';
					if (SRPRO === true) {
						echo _e ( 'Pro', SR_TEXT_DOMAIN );
					} else {
						echo _e ( 'Lite', SR_TEXT_DOMAIN );
					}
			}
			?>


   	<p class="wrap" style="font-size: 12px">
	   	<span id='sr_nav_links' style="float: right;margin-right: -1.2em;"> <?php
			if ( SRPRO === true && ! is_multisite() ) {
				
				if (SR_WPSC_RUNNING == true) {
					$plug_page = 'wpsc';
				} elseif (SR_WOO_RUNNING == true) {
					$plug_page = 'woo';
				}
			} else {
				$before_plug_page = '';
				$after_plug_page = '';
				$plug_page = '';
			}

			$switch_version = '';
/*
			if ( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ) {
				if (isset($_GET['view']) && $_GET['view'] == "smart_reporter_old") {
					$switch_version = '<a href="'. admin_url('admin.php?page=wc-reports&tab=smart_reporter') .'" title="'. __( 'Switch back to new view', SR_TEXT_DOMAIN ) .'"> ' . __( 'Switch back to new view', SR_TEXT_DOMAIN ) .'</a>';
				} else {
					$switch_version = '<a href="'. admin_url('admin.php?page=wc-reports&tab=smart_reporter&view=smart_reporter_old') .'" title="'. __( 'Switch to old view', SR_TEXT_DOMAIN ) .'"> ' . __( 'Switch to old view', SR_TEXT_DOMAIN ) .'</a>';
				}	
			}
*/			
			if ( SRPRO === true ) {

				if( defined('SR_WOO_RUNNING') && SR_WOO_RUNNING === true ) {
					$switch_version .= ' | ';
				}

	            if ( !wp_script_is( 'thickbox' ) ) {
	                if ( !function_exists( 'add_thickbox' ) ) {
	                    require_once ABSPATH . 'wp-includes/general-template.php';
	                }
	                add_thickbox();
	            }


	            // <a href="edit.php#TB_inline?max-height=420px&inlineId=smart_manager_post_query_form" title="Send your query" class="thickbox" id="support_link">Need Help?</a>
	            // $before_plug_page = '<a href="admin.php#TB_inline?max-height=420px&inlineId=sr_post_query_form" title="Send your query" class="thickbox" id="support_link">Feedback / Help?</a>';
	            $query_char = ( strpos( $_SERVER['REQUEST_URI'], '?' ) !== false ) ? '&' : '?';
	            $prefix = 'sa_smart_reporter';
	            $before_plug_page = '<a href="#TB_inline'.$query_char.'inlineId='.$prefix.'_post_query_form" class="thickbox '.$prefix.'_support_link" title="' . __( 'Submit your query', SR_TEXT_DOMAIN ) . '">' . __( 'Feedback / Help?', SR_TEXT_DOMAIN ) . '</a>';
	            
	            // if ( !isset($_GET['tab']) && ( isset($_GET['page']) && $_GET['page'] == 'smart-reporter-woo') && SR_BETA == "true") {
	            // 	// $before_plug_page .= ' | <a href="#" class="show_hide" rel="#slidingDiv">Settings</a>';
	            // 	$after_plug_page = '';
	            // 	$plug_page = '';
	            // }
	            // else {

	            if ( defined('SR_WPSC_RUNNING') && SR_WPSC_RUNNING === true ) {
					$before_plug_page .= ' | <a href="admin.php?page=smart-reporter-wpsc';
				} else if( defined('SR_WOO_RUNNING') && SR_WOO_RUNNING === true ) {
					$before_plug_page .= ' | <a href="admin.php?page=wc-reports&tab=smart_reporter';
				}

	            	
	            	$after_plug_page = '&action=sr-settings">Settings</a>';
	            // }

	        }

			printf ( __ ( '%1s%2s%3s'), $switch_version, $before_plug_page, $after_plug_page);		
		?>
		</span>
		<?php
			if ( !empty($_GET['page']) && $_GET['page'] != 'wc-reports') {
				echo __ ( 'Store analysis like never before.' );
			}
		?>
	</p>
	<h6 align="right"><?php
			if (isset($is_pro_updated) && ! $is_pro_updated) {
				$admin_url = SR_ADMIN_URL . "plugins.php";
				$update_link = "An upgrade for Smart Reporter Pro  $latest_version is available. <a align='right' href=$admin_url> Click to upgrade. </a>";
				sr_display_notice ( $update_link );
			}
			?>
   </h6>
   <h6 align="right">
</h2>
</div>
<?php
			$error_message = '';

			$active_plugins = (array) get_option('active_plugins', array());

			if (is_multisite()) {
				$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
			}


			if ((file_exists( WP_PLUGIN_DIR . '/wp-e-commerce/wp-shopping-cart.php' )) && (file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ))) {

			if ( ( isset($_GET['post_type']) && $_GET['post_type'] == 'wpsc-product') || ( isset($_GET['page']) && $_GET['page'] == 'smart-reporter-wpsc')) {

				if ( in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins) ) {
	                require_once (WPSC_FILE_PATH . '/wp-shopping-cart.php');
	                	if ( ((defined('SR_IS_WPSC37')) && SR_IS_WPSC37) || (defined('SR_IS_WPSC38') && SR_IS_WPSC38) ) {

	                        if (file_exists( $base_path . 'reporter-console.php' )) {
	                                include_once ($base_path . 'reporter-console.php');
	                                return;
	                        } else {
	                                $error_message = __( "A required Smart Reporter file is missing. Can't continue.", SR_TEXT_DOMAIN );
	                        }
	                    } else {
	                        $error_message = __( 'Smart Reporter currently works only with WP e-Commerce 3.7 or above.', SR_TEXT_DOMAIN );
	                    }
                }

			} else if ( in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins) ) {
                if ((defined('SR_IS_WOO13')) && SR_IS_WOO13 == "true") {
                        $error_message = __( 'Smart Reporter currently works only with WooCommerce 1.4 or above.', SR_TEXT_DOMAIN );
                } else {
                    if (file_exists( $base_path . 'reporter-console.php' )) {
                            include_once ($base_path . 'reporter-console.php');
                            return;
                    } else {
                            $error_message = __( "A required Smart Reporter file is missing. Can't continue.", SR_TEXT_DOMAIN );
                    }
                }
			}
                        else {
                            $error_message = "<b>" . __( 'Smart Reporter', SR_TEXT_DOMAIN ) . "</b> " . __( 'add-on requires', SR_TEXT_DOMAIN ) . " " .'<a href="https://www.storeapps.org/wpec/">' . __( 'WP e-Commerce', SR_TEXT_DOMAIN ) . "</a>" . " " . __( 'plugin or', SR_TEXT_DOMAIN ) . " " . '<a href="https://www.storeapps.org/woocommerce/">' . __( 'WooCommerce', SR_TEXT_DOMAIN ) . "</a>" . " " . __( 'plugin. Please install and activate it.', SR_TEXT_DOMAIN );
                        }
                    } else if (file_exists( WP_PLUGIN_DIR . '/wp-e-commerce/wp-shopping-cart.php' )) {
                        if ( in_array('wp-e-commerce/wp-shopping-cart.php', $active_plugins) || array_key_exists('wp-e-commerce/wp-shopping-cart.php', $active_plugins) ) {
                            require_once (WPSC_FILE_PATH . '/wp-shopping-cart.php');
                            if ((defined('SR_IS_WPSC37') && SR_IS_WPSC37) || (defined('SR_IS_WPSC38') && SR_IS_WPSC38)) {
                                if (file_exists( $base_path . 'reporter-console.php' )) {
                                        include_once ($base_path . 'reporter-console.php');
                                        return;
                                } else {
                                        $error_message = __( "A required Smart Reporter file is missing. Can't continue.", SR_TEXT_DOMAIN );
                                }
                            } else {
                                $error_message = __( 'Smart Reporter currently works only with WP e-Commerce 3.7 or above.', SR_TEXT_DOMAIN );
                            }
                        } else {
                                $error_message = __( 'WP e-Commerce plugin is not activated.', SR_TEXT_DOMAIN ) . "<br/><b>" . _e( 'Smart Reporter', SR_TEXT_DOMAIN ) . "</b> " . _e( 'add-on requires WP e-Commerce plugin, please activate it.', SR_TEXT_DOMAIN );
                        }
                    } else if (file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' )) {



                        if ( in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins) ) {
                            if ((defined('SR_IS_WOO13')) && SR_IS_WOO13 == "true") {
                                    $error_message = __( 'Smart Reporter currently works only with WooCommerce 1.4 or above.', SR_TEXT_DOMAIN );
                            } else {
                                if (file_exists( $base_path . 'reporter-console.php' )) {
                                    include_once ($base_path . 'reporter-console.php');
                                    return;
                                } else {
                                    $error_message = __( "A required Smart Reporter file is missing. Can't continue.", SR_TEXT_DOMAIN );
                                }
                            }
                        } else {
                            $error_message = __( 'WooCommerce plugin is not activated.', SR_TEXT_DOMAIN ) . "<br/><b>" . __( 'Smart Reporter', SR_TEXT_DOMAIN ) . "</b> " . __( 'add-on requires WooCommerce plugin, please activate it.', SR_TEXT_DOMAIN );
                        }
                    }
                    else {
                        $error_message = "<b>" . __( 'Smart Reporter', SR_TEXT_DOMAIN ) . "</b> " . __( 'add-on requires', SR_TEXT_DOMAIN ) . " " .'<a href="https://www.storeapps.org/wpec/">' . __( 'WP e-Commerce', SR_TEXT_DOMAIN ) . "</a>" . " " . __( 'plugin or', SR_TEXT_DOMAIN ) . " " . '<a href="https://www.storeapps.org/woocommerce/">' . __( 'WooCommerce', SR_TEXT_DOMAIN ) . "</a>" . " " . __( 'plugin. Please install and activate it.', SR_TEXT_DOMAIN );
                    }

			if ($error_message != '') {
				sr_display_err ( $error_message );
				?>
<?php
			}
		}
	};


	// if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ) )) {
 //    	add_action( 'wp_dashboard_setup', 'sr_wp_dashboard_widget' );
 //    }
	
	function sr_wp_dashboard_widget() {
		$base_path = WP_PLUGIN_DIR . '/' . str_replace ( basename ( __FILE__ ), "", plugin_basename ( __FILE__ ) ) . 'sr/';
		if (file_exists( $base_path . 'reporter-console.php' )) {
            include_once ($base_path . 'reporter-console.php');
		
            $ver = (!empty($sr_plugin_info ['Version'])) ? $sr_plugin_info ['Version'] : '';

            wp_register_style ( 'font_awesome', plugins_url ( "resources/font-awesome/css/font-awesome.min.css", __FILE__ ), array ());
            wp_register_style ( 'sr_main', plugins_url ( '/sr/smart-reporter.css', __FILE__ ), array('font_awesome'), $ver );
			wp_enqueue_style ( 'sr_main' );	

			//Constants for the arrow indicators
		    define ('SR_IMG_UP_GREEN', 'fa fa-angle-double-up icon_cumm_indicator_green');
		    define ('SR_IMG_UP_RED', 'fa fa-angle-double-up icon_cumm_indicator_red');
		    define ('SR_IMG_DOWN_RED', 'fa fa-angle-double-down icon_cumm_indicator_red');

		    if (file_exists( $base_path . 'json-woo.php' )) {
	            include_once ($base_path . 'json-woo.php');
				$sr_daily_widget_data = sr_get_daily_kpi_data(SR_NONCE);
				
				wp_add_dashboard_widget( 'sr_dashboard_kpi', __( 'Resumo de Vendas', SR_TEXT_DOMAIN ), 'sr_dashboard_widget_kpi','',array('security' => SR_NONCE, 'data' => $sr_daily_widget_data) );
	        }

		}
	}

	function sr_beta_show_console() {
		

		//Constants for the arrow indicators
	    define ('SR_IMG_UP_GREEN', 'fa fa-angle-double-up icon_cumm_indicator_green');
	    define ('SR_IMG_UP_RED', 'fa fa-angle-double-up icon_cumm_indicator_red');
	    define ('SR_IMG_DOWN_RED', 'fa fa-angle-double-down icon_cumm_indicator_red');
	    
	    //Constant for DatePicker Icon    
	    define ('SR_IMG_DATE_PICKER', SR_IMG_URL . 'calendar-blue.gif');

	    define("SR_BETA","true");

	    $base_path = WP_PLUGIN_DIR . '/' . str_replace ( basename ( __FILE__ ), "", plugin_basename ( __FILE__ ) ) . 'sr/';
		if (file_exists( $base_path . 'json-woo.php' )) {
            include_once ($base_path . 'json-woo.php');
			$sr_daily_widget_data = sr_get_daily_kpi_data(SR_NONCE);
			define("sr_daily_widget_data",$sr_daily_widget_data);

        }
		sr_console_common();
	};

	function sr_get_stats(){

		$params = (!empty($_REQUEST['params'])) ? $_REQUEST['params'] : $_REQUEST;

		if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' );
     	}

		$json_filename = ($params['file_nm'] == 'json-woo-beta') ? 'json-woo' : $params['file_nm'];
		$base_path = WP_PLUGIN_DIR . '/' . str_replace ( basename ( __FILE__ ), "", plugin_basename ( __FILE__ ) ) . 'sr/';
		if (file_exists( $base_path . $json_filename . '.php' )) {
            include_once ($base_path . $json_filename . '.php');
            if ( $json_filename == 'json-woo' ) {

            	if ( $_POST ['cmd'] == 'sr_data_sync' ) {
        			sr_data_sync();
        		} else if ( $params['file_nm'] == "json-woo-beta" && ( !empty( $_POST ['cmd'] ) && ( $_POST ['cmd'] != 'daily') ) ) {
            		sr_get_cumm_stats();
				} 
            }
        }
	}
	
	function sr_update_notice() {
		if ( !function_exists( 'sr_get_download_url_from_db' ) ) return;
                $download_details = sr_get_download_url_from_db();
//                $plugins = get_site_transient ( 'update_plugins' );
		$link = $download_details['results'][0]->option_value;                                //$plugins->response [SR_PLUGIN_FILE]->package;
		
                if ( !empty( $link ) ) {
                    $current  = get_site_transient ( 'update_plugins' );
                    $r1       = sr_plugin_reset_upgrade_link ( $current, $link );
                    set_site_transient ( 'update_plugins', $r1 );
                    echo $man_download_link = " Or <a href='$link'>click here to download the latest version.</a>";
                }
	}
		
	if (! function_exists ( 'sr_display_err' )) {
		function sr_display_err($error_message) {
			echo "<div id='notice' class='error'>";
			echo _e ( '<b>Error: </b>' . $error_message );
			echo "</div>";
		}
	}
	
	if (! function_exists ('sr_display_notice')) {
		function sr_display_notice($notice) {
			echo "<div id='message' class='updated fade'>
             <p>";
			echo _e ( $notice );
			echo "</p></div>";
		}
	}
// EOF auto upgrade code
}
?>
