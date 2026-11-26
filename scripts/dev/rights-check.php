<?php declare(strict_types=1);
/**
 * Sales check their own right (D-1585 step 3, D-1586): the sales page, the stages of the Warenausgang and their acts need sell,
 * and no old right is left.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.237 active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

$old = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $old += preg_match_all('/\'manage_options\'|\bModeller::CAPABILITY\b|Plugin::CAPABILITY\b/', (string) file_get_contents($file->getPathname()));
    }
}
$pruefe($old === 0, "no old right left in src ($old)");

$outbound = array_values(array_filter((array) apply_filters('taxmod_logistics_pages', []), static fn (array $page): bool => ($page['slug'] ?? '') === \TaxmodSales\WordPress\Plugin::PAGE || str_starts_with((string) ($page['slug'] ?? ''), 'taxmod-wa-')));
$pruefe(count($outbound) > 1 && array_unique(array_column($outbound, 'capability')) === [Rights::SELL], 'the sales page and every stage ask sell');

Rights::ensureRoles();
$user   = static fn (string $login, string $role): int => (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role]);
$seller = $user('__sr2_sales', 'taxmod_sales');
$stock  = $user('__sr2_stock', 'taxmod_stock');
$pruefe(user_can($seller, Rights::SELL) && ! user_can($stock, Rights::SELL), 'the sales role sells, the stock role does not');

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
