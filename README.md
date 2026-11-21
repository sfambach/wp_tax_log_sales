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

- **Warenausgang (D-1528)**: one process for every channel, each stage a screen of its own in the navigation with its open
  sales: **Verkauft → Bereitstellen → Packen → Etikett → Übergeben → Unterwegs → Zugestellt**. The stage is the field «Stufe»
  of the sale; menu and process bar read the same count. Until the field is in the model the stages are only shown, read from
  the eBay shipping page (`taxmod_ebay_stages`); once it is there, the button «Stufen aus eBay übernehmen» writes them into the field once (an act of its own, CD-5) and that option is no longer read.
  On from **Bereitstellen** picks every item not yet booked into the place with the function «Warenausgang» (`taxmod_stock_book_pick`, the
  sale as Bezug, the fullest place first) and moves the sale to «Packen» in one act; back from «Packen» takes the pick back
  (`taxmod_stock_cancel_pick`). An item booked before (D-1260) has left the stock and is not picked. No eBay message yet.

Concept and decisions: [`docs/pakete/verkauf/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/verkauf/konzept.md),
D-1260 in the modeller's decision log.

## Requirements

- Taxonomy Modeller 0.2.180 or newer (`ModelDoor`, the shared list page, the one role table D-1394, `ListPage::listHead` D-1526).
- Taxmod Stock for the navigation; for booking, the stock's sale bookings; for «Bereitstellen» Taxmod Stock 0.64.186 (field
  «Platzfunktion» on the place, `taxmod_stock_places_with_function`, `taxmod_stock_cancel_pick`).
- Two fields on «Verkaufte Artikel»: «Material» (record reference to the material node, 0..1) and «Bestandsposten»
  (aggregation to the lots, 0..1). Their ids are roles in the modeller's role table (D-1394); until every role is set, the
  page lists the missing ones.
- For the stages: the field «Stufe» on «Verkäufe» pointing at the constants «Warenausgangsstufen» (roles `sale_stage`,
  `stage_sold` … `stage_delivered`, optional).

## Layout

- `src/Core`: domain, no WordPress (CD-1): the roles it reads, sales and items, the booking plan, the provision lines.
- `src/WordPress`: the boundary: the model gateway, the service and the admin page.
- `tests/Core`: PHPUnit, no WordPress.

## Not yet built

- The shipment record, the eBay shipping page moving here, stock leaving at «Übergeben» (D-1528 stages 2–4); offers, returns, payment.
- A sale entered by hand.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
