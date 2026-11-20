<?php declare(strict_types=1);

namespace TaxmodSales\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
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

    /** @var list<string> the admin-post actions this page answers */
    public const ACTIONS = [self::ACTION, self::BULK_NEXT, self::BULK_BACK];

    private const NONCE = 'taxmod_sales_stage';
    private const FORM = 'taxmod-sales-stage-bulk';

    /** The page address of a stage, owned here so the boot names it without compiling the page (D-1187). */
    public static function slug(string $stage): string
    {
        return 'taxmod-wa-' . str_replace('_', '-', substr($stage, strlen('stage_')));
    }

    public function __construct(private readonly string $stage, private readonly SalesService $service = new SalesService())
    {
    }

    public function render(): string
    {
        $names   = self::names();
        $page    = fn (string $html, string $bar = ''): string => ListPage::page(__('Warenausgang', 'taxmod-sales'), [], [['html' => $html]], Plugin::VERSION, 'Taxmod Sales', '', $bar);
        $missing = $this->service->missingRoles();

        if ($missing !== []) {
            return $page('<div class="notice notice-warning inline"><p>' . esc_html__('Das Modell des Verkaufs ist noch nicht eingespielt. Es fehlen:', 'taxmod-sales')
                . ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>');
        }

        try {
            $carried = $this->service->carryOver();
            $sales   = $this->service->sales();
        } catch (SalesError $error) {
            return $page('<div class="notice notice-error inline"><p>' . esc_html($error->getMessage()) . '</p></div>');
        }

        $counts = Stage::counts($sales);
        $bar    = self::bar($counts, $this->stage);
        $shown  = array_values(array_filter($sales, fn (Sale $sale): bool => $sale->stage === $this->stage));

        return $page(ListPage::listHead($names[$this->stage], count($shown), self::hint($this->stage))
            . $this->notices($carried) . $this->list($shown), $bar);
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

            if ($stage === null) {
                ListPage::fail(__('Unbekannte Stufe.', 'taxmod-sales'));
            }

            $forward = $action === self::BULK_NEXT || ($action === self::ACTION && sanitize_key((string) ($_POST['do'] ?? '')) === 'next');
            $to      = $forward ? Stage::next($stage) : Stage::previous($stage);
            $ids     = $action === self::ACTION ? [absint($_POST['sale'] ?? 0)] : array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))));

            if ($to === null || $ids === [] || $ids === [0]) {
                ListPage::fail(__('Hier geht es nicht weiter.', 'taxmod-sales'));
            }

            try {
                $moved = $this->service->moveStage($ids, $stage, $to);
            } catch (SalesError $error) {
                ListPage::fail($error->getMessage());
            }

            return add_query_arg(['msg' => 'moved', 'n' => $moved, 'to' => $to], self::url($stage));
        }, $back);
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
        $html     = ListPage::bulkBar(self::FORM, self::NONCE, $bulk, '', '', true)
            . '<input type="hidden" name="stage" value="' . esc_attr($this->stage) . '" form="' . esc_attr(self::FORM) . '">';
        $rows     = '';

        foreach ($shown as $sale) {
            $items = implode('<br>', array_map(static fn (SoldItem $item): string => esc_html(ListPage::number($item->count, 2) . ' × ' . $item->label), $sale->items));

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

    /** Back and on as one little form (one line of icons, D-1500); the button pressed says which way. */
    private function stepButtons(Sale $sale, ?string $previous, ?string $next): string
    {
        $names = self::names();

        return '<form method="post" class="taxmod-acts" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="sale" value="' . esc_attr((string) $sale->id) . '"><input type="hidden" name="stage" value="' . esc_attr($this->stage) . '">'
            /* translators: %s: the previous stage */
            . ($previous === null ? '' : ListPage::iconButton(ListPage::ICON_UNDO, sprintf(__('Zurück: %s', 'taxmod-sales'), $names[$previous]), '', 'do', 'back'))
            /* translators: %s: the next stage */
            . ($next === null ? '' : ListPage::iconButton('arrow-right-alt', sprintf(__('Weiter: %s', 'taxmod-sales'), $names[$next]), '', 'do', 'next'))
            . '</form>';
    }

    private function notices(int $carried): string
    {
        $html = '';

        if (! $this->service->canStage()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('Das Feld «Stufe» ist noch nicht im Modell: die Stufen werden nur gezeigt (aus der eBay-Versandseite), nicht gesetzt.', 'taxmod-sales') . '</p></div>';
        }

        if ($carried > 0) {
            /* translators: %d: number of sales */
            $html .= '<div class="notice notice-info inline"><p>' . esc_html(sprintf(_n('Die Stufe von %d Verkauf wurde aus der eBay-Versandseite übernommen.', 'Die Stufe von %d Verkäufen wurde aus der eBay-Versandseite übernommen.', $carried, 'taxmod-sales'), $carried)) . '</p></div>';
        }

        $n  = absint($_GET['n'] ?? 0);
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
