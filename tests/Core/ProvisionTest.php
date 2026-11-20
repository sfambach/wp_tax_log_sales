<?php declare(strict_types=1);

namespace TaxmodSales\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodSales\Core\Provision;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\SoldItem;

final class ProvisionTest extends TestCase
{
    public function testTakesTheFullestPlaceFirstAndSplitsWhenNeeded(): void
    {
        $sale = new Sale(1, 'A1', 'eBay', 'x', '2026-11-20', [new SoldItem(10, 'Karte', 3.0, 7, null)]);

        self::assertSame([['thing' => 7, 'from' => 21, 'qty' => 2.0], ['thing' => 7, 'from' => 20, 'qty' => 1.0]], Provision::lines($sale, [7 => [20 => 1.0, 21 => 2.0]]));
    }

    public function testSkipsBookedItems(): void
    {
        $sale = new Sale(1, 'A1', 'eBay', 'x', '2026-11-20', [new SoldItem(10, 'Karte', 1.0, 7, 99)]);

        self::assertSame([], Provision::lines($sale, []));
    }

    public function testRefusesWithoutMaterialOrStock(): void
    {
        $this->expectException(SalesError::class);
        Provision::lines(new Sale(1, 'A1', 'eBay', 'x', '2026-11-20', [new SoldItem(10, 'Karte', 2.0, 7, null)]), [7 => [20 => 1.0]]);
    }
}
