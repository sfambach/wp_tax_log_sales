<?php declare(strict_types=1);

namespace TaxmodSales\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodSales\Core\BookingPlan;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Sales;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\Structure;

final class SalesTest extends TestCase
{
    private const S = ['nodes' => ['sale' => 1, 'sold_item' => 2, 'material' => 3],
        'fields' => ['label' => 10, 'sale_number' => 11, 'sale_platform' => 12, 'sale_buyer' => 13, 'sale_date' => 14, 'sale_items' => 15,
            'item_count' => 16, 'item_material' => 17, 'item_lot' => 18]];

    /** @return list<Sale> */
    private static function sales(): array
    {
        return Sales::of(Structure::fromArray(self::S), [
            100 => [11 => ['02-1'], 12 => ['Ebay'], 13 => ['ph'], 14 => ['2025-09-24 00:00:00'], 15 => ['200']],
            101 => [11 => ['02-2'], 12 => ['eBay'], 13 => ['gu'], 14 => ['2026-10-22 00:00:00'], 15 => ['201', '202']],
        ], [
            200 => [10 => ['greaseweazle 4.1, USB Floppy Disk Controller, Auf...'], 16 => ['1']],
            201 => [10 => ['greaseweazle 4.1, USB Floppy Disk Controller, Auf…'], 16 => ['2'], 17 => ['500']],
            202 => [10 => ['Joystick Tester'], 16 => ['1'], 17 => ['501'], 18 => ['900']],
        ]);
    }

    public function testASaleWaitsForMaterialThenForItsBooking(): void
    {
        [$newest, $oldest] = self::sales();

        self::assertSame(101, $newest->id);
        self::assertSame(Sale::TO_BOOK, $newest->state());
        self::assertSame(Sale::TO_ASSIGN, $oldest->state());
        self::assertSame('2025-09-24', $oldest->day());
        self::assertSame([Sale::TO_ASSIGN => 1, Sale::TO_BOOK => 1, Sale::BOOKED => 0], Sales::counts(self::sales()));
    }

    public function testTheSameArticleIsFoundDespiteACutTitle(): void
    {
        $sales = self::sales();

        self::assertSame([], Sales::sameArticle($sales, $sales[1]->items[0]), 'the other one has a material already');
        self::assertSame([200], Sales::sameArticle($sales, $sales[0]->items[0]));
    }

    public function testEachItemLeavesTheOldestLotThatHoldsItOrIsRecordedFirst(): void
    {
        $sale = self::sales()[0];

        self::assertSame([['item' => 201, 'material' => 500, 'count' => 2.0, 'lot' => 41]],
            BookingPlan::of($sale, [500 => [['lot' => 40, 'rest' => 1.0], ['lot' => 41, 'rest' => 5.0]]]), 'the booked item stays out');
        self::assertSame([['item' => 201, 'material' => 500, 'count' => 2.0, 'lot' => null]], BookingPlan::of($sale, []), 'no stock: record it first');
    }

    public function testASaleWithoutMaterialIsNotBooked(): void
    {
        $this->expectException(SalesError::class);
        BookingPlan::of(self::sales()[1], []);
    }

    public function testTheStructureNamesWhatIsMissing(): void
    {
        self::assertSame(['fields.item_material', 'fields.item_lot'], Structure::fromArray(['nodes' => self::S['nodes'],
            'fields' => array_diff_key(self::S['fields'], ['item_material' => 0, 'item_lot' => 0])])->missing());
    }
}
