<?php declare(strict_types=1);

namespace TaxmodSales\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\RecordPick;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\SoldItem;
use TaxmodSales\Core\Stage;
use TaxmodSales\WordPress\Plugin;
use TaxmodSales\WordPress\SalesService;

/**
 * Logistik › Warenausgang › one stage (D-1528): the sales standing in it, the process bar over all stages, and the step on or
 * back. Every stage is a screen of its own; bar and menu read the same counts ({@see Stage::counts()}, D-1258).
 *
 * @see /mnt/project-files/taxmod-warenausgang/konzept-vorschlag.md §2, §6
 */
final class StagePage
{
    public const ACTION = 'taxmod_sales_stage';
    public const BULK_NEXT = 'taxmod_sales_stage_next';
    public const BULK_BACK = 'taxmod_sales_stage_back';
    public const CARRY_OVER = 'taxmod_sales_stage_carry';

    /** @var list<string> the admin-post actions this page answers */
    public const ACTIONS = [self::ACTION, self::BULK_NEXT, self::BULK_BACK, self::CARRY_OVER];

    private const NONCE = 'taxmod_sales_stage';
    private const FORM = 'taxmod-sales-stage-bulk';
    private const STEP_FORM = 'taxmod-sales-stage-step';

    /** The page address of a stage, owned here so the boot names it without compiling the page (D-1187). */
    public static function slug(string $stage): string
    {
        return 'taxmod-wa-' . str_replace('_', '-', substr($stage, strlen('stage_')));
    }

    /** @var array<int, string>|null */
    private ?array $outbound = null;

    public function __construct(private readonly string $stage, private readonly SalesService $service = new SalesService())
    {
    }

    public function render(): string
    {
        $names   = self::names();
        $page    = fn (string $html, string $bar = '', ?int $count = null): string => ListPage::page(
            /* translators: 1: stage, 2: number of sales in it */
            $count === null ? $names[$this->stage] : sprintf(__('%1$s (%2$s)', 'taxmod-sales'), $names[$this->stage], ListPage::number($count, 0)),
            [], [['html' => $html]], Plugin::VERSION, 'Taxmod Sales', self::hint($this->stage), $bar);
        $missing = $this->service->missingRoles();

        if ($missing !== []) {
            return $page('<div class="notice notice-warning inline"><p>' . esc_html__('Das Modell des Verkaufs ist noch nicht eingespielt. Es fehlen:', 'taxmod-sales')
                . ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>');
        }

        try {
            $sales = $this->service->sales();
        } catch (SalesError $error) {
            return $page('<div class="notice notice-error inline"><p>' . esc_html($error->getMessage()) . '</p></div>');
        }

        $counts = Stage::counts($sales);
        $bar    = self::bar($counts, $this->stage);
        $shown  = array_values(array_filter($sales, fn (Sale $sale): bool => $sale->stage === $this->stage));

        // Stefan 20.11. 19:52 «das zugestellt könnte in den seiten kopf noch integriert werden»: the stage and its count are the page's
        // own heading, no second head above the list.
        return $page($this->notices() . $this->list($shown), $bar, count($shown));
    }

    /**
     * The process bar of the Warenausgang (D-1258, D-1523): `ListPage::steps` with the counts, each arrow its stage's screen.
     *
     * @param array<string, int>|null $counts null: not counted yet, the arrows without numbers
     */
    public static function bar(?array $counts, string $active = ''): string
    {
        $names = self::names();

        return ListPage::steps(array_map(static fn (string $stage): array => $counts === null ? [$stage, $names[$stage]] : [$stage, $names[$stage], (int) ($counts[$stage] ?? 0)], Stage::ALL),
            $active, static fn (string $stage): string => self::url($stage));
    }

    /**
     * admin-post: capability → nonce → validate → sanitize → act → back to the stage it came from (CD-5, D-1254).
     */
    public function handle(string $action): void
    {
        $stage = self::stageOf((string) ($_POST['stage'] ?? ''));
        $back  = self::url($stage ?? Stage::SOLD);

        ListPage::act(function () use ($action, $stage): string {
            if (! current_user_can('manage_options')) {
                ListPage::fail(__('Keine Berechtigung.', 'taxmod-sales'));
            }

            ListPage::nonce(self::NONCE);

            if ($action === self::CARRY_OVER) {
                try {
                    $carried = $this->service->carryOver();
                } catch (SalesError $error) {
                    ListPage::fail($error->getMessage());
                }

                return add_query_arg(['msg' => 'carried', 'n' => $carried], self::url($stage ?? Stage::SOLD));
            }

            if ($stage === null) {
                ListPage::fail(__('Unbekannte Stufe.', 'taxmod-sales'));
            }

            // A row button says way and sale in one value («next:<id>»): all rows share one form outside the table.
            [$way, $sale] = array_pad(explode(':', sanitize_text_field(wp_unslash((string) ($_POST['do'] ?? ''))), 2), 2, '');
            $forward      = $action === self::BULK_NEXT || ($action === self::ACTION && $way === 'next');
            $to           = $forward ? Stage::next($stage) : Stage::previous($stage);
            $ids          = $action === self::ACTION ? [absint($sale)] : array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))));

            if ($to === null || $ids === [] || $ids === [0]) {
                ListPage::fail(__('Hier geht es nicht weiter.', 'taxmod-sales'));
            }

            try {
                // D-1528 Etappe 2: on from «Bereitstellen» picks into the outbound place, back from «Packen» takes it back.
                $moved = match (true) {
                    $stage === Stage::PROVIDE && $forward && $this->service->canProvide() => $this->service->provide($ids, $this->box()),
                    $stage === Stage::PACK && ! $forward && $this->service->canProvide()  => $this->service->unprovide($ids),
                    default                                                               => $this->service->moveStage($ids, $stage, $to),
                };
            } catch (SalesError $error) {
                ListPage::fail($error->getMessage());
            }

            return add_query_arg(['msg' => 'moved', 'n' => $moved, 'to' => $to], self::url($stage));
        }, $back);
    }

    /** The outbound place a step names, or the only one there is; several and none chosen is refused. */
    private function box(): int
    {
        $box = absint($_POST['box'] ?? 0);

        if ($box === 0 && count((new self(Stage::PROVIDE, $this->service))->outbound()) > 1) {
            ListPage::fail(__('Mehrere Warenausgangsplätze: mit dem Pfeil in der Zeile bereitstellen, der Platz steht über der Liste.', 'taxmod-sales'));
        }

        return $box;
    }

    /** @param list<Sale> $shown */
    private function list(array $shown): string
    {
        $canStage = $this->service->canStage();
        $next     = Stage::next($this->stage);
        $previous = Stage::previous($this->stage);
        $names    = self::names();
        $bulk     = ! $canStage ? [] : array_filter([
            /* translators: %s: the next stage */
            self::BULK_NEXT => $next === null ? '' : sprintf(__('Weiter: %s', 'taxmod-sales'), $names[$next]),
            /* translators: %s: the previous stage */
            self::BULK_BACK => $previous === null ? '' : sprintf(__('Zurück: %s', 'taxmod-sales'), $names[$previous]),
        ]);
        // The hidden fields stand before the bar: list-head.js finds the bar only right above the table (one row).
        $html     = '<input type="hidden" name="stage" value="' . esc_attr($this->stage) . '" form="' . esc_attr(self::FORM) . '">' . $this->boxField(self::FORM)
            . ($canStage ? $this->stepForm() : '')
            . ListPage::bulkBar(self::FORM, self::NONCE, $bulk, '', '', true);
        $rows     = '';
        [$free, $labels] = $this->whereFree($shown);

        foreach ($shown as $sale) {
            $items = implode('<br>', array_map(fn (SoldItem $item): string => esc_html(ListPage::number($item->count, 2) . ' × ' . $item->label)
                . ($free === null ? '' : $this->lies($item, $free, $labels)), $sale->items));

            $rows .= ListPage::row(self::FORM, (string) $sale->id, [
                ListPage::dateCell($sale->day()),
                esc_html($sale->platform . ' ' . $sale->number),
                esc_html($sale->buyer),
                $items,
            ], $canStage ? $this->stepButtons($sale, $previous, $next) : '', ListPage::lazyDetails(SalesPage::DETAILS, ['sale' => (string) $sale->id, 'tab' => 'all']));
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="6">' . esc_html__('Keine Verkäufe in dieser Stufe.', 'taxmod-sales') . '</td></tr>';
        }

        return $html . ListPage::table([
            [__('Verkauft am', 'taxmod-sales'), '8em'],
            [__('Verkauf', 'taxmod-sales'), '12em'],
            [__('Käufer', 'taxmod-sales'), '10em'],
            [__('Artikel', 'taxmod-sales')],
        ], $rows);
    }

    /**
     * On «Bereitstellen» only: where the items lie free and the place names, one ask for the whole list (CD-7).
     *
     * @param list<Sale> $shown
     * @return array{0: array<int, array<int, float>>|null, 1: array<int, string>}
     */
    private function whereFree(array $shown): array
    {
        if ($this->stage !== Stage::PROVIDE || ! $this->service->canProvide() || $shown === []) {
            return [null, []];
        }

        try {
            $free   = $this->service->freeOf($shown);
            $places = array_merge(...array_map(static fn (array $at): array => array_keys($at), array_values($free ?: [[]])));

            return [$free, $this->service->labelsOf(array_values(array_unique($places)))];
        } catch (SalesError) {
            return [null, []];
        }
    }

    /**
     * @param array<int, array<int, float>> $free
     * @param array<int, string> $labels
     */
    private function lies(SoldItem $item, array $free, array $labels): string
    {
        if ($item->isBooked()) {
            return ' <span class="description">' . esc_html__('(schon abgebucht)', 'taxmod-sales') . '</span>';
        }

        if ($item->material === null) {
            return ' <span class="description">' . esc_html__('(kein Material)', 'taxmod-sales') . '</span>';
        }

        $at = $free[$item->material] ?? [];
        arsort($at);

        return ' <span class="description">' . ($at === [] ? esc_html__('(kein freier Bestand)', 'taxmod-sales')
            : esc_html(sprintf(
                /* translators: %s: places with free pieces */
                __('(liegt: %s)', 'taxmod-sales'),
                implode(', ', array_map(static fn (int $place, float $pieces): string => ($labels[$place] ?? '#' . $place) . ' ' . ListPage::number($pieces, 2), array_keys($at), $at))
            ))) . '</span>';
    }

    /** The one form every row's step buttons submit; it stands outside the table so the buttons sit in the Actions cell (Prüftor 20.11.). */
    private function stepForm(): string
    {
        return '<form method="post" id="' . esc_attr(self::STEP_FORM) . '" hidden action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="stage" value="' . esc_attr($this->stage) . '">' . $this->boxField(self::STEP_FORM) . '</form>';
    }

    /** Back and on as icons in the row's Actions cell (D-1500); the button pressed says which way and which sale. */
    private function stepButtons(Sale $sale, ?string $previous, ?string $next): string
    {
        $names = self::names();
        $id    = (string) $sale->id;

        /* translators: %s: the previous stage */
        return ($previous === null ? '' : ListPage::iconButton(ListPage::ICON_UNDO, sprintf(__('Zurück: %s', 'taxmod-sales'), $names[$previous]), '', 'do', 'back:' . $id, self::STEP_FORM))
            /* translators: %s: the next stage */
            . ($next === null ? '' : ' ' . ListPage::iconButton('arrow-right-alt', sprintf(__('Weiter: %s', 'taxmod-sales'), $names[$next]), '', 'do', 'next:' . $id, self::STEP_FORM));
    }

    /**
     * The outbound place «Bereitstellen» puts the goods on when there is only one; with several, the row buttons take the one
     * chosen above the list (RecordPick, D-1488) and the bulk step refuses.
     */
    private function boxField(string $form): string
    {
        $places = $this->outbound();

        return count($places) === 1 ? '<input type="hidden" name="box" value="' . esc_attr((string) array_key_first($places)) . '" form="' . esc_attr($form) . '">' : '';
    }

    /** @return array<int, string> outbound place → label; empty outside «Bereitstellen» */
    private function outbound(): array
    {
        if ($this->outbound === null) {
            try {
                $this->outbound = $this->stage === Stage::PROVIDE && $this->service->canProvide() ? $this->service->outboundPlaces() : [];
            } catch (SalesError) {
                $this->outbound = [];
            }
        }

        return $this->outbound;
    }

    private function notices(): string
    {
        $html = '';

        if ($this->stage === Stage::PROVIDE && $this->service->canProvide()) {
            $places = $this->outbound();

            if ($places === []) {
                $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('Kein Warenausgangsplatz: einem Lagerort die Platzfunktion «Warenausgang» geben.', 'taxmod-sales') . '</p></div>';
            } elseif (count($places) === 1) {
                /* translators: %s: the outbound place */
                $html .= '<p class="description">' . esc_html(sprintf(__('Weiter bucht die Ware auf den Warenausgangsplatz %s.', 'taxmod-sales'), reset($places))) . '</p>';
            } else {
                $html .= '<p>' . esc_html__('Warenausgangsplatz', 'taxmod-sales') . ' '
                    . RecordPick::field('box', [['depth' => 0, 'name' => __('Warenausgangsplätze', 'taxmod-sales'), 'records' => $places]], (int) array_key_first($places), self::STEP_FORM, false) . '</p>';
            }
        }
        $n    = absint($_GET['n'] ?? 0);

        if (! $this->service->canStage()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('Das Feld «Stufe» ist noch nicht im Modell: die Stufen werden nur gezeigt (aus der eBay-Versandseite), nicht gesetzt.', 'taxmod-sales') . '</p></div>';
        } elseif (! $this->service->carriedOver()) {
            // Prüftor 20.11.: the carry-over writes, so it is an act of its own (CD-5), never a side effect of drawing the page.
            $html .= '<div class="notice notice-info inline"><form method="post" class="taxmod-acts" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::CARRY_OVER) . '">'
                . '<input type="hidden" name="stage" value="' . esc_attr($this->stage) . '"><p>'
                . esc_html__('Die Stufen der eBay-Versandseite stehen noch nicht am Verkauf; sie werden bis dahin von dort gelesen.', 'taxmod-sales') . '</p> '
                . get_submit_button(__('Stufen aus eBay übernehmen', 'taxmod-sales'), 'secondary small', 'submit', false) . '</form></div>';
        }

        if (sanitize_key((string) ($_GET['msg'] ?? '')) === 'carried') {
            /* translators: %d: number of sales */
            $html .= '<div class="notice notice-success inline"><p>' . esc_html(sprintf(_n('Die Stufe von %d Verkauf wurde aus der eBay-Versandseite übernommen.', 'Die Stufe von %d Verkäufen wurde aus der eBay-Versandseite übernommen.', $n, 'taxmod-sales'), $n)) . '</p></div>';
        }

        $to = self::stageOf((string) ($_GET['to'] ?? ''));

        if (sanitize_key((string) ($_GET['msg'] ?? '')) === 'moved' && $to !== null) {
            /* translators: 1: number of sales, 2: stage */
            $html .= '<div class="notice notice-success inline"><p>' . esc_html(sprintf(_n('%1$d Verkauf nach «%2$s».', '%1$d Verkäufe nach «%2$s».', $n, 'taxmod-sales'), $n, self::names()[$to])) . '</p></div>';
        }

        return $html;
    }

    private static function stageOf(string $raw): ?string
    {
        $raw = sanitize_key($raw);

        return in_array($raw, Stage::ALL, true) ? $raw : null;
    }

    /** @return array<string, string> stage → its name on screen and in the navigation */
    public static function names(): array
    {
        return [
            Stage::SOLD      => __('Verkauft', 'taxmod-sales'),
            Stage::PROVIDE   => __('Bereitstellen', 'taxmod-sales'),
            Stage::PACK      => __('Packen', 'taxmod-sales'),
            Stage::LABEL     => __('Etikett', 'taxmod-sales'),
            Stage::HANDOVER  => __('Übergeben', 'taxmod-sales'),
            Stage::TRANSIT   => __('Unterwegs', 'taxmod-sales'),
            Stage::DELIVERED => __('Zugestellt', 'taxmod-sales'),
        ];
    }

    /** The page's explanation behind the «?» (D-1526). */
    private static function hint(string $stage): string
    {
        return match ($stage) {
            Stage::SOLD      => __('Neue Verkäufe aller Kanäle. Weiter, sobald die Ware bereitgestellt werden soll.', 'taxmod-sales'),
            Stage::PROVIDE   => __('Ware kommissionieren und auf den Warenausgangsplatz legen.', 'taxmod-sales'),
            Stage::PACK      => __('Bereitgestellte Ware verpacken, Beileger dazu.', 'taxmod-sales'),
            Stage::LABEL     => __('Porto kaufen und Etikett drucken (Versand). Gedruckt ist noch nicht versendet.', 'taxmod-sales'),
            Stage::HANDOVER  => __('Paket liegt bereit. Weiter erst, wenn es im Briefkasten oder Shop ist.', 'taxmod-sales'),
            Stage::TRANSIT   => __('Übergeben, beim Paketdienst unterwegs.', 'taxmod-sales'),
            default          => __('Beim Käufer angekommen.', 'taxmod-sales'),
        };
    }

    public static function url(string $stage): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::slug($stage)], admin_url('admin.php'));
    }
}
