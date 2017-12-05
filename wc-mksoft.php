<?php
/**
 * WooCommerce MKSoft.
 *
 * @package   WooCommerce MKSoft
 * @author    Ing. Filip Vozár <filip.vozar@gmail.com>
 * @license   GPL-2.0+
 * @link      https://filipvozar.eu
 * @copyright 2017 Ing. Filip Vozár
 *
 * @wordpress-plugin
 * Plugin Name: WooCommerce MKSoft
 * Plugin URI:  https://filipvozar.eu/
 * Description: WooCommerce integrácia <a href="http://www.mksoft.sk/">MKSoft</a> na evidenciu objednávok
 * Version:     1.0.0
 * Author:      Ing. Filip Vozár
 * Author URI:  https://filipvozar.eu
 * Text Domain: wc-mksoft
 * License:     GPL-2.0+ License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 2.6.0 WC tested up to: 3.2.0
 */

// If this file is called directly, abort.
if ( ! defined('WPINC')) {
	die;
}

require_once __DIR__ . '/MKSoftApi/MKSoftApiClient.php';
require_once __DIR__ . '/class-wc-mksoft.php';

WC_MKSoft::get_instance();


function mks_action_links($links)
{
	return array_merge([
		'settings' => '<a href="' . get_admin_url(null,
				'admin.php?page=wc-settings&tab=wc_mksoft') . '">'. __('Nastavenia', 'wc-mksoft') . '</a>',
	],
		$links
	);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mks_action_links');
