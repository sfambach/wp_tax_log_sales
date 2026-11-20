<?php declare(strict_types=1);
/**
 * «Bereitstellen» (D-1528, Etappe 2): on from «Bereitstellen» picks the items into the outbound place and moves the sale to
 * «Packen» in one act; back from «Packen» takes the pick back. The stock's filters are answered here (priority 1) so the check
 * needs no stock structure; everything it writes is taken back by failing the surrounding act.
 *
 *     php scripts/dev/provide-check.php [path/to/wordpress]
 *
 * Needs the sales structure with the field «Stufe» and one sale in «Bereitstellen» whose items have a material and no lot.
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

use Taxmod\WordPress\ModelDoor;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Stage;
use TaxmodSales\WordPress\SalesService;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

wp_set_current_user(1);
$picks   = [];
$unpicks = [];
add_filter('taxmod_stock_places_with_function', static fn ($p, $a): array => ($a['function'] ?? '') === 'outbound' ? [999001] : [], 1, 2);
add_filter('taxmod_stock_places_of', static fn ($p, $a): array => array_fill_keys((array) ($a['things'] ?? []), [999002 => 1.0, 999003 => 50.0]), 1, 2);
add_filter('taxmod_stock_book_pick', static function ($p, $a) use (&$picks): int { $picks[] = $a; return 1; }, 1, 2);
add_filter('taxmod_stock_cancel_pick', static function ($p, $a) use (&$unpicks): int { $unpicks[] = $a; return 2; }, 1, 2);

$service = new SalesService();
check('the stock offers what «Bereitstellen» needs', $service->canProvide());
check('the outbound place comes from the place function', array_keys($service->outboundPlaces()) === [999001]);

$sale = array_values(array_filter($service->sales(), static fn (Sale $s): bool => $s->stage === Stage::PROVIDE && $s->items !== []
    && array_filter($s->items, static fn ($i): bool => $i->isBooked()) === []))[0] ?? null;

if ($sale === null) {
    echo "  --   no sale in «Bereitstellen» without a booked item: nothing checked\n";
    exit(3);
}

$door     = new ModelDoor();
$relation = $service->roles()->field('item_material');
$target   = $door->targetOf($relation);

try {
    ModelDoor::inAct(static function () use ($service, $sale, $door, $relation, $target, &$picks, &$unpicks): void {
        // An item without material gets one for the check; the act takes both back.
        $material = (int) ($door->write([['op' => 'create_record', 'node' => $target]])['applied'][0]['id'] ?? 0);
        $door->write(array_values(array_map(static fn ($i): array => ['op' => 'set_values', 'record' => (string) $i->id, 'values' => [(string) $relation => (string) $material]],
            array_filter($sale->items, static fn ($i): bool => $i->material === null))));
        $service = new SalesService();
        check('a place that is no outbound place is refused', (static function () use ($service, $sale): bool {
            try {
                $service->provide([$sale->id], 999002);
                return false;
            } catch (\TaxmodSales\Core\SalesError) {
                return true;
            }
        })());

        $service->provide([$sale->id], 999001);
        check('one pick per sale, into the outbound place, naming the sale', count($picks) === 1 && $picks[0]['place'] === 999001 && $picks[0]['reference'] === $sale->id);
        check('the fullest place gives first', ($picks[0]['lines'][0]['from'] ?? 0) === 999003);
        check('the sale stands in «Packen» afterwards', (new SalesService())->sale($sale->id)->stage === Stage::PACK);

        $service->unprovide([$sale->id]);
        check('back takes the pick out of the outbound place for that sale', count($unpicks) === 1 && $unpicks[0]['box'] === 999001 && $unpicks[0]['reference'] === $sale->id);
        check('the sale stands in «Bereitstellen» again', (new SalesService())->sale($sale->id)->stage === Stage::PROVIDE);

        throw new RuntimeException('take it all back');
    });
} catch (RuntimeException $e) {
    check('the check leaves nothing behind', $e->getMessage() === 'take it all back' && (new SalesService())->sale($sale->id)->stage === Stage::PROVIDE, $e->getMessage());
}

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
