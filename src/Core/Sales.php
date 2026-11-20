<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * The sales read from the model's raw records — the one place that turns field ids into {@see Sale} and {@see SoldItem}.
 */
final class Sales
{
    /**
     * The roles the sales read (D-1394), all required: D-934 a sale and its sold items, D-1257 the material a sold item
     * points to, D-1260 what a sold item is and the lot it left.
     */
    public const ROLES = [
        'nodes'  => ['sale', 'sold_item', 'material'],
        'fields' => ['label', 'sale_number', 'sale_platform', 'sale_buyer', 'sale_date', 'sale_items', 'item_count', 'item_material', 'item_lot'],
    ];

    /** D-1528: the stage of a sale and its constants — optional until the model change is in; without them every sale is «Verkauft». */
    public const STAGE_ROLES = [
        'fields' => ['sale_stage'],
        'values' => Stage::ALL,
    ];

    /** The platform the eBay module writes on its sales; only those read its old stages. */
    public const EBAY = 'ebay';

    /**
     * @param \Closure(string): int               $field   role name → field id, from the modeller's role table (D-1394)
     * @param array<int, array<int, list<string>>> $sales record id → relation id → values, the node «Verkäufe»
     * @param array<int, array<int, list<string>>> $items record id → relation id → values, their positions
     * @param int|null                              $stageField the relation of «Stufe», null while the model lacks it
     * @param array<int, string>                    $stageValues constant id → stage key ({@see Stage::ALL})
     * @param array<string, string>                 $ebayStages eBay order id → the eBay page's stage, read until carried over
     * @return list<Sale> newest first
     */
    public static function of(\Closure $field, array $sales, array $items, ?int $stageField = null, array $stageValues = [], array $ebayStages = []): array
    {
        $first = static fn (array $values, string $role): string => (string) ($values[$field($role)][0] ?? '');
        $id    = static function (array $values, string $role) use ($field): ?int {
            $value = (int) ($values[$field($role)][0] ?? 0);

            return $value > 0 ? $value : null;
        };
        $out = [];

        foreach ($sales as $saleId => $values) {
            $positions = [];

            foreach ($values[$field('sale_items')] ?? [] as $itemId) {
                $item = $items[(int) $itemId] ?? null;

                if ($item === null) {
                    continue;
                }

                $count       = $first($item, 'item_count');
                $positions[] = new SoldItem((int) $itemId, $first($item, 'label'), is_numeric($count) ? (float) $count : 1.0,
                    $id($item, 'item_material'), $id($item, 'item_lot'));
            }

            $number   = $first($values, 'sale_number');
            $platform = $first($values, 'sale_platform');
            $stored   = $stageField === null ? null : ($stageValues[(int) ($values[$stageField][0] ?? 0)] ?? null);
            $legacy   = strtolower($platform) === self::EBAY && isset($ebayStages[$number]) ? Stage::fromEbay($ebayStages[$number]) : null;

            $out[] = new Sale((int) $saleId, $number, $platform, $first($values, 'sale_buyer'),
                $first($values, 'sale_date'), $positions, $stored ?? $legacy ?? Stage::SOLD, $stored !== null);
        }

        usort($out, static fn (Sale $a, Sale $b): int => [$b->soldOn, $b->id] <=> [$a->soldOn, $a->id]);

        return $out;
    }

    /**
     * The items without a material that are the same article as `$item` — they take its material along (konzept §2, step 3).
     *
     * @param list<Sale> $sales
     * @return list<int> item ids, `$item` itself not among them
     */
    public static function sameArticle(array $sales, SoldItem $item): array
    {
        $out = [];

        foreach ($sales as $sale) {
            foreach ($sale->items as $other) {
                if ($other->id !== $item->id && $other->material === null && $other->article() === $item->article()) {
                    $out[] = $other->id;
                }
            }
        }

        return $out;
    }

    /**
     * The sales whose stage stands only on the eBay page — what the one-time carry-over writes into their field (D-1528, Prüftor
     * condition 1: `taxmod_ebay_stages` is read only until then).
     *
     * @param list<Sale> $sales
     * @return array<int, string> sale id → stage
     */
    public static function toCarryOver(array $sales): array
    {
        $out = [];

        foreach ($sales as $sale) {
            if (! $sale->stageStored && $sale->stage !== Stage::SOLD) {
                $out[$sale->id] = $sale->stage;
            }
        }

        return $out;
    }

    /**
     * @param list<Sale> $sales
     * @return array<string, int> state → sales
     */
    public static function counts(array $sales): array
    {
        $counts = array_fill_keys(Sale::STATES, 0);

        foreach ($sales as $sale) {
            $counts[$sale->state()]++;
        }

        return $counts;
    }
}
