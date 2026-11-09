<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Sales
 * Description:       Sales for the Taxonomy Modeller: every sale with its items' material, and its goods issue through Taxmod Stock.
 * Version: 0.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmod-sales
 * GitHub Plugin URI: sfambach/wp_tax_log_sales
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TAXMOD_SALES_VERSION', '0.0.2');

$taxmodSalesAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodSalesAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Sales: vendor/autoload.php fehlt — bitte das Release-Paket installieren.', 'taxmod-sales') . '</p></div>';
    });

    return;
}

require $taxmodSalesAutoloader;

(new TaxmodSales\WordPress\Plugin())->boot();
