<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * One position of a sale (D-934): what went, how many, and — once known — its material and the lot it left (D-1260).
 */
final class SoldItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly float $count,
        public readonly ?int $material,
        public readonly ?int $lot,
    ) {
    }

    /** Booked = it left a lot (konzept §8, point 2). */
    public function isBooked(): bool
    {
        return $this->lot !== null;
    }

    /**
     * The label without eBay's cut-off «…» and spacing, so the same article sold twice is recognised as one (the listing
     * title was shortened in some mails).
     */
    public function article(): string
    {
        $label = preg_replace('/\s+/u', ' ', trim($this->label)) ?? $this->label;

        return mb_strtolower(rtrim($label, " .…\u{2026}"));
    }
}
