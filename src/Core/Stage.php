<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * Where a sale stands in the Warenausgang (D-1528): one process for every channel, the stage a field of the sale. The keys are
 * the role names of the stage constants (D-1394); their order is the process.
 *
 * ```mermaid
 * flowchart LR
 *   S[Verkauft] --> B[Bereitstellen] --> P[Packen] --> E[Etikett] --> U[Übergeben] --> W[Unterwegs] --> Z[Zugestellt]
 * ```
 *
 * @see /mnt/project-files/taxmod-warenausgang/konzept-vorschlag.md §2
 */
final class Stage
{
    public const SOLD = 'stage_sold';
    public const PROVIDE = 'stage_provide';
    public const PACK = 'stage_pack';
    public const LABEL = 'stage_label';
    public const HANDOVER = 'stage_handover';
    public const TRANSIT = 'stage_transit';
    public const DELIVERED = 'stage_delivered';

    /** In the order of the process. */
    public const ALL = [self::SOLD, self::PROVIDE, self::PACK, self::LABEL, self::HANDOVER, self::TRANSIT, self::DELIVERED];

    /**
     * The eBay shipping page's own stages (`taxmod_ebay_stages`, D-985), read only until they are carried over: «open» knew no
     * material or provision, «packed» waited for its label, «shipped» was on its way.
     */
    private const FROM_EBAY = ['open' => self::SOLD, 'packed' => self::LABEL, 'shipped' => self::TRANSIT, 'received' => self::DELIVERED];

    public static function fromEbay(string $ebayStage): ?string
    {
        return self::FROM_EBAY[$ebayStage] ?? null;
    }

    public static function next(string $stage): ?string
    {
        $at = array_search($stage, self::ALL, true);

        return $at === false ? null : (self::ALL[$at + 1] ?? null);
    }

    public static function previous(string $stage): ?string
    {
        $at = array_search($stage, self::ALL, true);

        return $at === false || $at === 0 ? null : self::ALL[$at - 1];
    }

    /**
     * How many sales stand in each stage — the one count the menu and the process bar read (D-1258, D-1528).
     *
     * @param list<Sale> $sales
     * @return array<string, int> stage → sales, in process order
     */
    public static function counts(array $sales): array
    {
        $counts = array_fill_keys(self::ALL, 0);

        foreach ($sales as $sale) {
            $counts[$sale->stage]++;
        }

        return $counts;
    }
}
