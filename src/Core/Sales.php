<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * The sales read from the model's raw records — the one place that turns field ids into {@see Sale} and {@see SoldItem}.
 */
final class Sales
{
    /**
     * @param array<int, array<int, list<string>>> $sales record id → relation id → values, the node «Verkäufe»
     * @param array<int, array<int, list<string>>> $items record id → relation id → values, their positions
     * @return list<Sale> newest first
     */
    public static function of(Structure $structure, array $sales, array $items): array
    {
        $first = static fn (array $values, string $field): string => (string) ($values[$structure->field($field)][0] ?? '');
        $id    = static function (array $values, string $field) use ($structure): ?int {
            $value = (int) ($values[$structure->field($field)][0] ?? 0);

            return $value > 0 ? $value : null;
        };
        $out = [];

        foreach ($sales as $saleId => $values) {
            $positions = [];

            foreach ($values[$structure->field('sale_items')] ?? [] as $itemId) {
                $item = $items[(int) $itemId] ?? null;

                if ($item === null) {
                    continue;
                }

                $count       = $first($item, 'item_count');
                $positions[] = new SoldItem((int) $itemId, $first($item, 'label'), is_numeric($count) ? (float) $count : 1.0,
                    $id($item, 'item_material'), $id($item, 'item_lot'));
            }

            $out[] = new Sale((int) $saleId, $first($values, 'sale_number'), $first($values, 'sale_platform'), $first($values, 'sale_buyer'),
                $first($values, 'sale_date'), $positions);
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
