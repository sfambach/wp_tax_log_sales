<?php declare(strict_types=1);

namespace TaxmodSales\WordPress;

use TaxmodSales\Core\BookingPlan;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Sales;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\Structure;

/**
 * What the sales page asks and does: the sales as the model holds them, a material for an item, and a sale's booking — always
 * through the modeller's door (R2) and the stock module's booking filters (D-1260), never past them.
 */
final class SalesService
{
    public const STRUCTURE = 'taxmod_sales_structure';

    /** The stock module's bookings (wp_tax_stock): the lots of materials, a recorded lot, a goods issue for a sale. */
    public const LOTS_OF = 'taxmod_stock_lots_of';
    public const BOOK_BACKFILL = 'taxmod_stock_book_backfill';
    public const BOOK_SALE = 'taxmod_stock_book_sale';

    private ?Structure $structure = null;

    /** @var list<Sale>|null */
    private ?array $sales = null;

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    public function structure(): Structure
    {
        if ($this->structure !== null) {
            return $this->structure;
        }

        $stored  = get_option(self::STRUCTURE, []);
        $file    = dirname(__DIR__, 2) . '/structure-live.json';
        $bundled = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

        return $this->structure = Structure::fromArray(Structure::completed(is_array($stored) ? $stored : [], is_array($bundled) ? $bundled : []));
    }

    /** @return list<Sale> newest first; one read of each node per request */
    public function sales(): array
    {
        $s = $this->structure();

        return $this->sales ??= Sales::of($s, $this->model->records($s->node('sale')), $this->model->records($s->node('sold_item')));
    }

    public function sale(int $id): Sale
    {
        foreach ($this->sales() as $sale) {
            if ($sale->id === $id) {
                return $sale;
            }
        }

        throw SalesError::noSuchSale($id);
    }

    /** The modeller's control for an item's material (D-1250), posted as `material[<relation>]`. */
    public function materialControl(int $item, string $formId): string
    {
        $s = $this->structure();

        return $this->model->controls($s->node('sold_item'), [$s->field('item_material')], 'material', $formId, $item)[$s->field('item_material')] ?? '';
    }

    /**
     * Sets the material of an item and, if asked, of every item of the same article still without one — one write, one step
     * to take back (V1).
     *
     * @param array<int|string, mixed> $submitted `$_POST['material']`
     * @return int how many items got the material
     */
    public function assign(int $item, array $submitted, bool $sameArticle): int
    {
        $s        = $this->structure();
        $relation = $s->field('item_material');
        $material = $this->model->typed($s->node('sold_item'), $submitted)[$relation] ?? '';
        $items    = [$item];

        if ($sameArticle) {
            foreach ($this->sales() as $sale) {
                foreach ($sale->items as $sold) {
                    if ($sold->id === $item) {
                        $items = [$item, ...Sales::sameArticle($this->sales(), $sold)];
                    }
                }
            }
        }

        $this->model->write(array_map(static fn (int $id): array => ['op' => 'set_values', 'record' => (string) $id, 'values' => [(string) $relation => $material]], $items));
        $this->sales = null;

        return count($items);
    }

    /** Whether the stock module offers the bookings a sale needs. */
    public function canBook(): bool
    {
        return has_filter(self::LOTS_OF) && has_filter(self::BOOK_BACKFILL) && has_filter(self::BOOK_SALE);
    }

    /**
     * Books one sale (D-1260): each item leaves the oldest lot of its material that holds it, or a lot recorded for it on the
     * day of the sale; the lot is written to the item. Answers the goods issue's id.
     */
    public function book(int $saleId): int
    {
        if (! $this->canBook()) {
            throw SalesError::noStockModule();
        }

        $sale      = $this->sale($saleId);
        $materials = array_values(array_unique(array_filter(array_map(static fn ($item): ?int => $item->material, $sale->items))));
        $lots      = $this->stock(self::LOTS_OF, ['materials' => $materials]);
        $plan      = BookingPlan::of($sale, is_array($lots) ? $lots : []);

        foreach ($plan as $i => $line) {
            if ($line['lot'] === null) {
                $plan[$i]['lot'] = (int) $this->stock(self::BOOK_BACKFILL, [
                    'material' => $line['material'], 'quantity' => $line['count'], 'date' => $sale->day(), 'reference' => $sale->id,
                    'title'    => sprintf(__('Nacherfassung für Verkauf %s', 'taxmod-sales'), $sale->number),
                ]);
            }
        }

        $issue = (int) $this->stock(self::BOOK_SALE, [
            'sale'  => $sale->id,
            'lines' => array_map(static fn (array $line): array => ['lot' => $line['lot'], 'quantity' => $line['count']], $plan),
            'date'  => $sale->day(),
            'title' => sprintf(__('Verkauf %s', 'taxmod-sales'), $sale->number),
        ]);
        $relation = $this->structure()->field('item_lot');
        $this->model->write(array_map(static fn (array $line): array => ['op' => 'set_values', 'record' => (string) $line['item'], 'values' => [(string) $relation => (string) $line['lot']]], $plan));
        $this->sales = null;

        return $issue;
    }

    /**
     * @param array<string, mixed> $args
     * @return mixed the stock module's answer; `mixed`: a filter's
     */
    private function stock(string $filter, array $args): mixed
    {
        $answer = apply_filters($filter, null, $args);

        if ($answer instanceof \WP_Error) {
            throw SalesError::fromStock($answer->get_error_message());
        }

        if ($answer === null) {
            throw SalesError::noStockModule();
        }

        return $answer;
    }
}
