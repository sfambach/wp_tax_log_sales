<?php declare(strict_types=1);

namespace TaxmodSales\WordPress\Admin;

use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileTone;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodSales\Core\Sale;
use TaxmodSales\Core\Sales;
use TaxmodSales\Core\SalesError;
use TaxmodSales\Core\SoldItem;
use TaxmodSales\WordPress\Plugin;
use TaxmodSales\WordPress\SalesService;

/**
 * Logistik › Warenausgang › Verkäufe: every sale, its items' material, and its booking (D-1260). Drawn as the standard page
 * (S6): heading, process, tiles, then the list.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/verkauf/konzept.md §4, §8
 */
final class SalesPage
{
    public const ACTION = 'taxmod_sales';
    public const BULK_BOOK = 'taxmod_sales_bulk_book';

    /** @var list<string> the admin-post actions this page answers */
    public const ACTIONS = [self::ACTION, self::BULK_BOOK];

    private const NONCE = 'taxmod_sales';
    private const FORM = 'taxmod-sales-bulk';
    private const ALL = 'all';

    public function __construct(private readonly SalesService $service = new SalesService())
    {
    }

    public function render(): string
    {
        $screen = __('Verkäufe', 'taxmod-sales');
        $hint   = __('Ein Verkauf ist gebucht, wenn jeder Artikel ein Material hat und aus einem Bestandsposten abgegangen ist. Fehlt der Bestand, wird er zum Verkaufsdatum nacherfasst (D-1260).', 'taxmod-sales');
        $page   = static fn (array $sections, array $tiles = [], string $tabs = ''): string => ListPage::page($screen, $tiles, $sections, Plugin::VERSION, 'Taxmod Sales', $hint, $tabs);
        $missing = $this->service->structure()->missing();

        if ($missing !== []) {
            return $page([['html' => '<div class="notice notice-warning inline"><p>' . esc_html__('Das Modell des Verkaufs ist noch nicht eingespielt. Es fehlen:', 'taxmod-sales')
                . ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>']]);
        }

        try {
            $sales = $this->service->sales();
        } catch (SalesError $error) {
            return $page([['html' => '<div class="notice notice-error inline"><p>' . esc_html($error->getMessage()) . '</p></div>']]);
        }

        $tab    = $this->tab();
        $counts = Sales::counts($sales);
        $names  = self::stateNames();
        $steps  = array_map(static fn (string $state): array => [$state, $names[$state], $counts[$state]], Sale::STATES);
        $tabs   = ListPage::steps($steps, $tab, fn (string $slug): string => self::url(['tab' => $slug]), [], [self::ALL, __('Alle', 'taxmod-sales'), count($sales)]);
        $tiles  = array_map(fn (string $state): Tile => new Tile($names[$state], self::url(['tab' => $state]), (string) $counts[$state], [],
            $counts[$state] === 0 ? TileTone::Empty : ($state === Sale::BOOKED ? TileTone::Normal : TileTone::Alert)), Sale::STATES);
        $shown  = array_values(array_filter($sales, static fn (Sale $sale): bool => $tab === self::ALL || $sale->state() === $tab));

        return $page([['html' => $this->stockNotice() . $this->list($shown, $tab)]], $tiles, $tabs);
    }

    /**
     * admin-post: capability → nonce → validate → sanitize → act → back to the page (CD-5); as an act, so a failure ends with
     * a message on the page it came from (D-1254).
     */
    public function handle(string $action): void
    {
        $tab  = sanitize_key((string) ($_POST['tab'] ?? ''));
        $back = self::url(['tab' => $tab]);

        ListPage::act(function () use ($action, $tab): string {
            if (! current_user_can('manage_options')) {
                ListPage::fail(__('Keine Berechtigung.', 'taxmod-sales'));
            }

            ListPage::nonce(self::NONCE);

            if ($action === self::BULK_BOOK) {
                $booked = 0;

                foreach (array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))) as $sale) {
                    $this->service->book($sale);
                    $booked++;
                }

                return self::url(['tab' => $tab, 'msg' => 'booked', 'n' => $booked]);
            }

            $do = sanitize_key((string) ($_POST['do'] ?? ''));

            return match ($do) {
                'assign' => self::url(['tab' => $tab, 'msg' => 'assigned', 'n' => $this->service->assign(
                    absint($_POST['item'] ?? 0),
                    is_array($_POST['material'] ?? null) ? wp_unslash($_POST['material']) : [],
                    ! empty($_POST['same_article'])
                )]),
                'book'   => self::url(['tab' => $tab, 'msg' => 'booked', 'n' => $this->service->book(absint($_POST['sale'] ?? 0)) > 0 ? 1 : 0]),
                default  => ListPage::fail(__('Unbekannte Aktion.', 'taxmod-sales')),
            };
        }, $back);
    }

    /** @param list<Sale> $shown */
    private function list(array $shown, string $tab): string
    {
        $count = sprintf(_n('%d Verkauf', '%d Verkäufe', count($shown), 'taxmod-sales'), count($shown));
        $bulk  = $this->service->canBook() ? [self::BULK_BOOK => __('Buchen', 'taxmod-sales')] : [];
        $html  = $this->message() . ListPage::bulkBar(self::FORM, self::NONCE, $bulk, $count, '', true)
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" form="' . esc_attr(self::FORM) . '">';
        $rows  = '';
        $names = self::stateNames();

        foreach ($shown as $sale) {
            $items = implode('<br>', array_map(static fn (SoldItem $item): string => esc_html(self::quantity($item->count) . ' × ' . $item->label)
                . ($item->material === null ? ' <em>(' . esc_html__('ohne Material', 'taxmod-sales') . ')</em>' : ''), $sale->items));

            $rows .= ListPage::row(self::FORM, (string) $sale->id, [
                esc_html($sale->day()),
                esc_html($sale->platform . ' ' . $sale->number),
                esc_html($sale->buyer),
                $items,
                esc_html($names[$sale->state()]),
            ], $this->bookButton($sale, $tab), $this->details($sale, $tab));
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Keine Verkäufe.', 'taxmod-sales') . '</td></tr>';
        }

        return $html . ListPage::table([
            [__('Verkauft am', 'taxmod-sales'), '8em'],
            [__('Verkauf', 'taxmod-sales'), '12em'],
            [__('Käufer', 'taxmod-sales'), '10em'],
            [__('Artikel', 'taxmod-sales')],
            [__('Stand', 'taxmod-sales'), '8em'],
        ], $rows);
    }

    /** The items of a sale, each without a material with its dialog «Material zuordnen». */
    private function details(Sale $sale, string $tab): string
    {
        $html = '<ul>';

        foreach ($sale->items as $item) {
            $state = match (true) {
                $item->isBooked()       => sprintf(__('gebucht aus Bestandsposten %d', 'taxmod-sales'), (int) $item->lot),
                $item->material !== null => sprintf(__('Material %d, nicht gebucht', 'taxmod-sales'), (int) $item->material),
                default                 => __('ohne Material', 'taxmod-sales'),
            };
            $html .= '<li>' . esc_html(self::quantity($item->count) . ' × ' . $item->label . ' — ' . $state)
                . ($item->isBooked() ? '' : ' ' . $this->assignAction($item, $tab)) . '</li>';
        }

        return $html . '</ul>';
    }

    private function assignAction(SoldItem $item, string $tab): string
    {
        $form = 'taxmod-sales-assign-' . $item->id;
        $body = $this->service->materialControl($item->id, $form)
            . '<p><label><input type="checkbox" name="same_article" value="1" form="' . esc_attr($form) . '" checked> '
            . esc_html__('Gleiche Artikel ohne Material mit zuordnen', 'taxmod-sales') . '</label></p>';

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="assign">'
            . '<input type="hidden" name="item" value="' . esc_attr((string) $item->id) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
            . ListPage::dialogAction('taxmod-sales-assign-dialog-' . $item->id, __('Material zuordnen', 'taxmod-sales'), __('Material zuordnen', 'taxmod-sales'),
                $body, 'go', '1', __('Zuordnen', 'taxmod-sales'), $form)
            . '</form>';
    }

    private function bookButton(Sale $sale, string $tab): string
    {
        if ($sale->state() !== Sale::TO_BOOK || ! $this->service->canBook()) {
            return '';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" ' . ListPage::confirm(sprintf(
            /* translators: %s: the sale's number */
            __('Verkauf %s buchen? Fehlender Bestand wird zum Verkaufsdatum nacherfasst.', 'taxmod-sales'), $sale->number)) . '>'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="book">'
            . '<input type="hidden" name="sale" value="' . esc_attr((string) $sale->id) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
            . '<button type="submit" class="button">' . esc_html__('Buchen', 'taxmod-sales') . '</button></form>';
    }

    private function stockNotice(): string
    {
        return $this->service->canBook() ? '' : '<div class="notice notice-warning inline"><p>'
            . esc_html__('Buchen braucht Taxmod Stock mit den Verkaufsbuchungen; bis dahin lässt sich nur das Material zuordnen.', 'taxmod-sales') . '</p></div>';
    }

    private function message(): string
    {
        $n = absint($_GET['n'] ?? 0);

        return match (sanitize_key((string) ($_GET['msg'] ?? ''))) {
            /* translators: %d: number of sold items */
            'assigned' => '<div class="notice notice-success inline"><p>' . esc_html(sprintf(_n('Material an %d Artikel zugeordnet.', 'Material an %d Artikel zugeordnet.', $n, 'taxmod-sales'), $n)) . '</p></div>',
            /* translators: %d: number of sales */
            'booked'   => '<div class="notice notice-success inline"><p>' . esc_html(sprintf(_n('%d Verkauf gebucht.', '%d Verkäufe gebucht.', $n, 'taxmod-sales'), $n)) . '</p></div>',
            default    => '',
        };
    }

    /** @return array<string, string> */
    private static function stateNames(): array
    {
        return [
            Sale::TO_ASSIGN => __('Zuzuordnen', 'taxmod-sales'),
            Sale::TO_BOOK   => __('Zu buchen', 'taxmod-sales'),
            Sale::BOOKED    => __('Gebucht', 'taxmod-sales'),
        ];
    }

    private function tab(): string
    {
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));

        return in_array($tab, [...Sale::STATES, self::ALL], true) ? $tab : Sale::TO_ASSIGN;
    }

    private static function quantity(float $count): string
    {
        return number_format_i18n($count, floor($count) === $count ? 0 : 2);
    }

    /** @param array<string, string|int> $args */
    public static function url(array $args): string
    {
        return add_query_arg(array_filter(['page' => 'taxmod-lager-modul', 'module' => Plugin::PAGE] + $args, static fn ($v): bool => $v !== '' && $v !== 0), admin_url('admin.php'));
    }
}
