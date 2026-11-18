<?php declare(strict_types=1);
/**
 * Booking a sale is one act (S1, D-1419): recording, goods issue and the items' lots run in one `ModelDoor::inAct`, so a failed
 * model write takes the stock's bookings back with it and a second try cannot book the sale twice.
 *
 *     php scripts/dev/book-act-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller (0.2.126 or later, for ModelDoor::inAct) active. Checks the bracket with a stock-like write that the
 * failing model write must take back, with the counter-test outside the bracket; `book()` and `cancel()` themselves are checked by
 * reading them, since a real sale needs the stock module and the sales structure on the site.
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

use Taxmod\WordPress\ModelDoor;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

wp_set_current_user(1);
$mark = '__taxmod_sales_s1_check';
$issue = static fn (): bool => (bool) $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->options, ['option_name' => $mark, 'option_value' => 'issue', 'autoload' => 'no']);
$booked = static fn (): bool => $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $mark)) === '1';
$clear = static fn () => $GLOBALS['wpdb']->delete($GLOBALS['wpdb']->options, ['option_name' => $mark]);
$clear();

echo "\n== ein gescheitertes Schreiben nimmt den Abgang zurück ==\n";
check('the modeller brings ModelDoor::inAct', method_exists(ModelDoor::class, 'inAct'));

try {
    ModelDoor::inAct(static function () use ($issue): void {
        $issue();
        throw new RuntimeException('model write fails');
    });
    check('the failure comes through', false);
} catch (RuntimeException) {
    check('the failure comes through', true);
}

check('inside the act: no goods issue left behind', ! $booked());

echo "\n== Gegenprobe ==\n";
$issue();
check('outside the act the same write stays (what S1 found)', $booked());
$clear();

echo "\n== book() und cancel() buchen in einem Akt ==\n";
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/SalesService.php');

foreach (['book' => ['BOOK_BACKFILL', 'BOOK_SALE'], 'cancel' => ['CANCEL_SALE']] as $method => $filters) {
    preg_match('/function ' . $method . '\(.*?\n    }\n/s', $source, $body);
    $body = $body[0] ?? '';
    $act  = strpos($body, 'ModelDoor::inAct(');
    $ok   = $act !== false && str_contains(substr($body, $act), '$this->model->write(');

    foreach ($filters as $filter) {
        $at = strrpos($body, '$this->stock(self::' . $filter);
        $ok = $ok && $at !== false && $at > $act;
    }

    check($method . '(): stock bookings and model write inside ModelDoor::inAct', $ok);
}

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
