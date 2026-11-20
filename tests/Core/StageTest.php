<?php declare(strict_types=1);

namespace TaxmodSales\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Sales;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\Stage;

final class StageTest extends TestCase
{
    private const F = ['label' => 10, 'sale_number' => 11, 'sale_platform' => 12, 'sale_buyer' => 13, 'sale_date' => 14, 'sale_items' => 15,
        'item_count' => 16, 'item_material' => 17, 'item_lot' => 18];

    private const STAGE_FIELD = 19;

    /** Constant id → stage, as the role table answers it. */
    private const VALUES = [70 => Stage::SOLD, 71 => Stage::PROVIDE, 72 => Stage::PACK, 73 => Stage::LABEL, 74 => Stage::HANDOVER, 75 => Stage::TRANSIT, 76 => Stage::DELIVERED];

    /**
     * @param array<string, string> $ebay
     * @return list<Sale>
     */
    private static function sales(?int $stageField, array $ebay = []): array
    {
        return Sales::of(static fn (string $role): int => self::F[$role] ?? throw SalesError::fromModel('no role ' . $role), [
            100 => [11 => ['02-1'], 12 => ['eBay'], 14 => ['2026-11-01 00:00:00'], 15 => [], self::STAGE_FIELD => ['72']],
            101 => [11 => ['02-2'], 12 => ['eBay'], 14 => ['2026-11-02 00:00:00'], 15 => []],
            102 => [11 => ['K-7'], 12 => ['Kleinanzeigen'], 14 => ['2026-11-03 00:00:00'], 15 => []],
        ], [], $stageField, self::VALUES, $ebay);
    }

    /** @param list<Sale> $sales */
    private static function stageOf(array $sales, int $id): string
    {
        foreach ($sales as $sale) {
            if ($sale->id === $id) {
                return $sale->stage;
            }
        }

        self::fail('no sale ' . $id);
    }

    public function testTheFieldWinsTheEbayPageIsReadOnlyWhereTheFieldIsEmpty(): void
    {
        $sales = self::sales(self::STAGE_FIELD, ['02-1' => 'shipped', '02-2' => 'packed', 'K-7' => 'shipped']);

        self::assertSame(Stage::PACK, self::stageOf($sales, 100), 'the stored stage is not overruled by the eBay page');
        self::assertSame(Stage::LABEL, self::stageOf($sales, 101), 'packed on the eBay page waits for its label');
        self::assertSame(Stage::SOLD, self::stageOf($sales, 102), 'another channel never reads the eBay page');
        self::assertSame([101 => Stage::LABEL], Sales::toCarryOver($sales));
    }

    public function testWithoutTheModelChangeEverySaleIsSoldOrWhereTheEbayPageHasIt(): void
    {
        $sales = self::sales(null, ['02-1' => 'received']);

        self::assertSame(Stage::DELIVERED, self::stageOf($sales, 100));
        self::assertSame(Stage::SOLD, self::stageOf($sales, 101));
        self::assertSame([Stage::SOLD => 2, Stage::PROVIDE => 0, Stage::PACK => 0, Stage::LABEL => 0, Stage::HANDOVER => 0, Stage::TRANSIT => 0, Stage::DELIVERED => 1],
            Stage::counts($sales));
    }

    public function testTheStagesFollowEachOtherInTheOrderOfD1528(): void
    {
        self::assertSame(Stage::PROVIDE, Stage::next(Stage::SOLD));
        self::assertNull(Stage::next(Stage::DELIVERED));
        self::assertNull(Stage::previous(Stage::SOLD));
        self::assertSame(Stage::LABEL, Stage::previous(Stage::HANDOVER));
        self::assertNull(Stage::fromEbay('unknown'));
    }
}
