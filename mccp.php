<?php
/**
 * Plugin Name: Multi Crypto Currency Payment
 * Plugin URI: https://github.com/zaytseff/mccp-woo
 * Description: Multi currency crypto payments for WooCommerce. Uses Apirone Processing Provider
 * Version: 2.0.7
 * Author: Alex Zaytseff
 * Author URI: https://github.com/zaytseff
 * Tested up to: 6.8.2
 */

defined('ABSPATH') || exit;

define('MCCP_ROOT', __DIR__);
define('MCCP_MAIN', __FILE__);
define('MCCP_URL', plugin_dir_url(__FILE__));

use Apirone\API\Log\LoggerWrapper;
use Apirone\SDK\Service\InvoiceDb;
use Apirone\SDK\Service\InvoiceQuery;

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

require_once(__DIR__ . '/vendor/autoload.php');

add_filter('woocommerce_payment_gateways', function ($plugins) {
	return array_merge($plugins, [WC_MCCP::class]);
});

// Show settings link on plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	$url = admin_url('admin.php?page=wc-settings&tab=checkout&section=mccp');
	return array_merge(['<a href="' . $url . '">' . __('Settings') . '</a>'], $links);
});


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'plugins_loaded', function() {
        require_once __DIR__ . '/inc/class-mccp-gateway.php';
    });
}
else {
    add_action( 'admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_attr_e( 'Please activate', 'woocommerce' );?> <a href="https://wordpress.org/plugins/woocommerce/"><?php esc_attr_e( 'Woocommerce', 'woocommerce' ); ?></a> <?php esc_attr_e( 'to use MCCP gateway.', 'woocommerce' ); ?></p>
        </div>
        <?php    
    } );
}

add_action('admin_enqueue_scripts', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'checkout' && isset($_GET['section']) && $_GET['section'] === 'mccp') {
        wp_enqueue_style( 'mccp_style', MCCP_URL . 'assets/mccp-admin.css' );
    }
});

add_action('wp_enqueue_scripts', function() {
        wp_enqueue_style ( 'mccp_style_invoice', MCCP_URL . 'vendor/apirone/apirone-sdk-php/src/assets/css/styles.min.css' );
        wp_enqueue_script('mccp_script_invoice', MCCP_URL . '/vendor/apirone/apirone-sdk-php/src/assets/js/script.min.js', array( 'jquery'));
        wp_enqueue_style( 'mccp_style', MCCP_URL . 'assets/mccp.css' );
    }
);

if (!function_exists('mccp_create_table')) {
    function mccp_create_table() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = InvoiceQuery::createInvoicesTable($wpdb->prefix, $wpdb->charset, $wpdb->collate);

        dbDelta($sql);

        if ($wpdb->last_error && class_exists('WC_Logger')) {
            $log = new WC_Logger();
            $log->error($wpdb->last_error, ['source' => 'mccp_install_error']);    
        }

        // Remove unused options from v1.0.0
        if ( get_option('mccp_db_version' ) ){
            delete_option('mccp_db_version');
        }
    }
    register_activation_hook( MCCP_MAIN, 'mccp_create_table' );
}


/**
 * Debug output
 *
 * @param mixed $mixed 
 * @param string $title 
 * @return void 
 */
function pa($mixed, $title = '') {
	echo '<pre>' . ($title ? $title . ': ' : '') . "\n";
	print_r(gettype($mixed) !== 'boolean' ? $mixed : ($mixed ? 'true' : 'false'));
	echo '</pre>';
}
