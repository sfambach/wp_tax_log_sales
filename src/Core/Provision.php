<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * What «Bereitstellen» takes where (D-1528, Etappe 2): each item not yet booked leaves the places where its material lies free,
 * the fullest first, into the outbound place. An item already booked (D-1260) has left the stock and is not picked again.
 *
 * ```mermaid
 * flowchart LR
 *   I[Artikel mit Material] --> F{frei je Platz}
 *   F -->|vollster zuerst| L[Zeilen: Ding · von · Menge]
 *   L --> WA[Warenausgangsplatz]
 * ```
 *
 * @see /mnt/project-files/taxmod-warenausgang/konzept-vorschlag.md §2, §5
 */
final class Provision
{
    /**
     * @param array<int, array<int, float>> $free thing → place → free pieces (the stock's `taxmod_stock_places_of`)
     * @return list<array{thing: int, from: int, qty: float}>
     */
    public static function lines(Sale $sale, array $free): array
    {
        $lines = [];

        foreach ($sale->items as $item) {
            if ($item->isBooked()) {
                continue;
            }

            if ($item->material === null) {
                throw SalesError::noMaterial($item->label);
            }

            $places = $free[$item->material] ?? [];
            arsort($places);
            $need = $item->count;

            foreach ($places as $place => $pieces) {
                $take = min($need, $pieces);

                if ($take > 0.0) {
                    $lines[] = ['thing' => $item->material, 'from' => (int) $place, 'qty' => $take];
                    $free[$item->material][$place] -= $take;
                    $need -= $take;
                }

                if ($need <= 0.000001) {
                    break;
                }
            }

            if ($need > 0.000001) {
                throw SalesError::notEnoughStock($item->label, $need);
            }
        }

        return $lines;
    }
}
