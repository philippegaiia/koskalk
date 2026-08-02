# Production Bench Purchasing and Inventory Redesign

## Status and Scope

This specification redesigns the purchasing and inventory experience introduced in
the first Production Bench implementation. It supersedes the purchasing, supplier
listing, receipt, and inventory-interface sections of
`2026-07-28-production-bench-design.md`.

The following existing decisions remain unchanged:

- Production Bench is an optional workspace entitlement.
- Recipe/Product Bench remains independent and available when Production Bench is
  stopped.
- Production data uses the existing Soapkraft database.
- Ingredients and composed ingredients remain owned by Recipe/Product Bench.
- Ingredient and intermediate inventory is stored as canonical mass; packaging and
  finished products are stored as counts.
- Stock movements are immutable, reservations are distinct from physical stock,
  and negative available stock is allowed.
- Production planning, execution, intermediates, output, and traceability remain
  later Production Bench checkpoints.

This redesign is limited to a professional V1 for:

- suppliers;
- supplier listings;
- quotation requests;
- purchase orders;
- direct purchases;
- goods receipts;
- initial stock;
- ingredient and packaging inventory visibility;
- purchasing documents;
- indicative ingredient-price updates.

It replaces the current dense all-in-one Purchasing page. It does not introduce
sales, accounting, automatic supplier selection, or ERP-style workflows.

## Design Principles

1. **Supplier-centred purchasing.** Users first identify who sells an existing
   Soapkraft ingredient or packaging item, then use that listing throughout
   quotation, ordering, and receipt.
2. **Enter commercial lines once.** A quotation request becomes a purchase order
   without reselecting its supplier listings or quantities.
3. **Separate views, shared workflow.** Related operations remain in one Purchasing
   workspace, but suppliers, listings, quotations, orders, and receipts have
   separate, focused views.
4. **Progressive disclosure.** Index pages answer operational questions quickly;
   detail pages contain addresses, documents, prices, lots, and history.
5. **No fictitious precision.** Receiving a sealed 200 kg drum confirms the drum,
   not a weight the maker cannot practically measure.
6. **Costing uses the latest known commercial reality.** The latest applicable
   price entered for an ingredient becomes its indicative formula and Flash
   Planner price. Historical records retain their own snapshots.
7. **Traceability without paperwork friction.** Internal lot identity is mandatory;
   supplier lot identity and supporting documents are retained when available.

## Information Architecture

Production Bench keeps top-level areas for:

- Home;
- Purchasing;
- Inventory;
- Planning;
- Production.

Purchasing is a workspace with separate views:

1. **Suppliers**
2. **Supplier listings**
3. **Quotation requests**
4. **Purchase orders**
5. **Receipts**

These may share local Purchasing navigation, counters, and contextual links, but
they must not be rendered as several forms and broad tables on one page.

Inventory opens on an ingredient summary. It does not open on a global lot ledger.

## Suppliers

### Supplier Record

A supplier belongs to a workspace and contains:

- name;
- structured postal address;
- country;
- website;
- main contact name;
- main contact email;
- main contact telephone;
- notes;
- active state.

V1 deliberately has one main contact. Additional people and informal information
belong in notes. A separate contacts table is deferred until customer feedback
shows that it is necessary.

### Supplier Detail

The supplier detail page is the natural working page for that relationship. It
shows:

- identity, address, and main contact;
- active supplier listings;
- recent quotation requests;
- open purchase orders;
- recent receipts;
- notes and relevant documents.

The primary action is **Add supplier listing**. Creating a listing from this page
preselects the supplier.

## Supplier Listings

### Meaning

A supplier listing represents one commercial way to buy an existing Soapkraft
ingredient or packaging item. It never creates or replaces the underlying
ingredient or packaging catalogue item.

A supplier may have several listings for the same ingredient. For example:

- olive oil — 5 gallon pail with a supplier-declared net mass;
- olive oil — 25 kg can;
- olive oil — 200 kg drum.

### Terminology

Customer-facing copy uses:

- **Supplier listing** for the complete record;
- **Purchase format** for Drum, Pail, Bag, Carton, Bottle, or another free-text
  container description;
- **Net quantity** for the mass or count contained in one purchase format;
- **Unit of measure** written in full.

The terms **commercial pack** and unexplained **UOM** are not used.

### Required Relationship

Each listing references exactly one of:

- an existing workspace ingredient; or
- an existing workspace packaging item.

The application and database enforce this exclusive relationship.

### Listing Data

A listing stores:

- supplier;
- ingredient or packaging item;
- supplier SKU, when available;
- supplier-facing description, when useful;
- purchase format;
- net quantity per purchase format;
- original unit of measure;
- canonical mass or count per purchase format;
- price entry basis;
- latest known price amount;
- price currency;
- derived total price per purchase format;
- optional minimum-order quantity;
- optional notes and documents;
- active state.

An ingredient listing can be entered as either:

- a price per mass unit, such as `EUR 4.20/kg`; or
- a total price for one purchase format, such as `EUR 840/drum`.

Soapkraft derives and displays the other value. Packaging listings similarly
support price per item or total price for the carton or other purchase format.

Example display:

`Olive oil · 200 kg drum · EUR 4.20/kg · EUR 840 total`

Canonical calculations do not depend on the workspace display unit. Original
commercial values are retained so a US supplier listing can remain understandable
as quoted while formula costing can display the equivalent `price/lb`.

### Listing Index

The Supplier listings view supports:

- search by supplier, ingredient, packaging item, supplier SKU, or description;
- filters for supplier, material type, and active state;
- concise rows showing material, supplier, purchase format, net quantity, and
  latest price;
- a direct link to the supplier and listing detail;
- creation with a supplier preselected when launched from a supplier.

The index does not contain inline creation forms or unrelated receipt operations.

## Procurement Document Lifecycle

### One Set of Lines

A procurement document starts as a quotation request and may become a purchase
order. The user selects the supplier listings and quantities once. Conversion
reuses the same working document and lines.

The workflow is:

1. Draft quotation request
2. Quotation request issued
3. Supplier price recorded
4. Purchase order issued
5. Partially received or received
6. Cancelled when applicable

Requesting a quotation is optional. A user who already knows the price may start
with a draft purchase order.

### Quotation Request

A quotation request contains:

- supplier;
- supplier address and contact snapshot;
- requested-delivery address;
- supplier listing lines;
- number of purchase formats requested per line;
- expected date, when useful;
- notes;
- attachments.

The request may be issued without prices. Soapkraft provides:

- **Print / save PDF**
- **Copy for email**

The first action opens a dedicated print layout and uses the browser's native
print/save-as-PDF capability, consistent with Soapkraft's existing printable
documents. It does not require a new server-side PDF dependency. The email action
copies a subject and a plain-text summary. V1 does not send email.

When issued, the request receives its own reference and an immutable document
snapshot. Later conversion does not erase what was sent to the supplier.

### Recording the Supplier Response

When the supplier replies, the user enters the confirmed price per unit of measure
or the confirmed total purchase-format price on the existing lines. Soapkraft
calculates the other value.

The supplier's quotation, price confirmation, or email export may be attached to
the procurement document.

### Purchase Order

Converting to a purchase order:

- preserves the selected listings and quantities;
- preserves the issued quotation-request snapshot;
- assigns a purchase-order reference;
- snapshots the current supplier, address, listing, quantity, and price data;
- allows expected-delivery information, order notes, shipping, discount, and tax.

A purchase order may be issued:

- with confirmed prices; or
- without prices when the commercial relationship permits it.

If a price is placed on the issued purchase order, it is treated as the current
applicable price.

Soapkraft provides the same two outputs:

- **Print / save PDF**
- **Copy for email**

The purchase-order output includes supplier and delivery addresses, line
descriptions, quantities, known prices, expected date, notes, and commercial
totals.

### Order-Level Amounts

Shipping, discount, and tax are separate order-level amounts. They contribute to
purchase-order and cash-flow totals but are not automatically distributed into
ingredient unit prices in V1.

The ingredient's indicative price therefore remains the material price per mass
unit, not a hidden landed-cost allocation.

## Direct Purchases

A direct purchase skips the purchase order but never skips the supplier.

The required flow is:

`Supplier → Supplier listing → Direct receipt → Internal lot`

This supports Amazon, local shops, cash purchases, and goods bought before a formal
order was entered. The receipt captures the commercial price and optional receipt
or invoice attachment.

## Goods Receipts

### Receipt Rules

A receipt always:

- belongs to a supplier;
- uses a supplier listing;
- has a price;
- creates one or more internal lots;
- posts immutable stock movements.

The price is prefilled from the purchase order when known. If the order did not
contain a price, the user must enter the current price while receiving. There is
no **Price pending** receipt state.

Receipts may be:

- against a purchase order;
- direct, without a purchase order;
- partial against one or more purchase-order lines.

### Quantity Confirmation

For a mass ingredient, the receiver confirms the number of purchase formats
received. Stock quantity is derived from the listing's nominal mass:

`received purchase formats × canonical mass per purchase format`

Example:

`2 drums × 200 kg = 400 kg received`

The interface does not ask the maker to claim an exact measured mass for a sealed
drum. Real differences appear through consumption and later stock adjustments.

For countable packaging, the receiver may enter the actual received count so a
visible shortage can be recorded, such as 995 bottles received from an expected
1,000.

### Lot Identity

Every received line creates a unique Soapkraft internal lot code.

An ingredient receipt can also record:

- supplier batch or lot number;
- expiry or best-before date;
- receipt note;
- CoA or other batch-specific document.

The supplier lot number is a non-unique external reference. The same supplier lot
may span several deliveries; each delivery still receives its own internal lot so
quantity, date, price, and documents remain auditable.

### Availability

Received raw ingredients and packaging become available immediately. Ingredient
receipt quarantine is not part of this V1.

Quarantine and release remain relevant to completed production batches in the
later production workflow. Existing ingredient-lot quarantine controls must not
appear in this purchasing and inventory interface.

### Partial Receipts

An order line may have several receipts. The purchase order displays:

- ordered purchase formats;
- received purchase formats or count;
- remaining quantity;
- receipt history.

The order becomes **Partially received** until every active line is fulfilled or
explicitly closed. It becomes **Received** when no receivable quantity remains.

Cancelling or closing a remainder never deletes posted receipt movements.

## Indicative Price Updates

### Trigger

The ingredient's main indicative price is updated from the latest applicable
commercial price entered for that ingredient, regardless of supplier listing.

An applicable price is entered at the earliest of:

1. recording the supplier's confirmed quotation;
2. issuing a priced purchase order;
3. receiving the goods when no earlier price was recorded;
4. entering a priced direct receipt.

A draft quotation request without prices does not update costing.

The latest applicable entry wins. There is no preferred supplier listing for
costing in V1.

### Use

The updated indicative price feeds:

- Formula Bench costing;
- ingredient index price display and editing;
- Flash Planner forecasts;
- other prospective costing that currently uses the ingredient's main price.

The value is converted correctly for the active metric or US customary display
basis. Costing calculations use precise canonical values and only round for
display.

### Historical Integrity

Updating the indicative ingredient price does not alter:

- issued quotation-request snapshots;
- issued purchase-order snapshots;
- receipt prices;
- internal lot acquisition prices;
- completed production-batch cost snapshots.

Later production costing uses the actual prices of consumed lots. Indicative price
is for forecasting and current formula costing, not for rewriting history.

## Initial Stock

Initial stock is a dedicated one-time setup flow, not an ordinary purchase order.

The user must select:

- supplier;
- existing supplier listing;
- current remaining mass or count;
- required price;
- received or opening date;
- optional supplier lot, expiry, note, and documents.

The flow creates an internal lot with an opening-stock origin and posts an
immutable opening movement. It does not invent a purchase order.

Unlike receiving a sealed purchase format, initial stock accepts the user's best
current remaining mass because the container has already been used.

Additional corrections after setup use stock adjustments, not repeated opening
stock.

## Inventory Experience

### Inventory Summary

The default Inventory view has one row per ingredient or packaging item and shows:

- physical stock;
- reserved stock;
- available stock;
- incoming stock as secondary information.

The principal relationship is:

`available = physical − reserved`

Forecast requirements do not reduce available stock. Planning may happen well in
advance without reserving ingredients.

Negative physical or available quantities are allowed and displayed clearly.
Negative stock is an operational signal to reconcile real consumption, not an
error that blocks production.

### Inventory Detail

Selecting an ingredient or packaging item opens a detail view with:

- physical, reserved, available, and incoming summaries;
- internal lots and remaining quantities;
- supplier batch references;
- received dates, expiry dates, and receipt documents;
- current and historical supplier-listing prices;
- open incoming purchase-order lines;
- immutable movement history;
- adjustments.

The movement history and lot tables are details, not the first screen users must
interpret.

### Secondary Actions

Inventory exposes:

- **Initial stock setup**
- **Adjust stock**

Adjustments require a reason and post a new immutable movement. They never edit or
delete the original receipt, consumption, or opening movement.

## Documents

Purchasing and receipt records use the existing private media system.

Supported customer-facing document purposes include:

- quotation or price confirmation;
- purchase order;
- invoice;
- shop or online receipt;
- delivery note;
- Certificate of Analysis;
- safety or technical document;
- photo;
- other.

Documents attach to the most specific relevant record:

- general supplier files to the supplier;
- commercial confirmation to the procurement document;
- delivery documents to the receipt;
- batch-specific CoAs to the internal lot or receipt line.

The same private media asset may be associated with several related records without
duplicating the uploaded file.

## Domain Boundaries

Mutations remain in focused actions, not in Livewire page classes.

Required responsibilities include:

- create and update a supplier;
- create and update a supplier listing;
- issue a quotation request and snapshot its output;
- record confirmed commercial prices;
- convert a quotation request to a purchase order;
- issue and snapshot a purchase order;
- receive a purchase-order line;
- create a direct receipt;
- create initial stock;
- update the indicative ingredient price;
- reverse a receipt through compensating movements;
- post a stock adjustment.

Price normalization and unit conversion are shared domain services. Printable and
email-copy formatting read immutable document snapshots, not mutable supplier
listings.

Every customer record and lookup is workspace-scoped and policy-authorized.

## Data Model Changes

The first checkpoint already introduced suppliers, listings, purchase orders,
receipts, stock lots, and stock movements. The redesign should evolve those tables
with additive migrations rather than editing migrations that may already have run.

Expected changes include:

- structured supplier address and main-contact fields;
- clearer listing purchase-format, net-quantity, and price-basis fields;
- procurement lifecycle fields for quotation request and purchase-order issuance;
- immutable issued-document snapshots and references;
- order-level shipping, discount, and tax;
- receipt price snapshots;
- actual count support for packaging receipts;
- supplier lot and expiry fields at the received-lot level;
- price-update provenance and timestamps where needed.

Names should reflect domain meaning rather than preserve misleading first-pass
labels. Existing data in development is migrated or safely transformed so the
redesigned screens can replace the current screens without maintaining two
competing purchasing models.

## Error Handling and Invariants

- A supplier listing cannot reference an item in another workspace.
- A listing must reference exactly one ingredient or packaging item.
- A quotation request and purchase order can contain listings only from their
  selected supplier.
- Issued document snapshots are immutable.
- A receipt cannot be posted without a price.
- A receipt cannot exceed an order line's remaining quantity unless the user uses
  a deliberate over-receipt confirmation supported by the domain action.
- A mass receipt derives stock from confirmed purchase-format count and the
  snapshotted nominal mass.
- Packaging actual count must be a non-negative whole number.
- Posted movements and receipt snapshots are never edited or deleted.
- Reversal uses compensating movements and retains the original receipt.
- Supplier batch number is optional and is never substituted for the internal lot
  code.
- Negative stock does not block production or adjustment posting.
- Currency amounts use fixed-precision decimals; mass calculations use canonical
  fixed-precision quantities.
- Display rounding never changes stored values.

## Testing Strategy

### Domain and Database Tests

Tests must prove:

- workspace ownership and exclusive listing relationships;
- mass and count listing normalization;
- per-unit and per-purchase-format price derivation;
- quotation-to-purchase-order line reuse;
- immutable RFQ and PO snapshots;
- latest applicable price updates the ingredient's indicative price;
- historical document, receipt, lot, and production prices do not change;
- mass receipts derive quantity from nominal purchase-format mass;
- packaging receipts accept actual whole-count shortages;
- receipt price is mandatory;
- partial receipts calculate the remainder and status correctly;
- direct receipts require a supplier listing;
- initial stock requires a supplier listing and posts an opening movement;
- internal lot uniqueness and non-unique supplier batch references;
- negative stock and compensating adjustments remain valid;
- cross-workspace records are rejected.

### Livewire Feature Tests

Tests must cover the focused views and primary journeys:

- create a supplier and listing from supplier detail;
- find and filter supplier listings;
- create and issue a quotation request;
- record prices and convert the same lines to a purchase order;
- generate print/save-PDF and copy-for-email content from snapshots;
- issue a purchase order without repeating line entry;
- partially and fully receive an order;
- create a direct receipt;
- enter initial stock;
- view summary stock and drill into lots and movements;
- see prices in the workspace's active mass display basis;
- keep all Production Bench routes inaccessible without entitlement.

### Regression Tests

Existing tests continue to prove:

- Formula Bench costing and unit conversion;
- Basic production history;
- Production Bench cancellation and read-only history;
- immutable stock ledger behavior;
- private document access;
- Recipe/Product Bench availability without Production Bench.

## Acceptance Criteria

The redesign is acceptable when a user can:

1. create a supplier with practical contact information;
2. add several purchase formats for the same existing ingredient;
3. request a quotation without prices;
4. enter the returned price once and reuse the same lines in a purchase order;
5. print or save the RFQ and PO as PDF, or copy a pasteable email version;
6. receive sealed mass containers without pretending to weigh them;
7. record a visible packaging shortage;
8. retain both an internal lot and the supplier's batch number;
9. receive a direct purchase while still linking it to a supplier listing;
10. enter initial remaining stock without inventing a PO;
11. see physical, reserved, and available stock without interpreting a global lot
    ledger;
12. use the latest entered applicable ingredient price in formula costing and the
    Flash Planner;
13. retain every historical commercial and stock snapshot after later price or
    listing changes.

The interface succeeds when each page has one dominant job, the purchasing
lifecycle is understandable without training, and normal receipt posting requires
only information the maker can genuinely know.
