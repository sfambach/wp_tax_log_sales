# Taxmod Sales («Verkauf»)

Sales for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler), shown inside the logistics navigation of
[Taxmod Stock](https://github.com/sfambach/wp_tax_stock) under **Warenausgang › Verkäufe**.

- Every sale of every channel (today eBay, from the modeller's import) with its items and where it stands:
  **Zuzuordnen** (an item has no material) → **Zu buchen** → **Gebucht**.
- **Material zuordnen**: the modeller's own record choice for the item's material; items of the same article without a
  material can take it along in the same step.
- **Buchen**: each item leaves the oldest lot of its material that still holds it. Where no lot does — sales from before the
  stock was kept — a lot is recorded first («Nacherfassung», on the day of the sale) and the item leaves that one (D-1260).
  The bookings go through Taxmod Stock (filters `taxmod_stock_lots_of`, `taxmod_stock_book_backfill`,
  `taxmod_stock_book_sale`, Taxmod Stock 0.64.27); the lot is written to the item.
- Every step can be taken back (D-1010): «Zuordnung lösen» empties an item's material, «Buchung zurücknehmen» cancels the
  goods issue at the stock (`taxmod_stock_cancel_sale` with the sale, a Storno) and empties the items' lots; a recorded lot
  stays, as the proof where the piece came from (D-1262).

Concept and decisions: [`docs/pakete/verkauf/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/verkauf/konzept.md),
D-1260 in the modeller's decision log.

## Requirements

- Taxonomy Modeller 0.2.39 or newer (`ModelDoor`, the shared list page with its acts).
- Taxmod Stock for the navigation; for booking, the stock's sale bookings.
- Two fields on «Verkaufte Artikel»: «Material» (record reference to the material node, 0..1) and «Bestandsposten»
  (aggregation to the lots, 0..1). Their ids go into the option `taxmod_sales_structure` (REST `/wp/v2/settings`) or into
  `structure-live.json`; until they are there, the page lists the missing keys.

## Layout

- `src/Core`: domain, no WordPress (CD-1): structure ids, sales and items, the booking plan.
- `src/WordPress`: the boundary: the model gateway, the service and the admin page.
- `tests/Core`: PHPUnit, no WordPress.

## Not yet built

- Shipping stages for every channel (the eBay shipping page moves here later), offers, returns, payment.
- A sale entered by hand.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
