<?php declare(strict_types=1);

namespace TaxmodSales\WordPress;

use TaxmodSales\Core\Sales;
use TaxmodSales\WordPress\Admin\SalesPage;

/**
 * Wires the module into WordPress: its page inside the logistics navigation (area «Warenausgang», D-1260), the form
 * handlers and the roles it reads (D-1394).
 */
final class Plugin
{
    public const VERSION = '0.0.3';

    /** The page's address, owned here so the boot names it without compiling the page (D-1187). */
    public const PAGE = 'taxmod-verkauf';

    /** D-1394: the one role table came with the modeller 0.2.118. */
    private const MODELLER = '0.2.130';

    /** The module's name in the role table's declarations. */
    public const ROLE_MODULE = 'sales';

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

        add_action('admin_init', [SalesService::class, 'handOverStructure']);
        // D-1394: the roles this module reads, so the modeller can say which are not set on this site.
        add_filter('taxmod_roles', static function (array $roles): array {
            foreach (Sales::ROLES as $group => $keys) {
                foreach ($keys as $key) {
                    $roles[] = ['module' => self::ROLE_MODULE, 'group' => $group, 'key' => $key, 'required' => true];
                }
            }

            return $roles;
        });
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

        // D-1147: a sale's items (with the material choice) are drawn when its row opens, not with the list.
        add_filter('taxmod_list_details', static fn (?string $html, string $source, array $args): ?string => $source === SalesPage::DETAILS
            ? (new SalesPage())->listDetails($html, $source, $args)
            : $html, 10, 3);

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
}
