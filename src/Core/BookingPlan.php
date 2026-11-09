<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * Which lot each item of a sale leaves (D-1260): the oldest lot of its material that still holds the pieces; where none
 * does, a lot is recorded first («Nacherfassung», on the day of the sale) and the item leaves that one.
 *
 * ```mermaid
 * flowchart LR
 *   I["Item with material"] --> L{"Lot with rest ≥ count?"}
 *   L -->|yes, oldest first| A["Issue from that lot"]
 *   L -->|no| N["Record lot (Nacherfassung)"] --> A
 * ```
 */
final class BookingPlan
{
    /**
     * @param array<int, list<array{lot: int, rest: float}>> $lots material → its lots with what is left, oldest first
     * @return list<array{item: int, material: int, count: float, lot: int|null}> lot null: record one first
     */
    public static function of(Sale $sale, array $lots): array
    {
        $plan = [];

        foreach ($sale->items as $item) {
            if ($item->isBooked()) {
                continue;
            }

            if ($item->material === null) {
                throw SalesError::noMaterial($item->label);
            }

            $chosen = null;

            foreach ($lots[$item->material] ?? [] as $i => $lot) {
                if ($lot['rest'] + 1e-9 >= $item->count) {
                    $chosen                         = $lot['lot'];
                    $lots[$item->material][$i]['rest'] -= $item->count;
                    break;
                }
            }

            $plan[] = ['item' => $item->id, 'material' => $item->material, 'count' => $item->count, 'lot' => $chosen];
        }

        return $plan;
    }
}
