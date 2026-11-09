<?php declare(strict_types=1);

namespace TaxmodSales\WordPress;

use TaxmodSales\Core\Structure;
use TaxmodSales\WordPress\Admin\SalesPage;

/**
 * Wires the module into WordPress: its page inside the logistics navigation (area «Warenausgang», D-1260), the form
 * handlers and the structure option.
 */
final class Plugin
{
    public const VERSION = '0.0.2';

    /** The page's address, owned here so the boot names it without compiling the page (D-1187). */
    public const PAGE = 'taxmod-verkauf';

    /** D-1254: the acts of the shared list page came with the modeller 0.2.39. */
    private const MODELLER = '0.2.39';

    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins», like the other modules: the modeller lives in a versioned folder.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    public function bootWhenModellerIsThere(): void
    {
        if (! class_exists(\Taxmod\WordPress\Plugin::class, false) || version_compare(\Taxmod\WordPress\Plugin::VERSION, self::MODELLER, '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Taxmod Sales braucht den aktiven Taxonomy Modeller ab %s.', 'taxmod-sales'), self::MODELLER)) . '</p></div>';
            });

            return;
        }

        // On admin_init and rest_api_init, where settings are saved, not on every page view (D-1187).
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('rest_api_init', [$this, 'registerSettings']);
        // D-1085: a module's page opens inside the logistics frame; the stock module draws the navigation.
        add_filter('taxmod_logistics_pages', static function (mixed $pages): array {
            $pages   = is_array($pages) ? $pages : [];
            $pages[] = [
                'slug'   => self::PAGE,
                'label'  => __('Verkäufe', 'taxmod-sales'),
                'area'   => 'outbound',
                'render' => static fn (): string => (new SalesPage())->render(),
            ];

            return $pages;
        });

        // ⚠️ *Only where admin-post.php answers (D-1187, D-1244); the page itself is named only inside the handler (D-1254).*
        if (self::handlesAdminActions()) {
            foreach (SalesPage::ACTIONS as $action) {
                // A booking goes through the stock, so it waits for the stock's other bookings like they wait for each other (D-1147).
                add_action('admin_post_' . $action, static fn () => class_exists(\TaxmodStock\WordPress\Plugin::class) && method_exists(\TaxmodStock\WordPress\Plugin::class, 'serially')
                    ? \TaxmodStock\WordPress\Plugin::serially(static fn () => (new SalesPage())->handle($action))
                    : (new SalesPage())->handle($action));
            }
        }
    }

    private static function handlesAdminActions(): bool
    {
        return ! method_exists(\Taxmod\WordPress\Plugin::class, 'handlesAdminActions') || \Taxmod\WordPress\Plugin::handlesAdminActions();
    }

    /** The structure ids, settable over REST (/wp/v2/settings) right after the model change is applied. */
    public function registerSettings(): void
    {
        $group = static fn (): array => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];

        register_setting('taxmod_sales', SalesService::STRUCTURE, [
            'type'              => 'object',
            'default'           => [],
            'sanitize_callback' => static fn ($value): array => Structure::fromArray(is_array($value) ? $value : [])->toArray(),
            'show_in_rest'      => ['schema' => ['type' => 'object', 'properties' => ['nodes' => $group(), 'fields' => $group()]]],
        ]);
    }
}
