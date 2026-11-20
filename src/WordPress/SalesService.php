<?php declare(strict_types=1);

namespace TaxmodSales\WordPress;

use Taxmod\Core\Role\RoleMap;
use Taxmod\WordPress\ModelDoor;

use TaxmodSales\Core\BookingPlan;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Sales;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\Stage;

/**
 * What the sales page asks and does: the sales as the model holds them, a material for an item, and a sale's booking — always
 * through the modeller's door (R2) and the stock module's booking filters (D-1260), never past them.
 */
final class SalesService
{
    /** The module's own id table before the role table (D-1394); handed to the modeller once, then gone. */
    public const STRUCTURE = 'taxmod_sales_structure';

    /** The stock module's bookings (wp_tax_stock): the lots of materials, a recorded lot, a goods issue for a sale. */
    public const LOTS_OF = 'taxmod_stock_lots_of';
    public const BOOK_BACKFILL = 'taxmod_stock_book_backfill';
    public const BOOK_SALE = 'taxmod_stock_book_sale';
    public const CANCEL_SALE = 'taxmod_stock_cancel_sale';

    /** The eBay module's own stage notes (D-985), read only until carried over into the sales (D-1528). */
    public const EBAY_STAGES = 'taxmod_ebay_stages';

    /** Set once the eBay page's stages are written into the sales' field. */
    public const CARRIED_OVER = 'taxmod_sales_stages_carried_over';

    /** The counts of the stages, for the navigation, which must not read every sale on every admin page (CD-7). */
    public const STAGE_COUNTS = 'taxmod_sales_stage_counts';

    /** @var list<Sale>|null */
    private ?array $sales = null;

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    /** Where the sales live in the model: the modeller's one role table (D-1394). */
    public function roles(): RoleMap
    {
        return ModelDoor::roles();
    }

    /** @return list<string> the required roles of this module not set on this site, e.g. «fields.item_material» */
    public function missingRoles(): array
    {
        return array_values(array_map(static fn (array $role): string => $role['group'] . '.' . $role['key'],
            array_filter(ModelDoor::missingRoles(), static fn (array $role): bool => $role['module'] === Plugin::ROLE_MODULE)));
    }

    /**
     * D-1394: the module's own id table goes into the role table once; nothing set there is overwritten. With no conflict the
     * own table is deleted, with one it stays and the conflict goes to the log.
     */
    public static function handOverStructure(): void
    {
        $stored = get_option(self::STRUCTURE);

        if (! is_array($stored)) {
            return;
        }

        $conflicts = ModelDoor::adoptRoles($stored);

        if ($conflicts !== []) {
            error_log('Taxmod Sales: roles set differently in the modeller: ' . wp_json_encode($conflicts));

            return;
        }

        delete_option(self::STRUCTURE);
    }

    /** @return list<Sale> newest first; one read of each node per request */
    public function sales(): array
    {
        $s = $this->roles();

        if ($this->sales !== null) {
            return $this->sales;
        }

        $values = [];

        foreach (Stage::ALL as $stage) {
            $id = $s->find('values', $stage);

            if ($id !== null) {
                $values[$id] = $stage;
            }
        }

        $carried = (bool) get_option(self::CARRIED_OVER, false);
        $ebay    = [];

        foreach ($carried ? [] : (array) get_option(self::EBAY_STAGES, []) as $order => $note) {
            if (is_array($note) && is_string($note['stage'] ?? null)) {
                $ebay[(string) $order] = $note['stage'];
            }
        }

        $this->sales = Sales::of($s->field(...), $this->model->records($s->node('sale')), $this->model->records($s->node('sold_item')),
            $this->canStage() ? $s->field('sale_stage') : null, $values, $ebay);
        set_transient(self::STAGE_COUNTS, Stage::counts($this->sales), DAY_IN_SECONDS);

        return $this->sales;
    }

    /** Whether the model has the field «Stufe» and every stage constant (D-1528); without them the stages are read, not set. */
    public function canStage(): bool
    {
        $s = $this->roles();

        if ($s->find('fields', 'sale_stage') === null) {
            return false;
        }

        foreach (Stage::ALL as $stage) {
            if ($s->find('values', $stage) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves sales to a stage — one write, one step to take back (D-1410). A sale outside `$from` stays where it is, so a second
     * click on a stale page moves nothing twice.
     *
     * @param list<int> $ids
     * @return int how many moved
     */
    public function moveStage(array $ids, string $from, string $to): int
    {
        if (! $this->canStage()) {
            throw SalesError::noStageField();
        }

        $s     = $this->roles();
        $moved = array_values(array_filter($this->sales(), static fn (Sale $sale): bool => in_array($sale->id, $ids, true) && $sale->stage === $from));

        if ($moved === []) {
            return 0;
        }

        $counts = Stage::counts($this->sales());
        $this->model->write(array_map(static fn (Sale $sale): array => ['op' => 'set_values', 'record' => (string) $sale->id,
            'values' => [(string) $s->field('sale_stage') => (string) $s->value($to)]], $moved));
        // The door may answer this request's earlier read again, so the navigation's count is moved by hand, not re-read.
        $counts[$from] -= count($moved);
        $counts[$to]   += count($moved);
        set_transient(self::STAGE_COUNTS, $counts, DAY_IN_SECONDS);
        $this->sales = null;

        return count($moved);
    }

    /**
     * Writes the eBay page's stages into the sales once (Prüftor condition 1): the sales whose field is empty take the stage the
     * eBay page shows; afterwards the option is no longer read. An act of its own («Stufen aus eBay übernehmen», CD-5), run once.
     *
     * @return int how many sales were written
     */
    public function carryOver(): int
    {
        if (! $this->canStage()) {
            throw SalesError::noStageField();
        }

        if ($this->carriedOver()) {
            return 0;
        }

        $s     = $this->roles();
        $carry = Sales::toCarryOver($this->sales());

        if ($carry !== []) {
            $this->model->write(array_map(static fn (int $id, string $stage): array => ['op' => 'set_values', 'record' => (string) $id,
                'values' => [(string) $s->field('sale_stage') => (string) $s->value($stage)]], array_keys($carry), $carry));
        }

        update_option(self::CARRIED_OVER, gmdate('c'), false);

        return count($carry);
    }

    public function carriedOver(): bool
    {
        return (bool) get_option(self::CARRIED_OVER, false);
    }

    /** @return array<string, int>|null the last counted stages, or null if nothing was counted yet */
    public static function stageCounts(): ?array
    {
        $counts = get_transient(self::STAGE_COUNTS);

        return is_array($counts) ? array_map('intval', $counts) : null;
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
        $s = $this->roles();

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
        $s        = $this->roles();
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

    /** Takes an item's material back (V1); a booked item keeps it until its booking is taken back. */
    public function unassign(int $item): void
    {
        $this->model->write([['op' => 'set_values', 'record' => (string) $item, 'values' => [(string) $this->roles()->field('item_material') => '']]]);
        $this->sales = null;
    }

    /**
     * Takes a sale's booking back (V1, V2: a Storno at the stock, nothing deleted): the goods issue
     * is cancelled, the items forget their lot. A lot recorded for it stays (D-1262: the piece is still there).
     */
    public function cancel(int $saleId, string $who): void
    {
        if (! has_filter(self::CANCEL_SALE)) {
            throw SalesError::noStockModule();
        }

        $sale     = $this->sale($saleId);
        $relation = $this->roles()->field('item_lot');

        // S1: the Storno and the items' lots as one act — a failed write leaves no Storno behind.
        ModelDoor::inAct(function () use ($sale, $who, $relation): void {
            $this->stock(self::CANCEL_SALE, ['sale' => $sale->id, 'who' => $who]);
            $this->model->write(array_values(array_map(static fn ($item): array => ['op' => 'set_values', 'record' => (string) $item->id, 'values' => [(string) $relation => '']],
                array_filter($sale->items, static fn ($item): bool => $item->isBooked()))));
        });
        $this->sales = null;
    }

    /** Whether the stock module offers the bookings a sale needs. */
    public function canBook(): bool
    {
        return has_filter(self::LOTS_OF) && has_filter(self::BOOK_BACKFILL) && has_filter(self::BOOK_SALE);
    }

    /**
     * Books one sale (D-1260): each item leaves the oldest lot of its material that holds it, or a lot recorded for it on the
     * day of the sale; the lot is written to the item. Answers the goods issue's id.
     *
     * Recording, goods issue and the items' lots are one act (S1, D-1419): if the model write fails, the issue is rolled back with
     * it, so a second try cannot book the sale twice.
     */
    public function book(int $saleId): int
    {
        if (! $this->canBook()) {
            throw SalesError::noStockModule();
        }

        $sale      = $this->sale($saleId);
        $materials = array_values(array_unique(array_filter(array_map(static fn ($item): ?int => $item->material, $sale->items))));
        $plan      = BookingPlan::of($sale, self::lotList($this->stock(self::LOTS_OF, ['materials' => $materials])));
        $relation  = $this->roles()->field('item_lot');

        $answer = ModelDoor::inAct(function () use ($sale, $plan, $relation): int {
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
                'lines' => self::issueLines($plan),
                'date'  => $sale->day(),
                'title' => sprintf(__('Verkauf %s', 'taxmod-sales'), $sale->number),
            ]);
            $this->model->write(array_map(static fn (array $line): array => ['op' => 'set_values', 'record' => (string) $line['item'], 'values' => [(string) $relation => (string) $line['lot']]], $plan));

            return $issue;
        });
        $this->sales = null;

        return $answer['result'];
    }

    /**
     * The stock's answer «material → lot → rest, oldest first» as the plan reads it.
     *
     * @param mixed $answer `mixed`: a filter's
     * @return array<int, list<array{lot: int, rest: float}>>
     */
    private static function lotList(mixed $answer): array
    {
        $out = [];

        foreach (is_array($answer) ? $answer : [] as $material => $lots) {
            foreach (is_array($lots) ? $lots : [] as $lot => $rest) {
                $out[(int) $material][] = ['lot' => (int) $lot, 'rest' => (float) $rest];
            }
        }

        return $out;
    }

    /**
     * Lot → pieces, as the stock books a goods issue; two items from one lot add up.
     *
     * @param list<array{item: int, material: int, count: float, lot: int|null}> $plan
     * @return array<int, string>
     */
    private static function issueLines(array $plan): array
    {
        $lines = [];

        foreach ($plan as $line) {
            $lines[(int) $line['lot']] = ($lines[(int) $line['lot']] ?? 0.0) + $line['count'];
        }

        return array_map(static fn (float $pieces): string => (string) $pieces, $lines);
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
