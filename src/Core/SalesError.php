<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * Why the sales module refused, with a reason key the boundary turns into a sentence.
 */
final class SalesError extends \RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $detail, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $key): self
    {
        return new self('not_configured', $key, 'The sales structure lacks ' . $key . '.');
    }

    public static function fromModel(string $message): self
    {
        return new self('model', $message, $message);
    }

    public static function fromStock(string $message): self
    {
        return new self('stock', $message, $message);
    }

    public static function noSuchSale(int $sale): self
    {
        return new self('no_such_sale', (string) $sale, 'There is no sale ' . $sale . '.');
    }

    public static function noMaterial(string $item): self
    {
        return new self('no_material', $item, 'The sold item «' . $item . '» has no material yet.');
    }

    public static function noStockModule(): self
    {
        return new self('no_stock', '', 'Taxmod Stock does not offer the bookings a sale needs.');
    }
}
