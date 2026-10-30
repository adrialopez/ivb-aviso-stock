<?php
/**
 * Plugin Name: IVB Aviso de Stock
 * Description: Botón «Avísame cuando haya stock» en productos agotados de WooCommerce y envío de un email automático cuando vuelven a tener stock.
 * Version: 1.3.0
 * Author: Adrià López
 * Author URI: https://adria-lopez.com
 * Requires Plugins: woocommerce
 * Text Domain: ivb-aviso-stock
 */

defined( 'ABSPATH' ) || exit;

define( 'IVB_AVISO_STOCK_VERSION', '1.3.0' );
define( 'IVB_AVISO_STOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'IVB_AVISO_STOCK_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

register_activation_hook( __FILE__, function () {
	require_once IVB_AVISO_STOCK_DIR . 'includes/class-ivb-aviso-stock-core.php';
	IVB_Aviso_Stock_Core::install();
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once IVB_AVISO_STOCK_DIR . 'includes/class-ivb-aviso-stock-core.php';
	require_once IVB_AVISO_STOCK_DIR . 'includes/class-ivb-aviso-stock-admin.php';
	IVB_Aviso_Stock_Core::init();
	IVB_Aviso_Stock_Admin::init();
} );
