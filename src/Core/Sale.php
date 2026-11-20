<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * One sale with its items and where it stands in booking (D-934, D-1260).
 */
final class Sale
{
    public const TO_ASSIGN = 'assign';
    public const TO_BOOK = 'book';
    public const BOOKED = 'booked';

    /** In the order of the process. */
    public const STATES = [self::TO_ASSIGN, self::TO_BOOK, self::BOOKED];

    /**
     * @param list<SoldItem> $items
     * @param string $stage       one of {@see Stage::ALL} (D-1528)
     * @param bool   $stageStored false: the sale's field is empty and the stage was read from the eBay page or is «Verkauft»
     */
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly string $platform,
        public readonly string $buyer,
        public readonly string $soldOn,
        public readonly array $items,
        public readonly string $stage = Stage::SOLD,
        public readonly bool $stageStored = false,
    ) {
    }

    /** A sale waits for a material while one item has none; then for its booking; booked when every item left a lot. */
    public function state(): string
    {
        foreach ($this->items as $item) {
            if ($item->material === null) {
                return self::TO_ASSIGN;
            }
        }

        foreach ($this->items as $item) {
            if (! $item->isBooked()) {
                return self::TO_BOOK;
            }
        }

        return self::BOOKED;
    }

    /** The date of the sale as Y-m-d, the date its goods issue carries (D-1260: «zum Verkaufsdatum»). */
    public function day(): string
    {
        return substr($this->soldOn, 0, 10);
    }
}
