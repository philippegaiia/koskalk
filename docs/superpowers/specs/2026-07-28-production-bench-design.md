# Production Bench Design

## Summary

Add an optional, customer-facing **Production Bench** to Soapkraft. It turns a published product formula into a professional but deliberately compact workflow for purchasing, stock visibility, production planning, batch execution, historical costing, release, and lot traceability.

The current production snapshot feature remains the complete Basic experience. The Production Bench is a separately entitled extension inside the same Laravel application and database. Customers can cancel it without losing the Recipe/Product Bench, retain read-only production history, and later resume with their data intact.

The design borrows the useful operational ideas from Cosmood—supplier pack listings, forecast demand, reservations, stock movements, Flash planning, and traceability—without copying production waves, equipment scheduling, large Filament forms, or ERP-style navigation.

## Product Structure

Soapkraft has three independent capabilities:

1. **Recipe/Product Bench**
   - formulate and calculate products;
   - maintain ingredients and composed ingredients;
   - manage packaging plans;
   - cost, document, publish, print, and export formulas.
2. **Production Bench**
   - purchase and receive materials;
   - understand physical, forecast, reserved, incoming, quarantined, and available stock;
   - schedule and execute production;
   - preserve actual batch costs;
   - release output and trace lots.
3. **Team capability**
   - invite additional users;
   - assign roles and permissions;
   - attribute actions to separate operators.

Production complexity is not tied to team size. A solo maker or two-person workshop may buy the Production Bench without buying broader team-management functionality.

## Goals

- Preserve the existing Basic production-history experience.
- Make Production Bench independently purchasable, cancellable, resumable, and workspace-scoped.
- Use published formula versions as the immutable handoff from formulation to production.
- Give users honest stock visibility before and during production.
- Support early production forecasting without forcing stock reservations.
- Support explicit, lot-specific hard reservations when the maker is ready.
- Preserve supplier and received-lot traceability through intermediates to finished output.
- Allow real-world negative stock caused by measurement variance and correct it through auditable adjustments.
- Track purchased ingredients by mass and packaging/finished products by count.
- Support US customary and metric purchase inputs without mixing volume into production arithmetic.
- Preserve the exact ingredient and packaging prices used by every completed batch.
- Provide a disposable Flash Planner for aggregate requirements and indicative cash-flow planning.
- Keep daily workflows fast, pleasant, and usable on tablets near the production floor.

## Non-Goals

- No sales orders, invoicing, customer management, ecommerce fulfillment, or accounting.
- No production waves.
- No equipment, mold, production-line, or labor scheduling.
- No backwards calculation of batch size from desired finished units.
- No configurable laboratory or QC-template system.
- No automatic supplier selection or supplier optimization in the Flash Planner.
- No multiple warehouses or production sites in V1.
- No automatic volume-to-mass conversion based on generic densities.
- No density catalogue that users must maintain.
- No mutable or deletable posted stock history.
- No second application, second live database, or distributed inventory service.
- No customer-facing Filament panel.
- No automatic reinterpretation of historical Basic production snapshots as inventory movements.

## Architectural Boundary

### Recipe/Product Bench Ownership

The Recipe/Product Bench remains the source of truth for:

- ingredients and composed ingredients;
- formula and recipe versions;
- product identity and finished SKU information;
- packaging components per product;
- formula calculation rules;
- live indicative ingredient prices;
- formula costing and documentation;
- product media.

### Production Bench Ownership

The Production Bench owns:

- suppliers;
- supplier listings;
- purchase orders and goods receipts;
- ingredient, intermediate, packaging, and finished-output lots;
- stock movements and stock adjustments;
- forecast requirements and hard reservations;
- operational production runs;
- quarantine and release;
- actual batch cost snapshots;
- production journal entries and production documents;
- forward and backward lot traceability.

### Handoff Contract

A production run starts from a published, accessible formula version. The run stores an immutable source reference and scaled snapshot. It may change planned and actual production quantities, selected lots, notes, and operational dates, but it never mutates the source formula.

The production basis is formula-family specific:

- soap uses initial oil mass;
- cosmetics use total formula mass.

A finished-unit target never drives formula scaling. Wastage and mold constraints make that calculation untrustworthy. The maker supplies the manufacturing basis from experience and records actual output later.

### Basic Production History Compatibility

The existing `ProductionBatch` remains the Basic immutable history record.

The premium operational record is named `ProductionRun` internally and displayed as **Production batch** to customers. Completing a premium run creates and links the existing immutable Basic production snapshot with the actual formula quantities, lot references, packaging quantities, prices, totals, and output.

This preserves completed history when Production Bench is disabled and avoids introducing a second concept for historical production truth.

The Basic snapshot schema may gain nullable production-run and output fields so it can represent either finished units or manufactured-intermediate mass. Existing Basic creation, history, and print behavior remain unchanged.

### Code Boundary

Production operations live in focused domain actions rather than Livewire components, models, or one large production service. Representative actions include:

- schedule a production run;
- reserve production materials;
- start a production run;
- receive a purchase line;
- complete or abort production;
- release an output lot;
- adjust stock;
- reverse a posted receipt.

Dependencies use constructor injection. Interfaces are reserved for real external boundaries such as accounting or scanners if those integrations are added later.

## Same-Database Decision

Production Bench uses the existing Soapkraft database.

Formula references, reservations, lot consumption, cost snapshots, the Basic production snapshot, and output creation must complete atomically. A second database would remove reliable foreign keys and require distributed transactions.

Isolation comes from:

- dedicated production and inventory tables;
- workspace ownership on every customer record;
- policies and scoped queries;
- route and navigation entitlement gates;
- domain actions that own mutations;
- no inventory logic embedded in Recipe/Product Bench.

Large stock-movement tables may later be partitioned or archived without splitting the live transactional system.

## Measurement Model

### Canonical Storage

Inventory is mass-only for ingredients and intermediates:

- canonical mass unit: gram;
- packaging and finished-product unit: integer count.

Mass quantities use precise fixed decimals, not binary floating-point arithmetic. A suitable database representation is a high-precision decimal gram quantity. Count-based records enforce whole numbers.

### Supported Entry and Display Units

Users may enter:

- grams;
- kilograms;
- ounces;
- pounds.

Conversions are exact:

- `1 kg = 1,000 g`;
- `1 lb = 453.59237 g`;
- `1 oz = 28.349523125 g`.

Every transaction stores:

- canonical quantity;
- original entered quantity;
- original entered unit.

Calculations use canonical grams. Rounding occurs only for display.

### Workspace and Formula Preferences

Each workspace has a preferred mass display system:

- metric automatically displays sensible g/kg values;
- US customary automatically displays sensible oz/lb values.

The workspace preference is a default, not a permanent lock. Users may enter a purchase in kg in a US workspace or in lb in a metric workspace.

Each formula version retains its preferred display unit. Changing the unit converts every absolute mass value; it never relabels unchanged numbers. Percentages do not change.

Correcting the current Formula Bench unit behavior is a prerequisite for Production Bench because the current UI treats the selected unit as formula basis metadata rather than performing a complete display conversion.

### Commercial Volume Descriptions

US suppliers sometimes sell liquids as gallon buckets or drums. Production inventory still remains mass-based.

A supplier listing may therefore contain:

- commercial description: `5-gallon pail`;
- supplier-declared net mass: `18.4 kg`.

The commercial volume is purchasing metadata. The declared or measured net mass drives stock.

Soapkraft does not generically convert gallons to kilograms. When a supplier does not provide net mass, the maker must enter the measured mass before receiving the material into stock.

Density may be derived for reference when both volume and mass exist, but it is not a required user field or inventory conversion rule.

### Finished Product Label Quantity

A product label such as `465 mL` is product and label metadata. Finished stock remains an integer number of units. Label quantity does not introduce volume-based inventory.

## Supplier and Purchasing Model

### Suppliers

A supplier is a workspace-owned commercial party. Supplier details stay intentionally small in V1: identity, contact information, notes, currency/defaults, and active state.

### Supplier Listings

A supplier listing directly references an existing Soapkraft ingredient or packaging item. No generic inventory-item wrapper sits between the listing and the catalog record.

The same ingredient may have multiple listings from the same supplier and from different suppliers. Examples include:

- olive oil, 5-gallon pail;
- olive oil, 35 lb pail;
- olive oil, 400 lb drum;
- olive oil, 25 kg can.

A listing stores:

- supplier;
- ingredient or packaging item;
- supplier SKU;
- supplier-facing name;
- commercial pack description;
- package/container type when useful;
- expected canonical mass or count per purchased pack;
- optional original commercial quantity and unit;
- price per purchased pack;
- currency;
- optional minimum order quantity;
- active state;
- optional documents and notes.

The listing must directly reference exactly one ingredient or packaging item. Database constraints should preserve that invariant without a weak, unvalidated generic relationship.

### Purchase Orders

A purchase order supports:

- draft;
- ordered;
- partially received;
- received;
- cancelled.

An order line selects a supplier listing and records the number of supplier packs ordered.

When a listing is selected, the order line snapshots:

- supplier listing identity;
- ingredient or packaging identity;
- supplier SKU and description;
- pack content and canonical quantity;
- original commercial unit;
- unit price and currency.

Later listing edits do not alter historical orders.

Expected line quantity is:

`number of purchased packs × canonical mass/count per pack`

Expected line cost is:

`number of purchased packs × price per pack`

### Goods Receipts

Receipts may be partial. A purchase line can generate one or more receipts and lots when deliveries are split or supplier lots differ.

The receiver confirms:

- number of packs received;
- actual received canonical mass or count;
- supplier batch/lot number for ingredients when provided;
- optional expiry or best-before date;
- receipt date;
- optional note and documents.

Every received ingredient lot gets a unique, system-generated Soapkraft internal lot code. The supplier batch/lot number is stored separately as a non-unique external reference.

Each delivery creates its own internal receipt lot, even when several deliveries carry the same supplier batch number. This preserves receipt date, received quantity, price, documents, and stock history per delivery while allowing Soapkraft to group and trace all internal lots that came from the same supplier batch.

If the supplier provides no batch number, the internal lot code still gives the receipt a complete operational identity. The missing supplier reference remains explicit rather than being replaced with invented supplier data.

The actual receipt quantity may differ from the listing expectation. Stock uses the actual quantity.

### Purchasing Documents

The existing private media library supports typed attachments:

- supplier confirmation;
- invoice;
- receipt;
- delivery note;
- photo;
- other purchasing document.

A CoA, SDS, specification, or certificate for a received material attaches to the specific received lot. Batch-specific certificates do not attach only to the supplier or generic ingredient.

When the same supplier batch arrives in several deliveries, the same private media asset may be associated with each related internal lot without duplicating the uploaded file.

## Stock Model

### Lots

Stock is lot-based. A lot identifies:

- the workspace;
- ingredient, packaging item, manufactured intermediate, or finished product;
- origin type and source record;
- unique Soapkraft internal lot code;
- nullable, non-unique supplier batch/lot number;
- manufactured output lot number when applicable;
- canonical unit kind;
- receipt or manufacture date;
- optional expiry/best-before date;
- quarantine/release status;
- optional `available_from` date;
- historical unit cost;
- related documents.

Purchased ingredients create received lots. Manufactured intermediates and finished products create output lots.

### Manufactured Intermediates

A manufactured intermediate is an in-house-only existing ingredient linked to its producing formula. It is not supplier-purchased. An externally purchased compound remains the existing composed-ingredient concept.

A production run may produce exactly one output:

- one finished product/SKU in units; or
- one manufactured intermediate in grams.

Intermediate lots can be reserved and consumed by later formulas. Their cost per gram is carried from the actual producing batch, preserving downstream historical cost.

### Immutable Stock Movements

Every physical quantity change creates an immutable movement. Movement types include:

- opening balance;
- purchase receipt;
- production consumption;
- production output;
- shipment/sale;
- sample;
- damaged;
- internal use;
- stock-count adjustment;
- receipt reversal;
- production correction.

Posted movements are not edited or deleted. Corrections create compensating movements linked to the original event.

### Reservations

Reservations are separate from physical movements. They commit released lot quantities to one production run without changing physical stock.

Reservations are:

- lot-specific;
- hard commitments;
- releasable on cancellation or plan changes;
- converted into actual consumption during completion.

FEFO is the default lot suggestion when expiry data exists. Users may select different lots.

### Stock Truth

The UI displays distinct quantities:

- **Physical**: all physically present stock, including quarantined and reserved stock.
- **Quarantined**: physical stock not yet released.
- **Reserved**: active hard reservations against released lots.
- **Available**: released physical stock minus active reservations.
- **Incoming**: ordered quantity not yet received.
- **Forecast demand**: requirements from scheduled runs that have not been reserved.

Forecast demand never reduces available stock.

### Negative Stock and Reconciliation

Hard reservation cannot exceed released available stock.

Actual production consumption may exceed its planned reservation. This is necessary because oils and liquids taken from bottles, pails, and drums rarely reconcile perfectly with theoretical balances. The affected lot may become negative.

Negative balances are prominent and actionable. The maker later reconciles the physical count through an adjustment movement. Soapkraft never rewrites the consumption that actually occurred.

## Production Run Model

### Statuses

The operational lifecycle is:

`Draft → Scheduled → Reserved → In production → Completed`

Alternative terminal paths are:

- `Draft/Scheduled/Reserved → Cancelled`;
- `In production → Aborted` through explicit reconciliation.

Output release is a lot state rather than a second completed-production state.

### Draft

A draft contains an incomplete operational idea and has no stock effect.

### Scheduled

A scheduled run has:

- published formula/product;
- planned production date;
- production basis quantity;
- calculated material requirements;
- optional expected finished units when packaging forecasting is useful;
- notes.

Scheduling creates forecast demand only. It is allowed even when stock is short.

### Reserved

`Reserve stock` is an explicit action. It:

- selects or proposes ingredient and packaging lots;
- creates lot-specific reservations;
- blocks if released available stock is insufficient;
- reports shortages clearly.

Reservation is not required when a run is first scheduled. Before a run starts, its lots must be selected and sufficient planned quantities must be reserved. Starting an unreserved run performs the same allocation and reservation confirmation.

### In Production

Starting production freezes the operational plan used at the bench. The operator may record actual quantities, replace selected lots when permitted, add journal entries, and upload documents.

### Completion

Completion requires:

- actual ingredient quantities by lot;
- actual packaging quantities used when applicable;
- actual output quantity;
- manufacture/completion date;
- one output lot identifier;
- required output-specific information.

Finished products require actual integer units. Manufactured intermediates require actual output mass.

Completion atomically:

1. validates the current run state;
2. locks affected lots and reservations;
3. posts actual material and packaging consumption;
4. allows actual consumption to exceed reservations and create negative stock;
5. releases unused reservations;
6. snapshots actual historical costs;
7. creates the linked Basic `ProductionBatch`;
8. creates one output lot;
9. posts the production-output movement;
10. closes the production run.

Either every step succeeds or none succeeds.

### Cancellation and Abort

Cancelling a draft or scheduled run has no stock effect.

Cancelling a reserved run releases all active reservations.

A started run cannot be deleted or casually cancelled. Aborting it requires the operator to record material consumed, returned, or lost. The abort action posts the appropriate movements and releases remaining reservations.

### No Mold Model

V1 does not store molds, mold counts, mold capacity, equipment availability, or batch presets.

The maker knows the initial oil quantity or total formula quantity from experience. A `Repeat production` action may prefill the previous run's basis. Mold configuration can be described in the production journal.

## Output Quarantine and Release

Completion creates the output lot as physically present.

A formula/product may provide a default curing or release delay. The output lot stores:

- quarantine status;
- optional `available_from` date;
- release status and date;
- released by;
- optional release note and documents.

Quarantined output is not available for later production or finished-goods issue.

The release action is intentionally lightweight. V1 does not implement configurable QC templates or a laboratory workflow. Makers may record pH, observations, deviations, or other checks in the production journal and attachments.

## Production Journal

Each production run includes a generous journal area with:

- rich-text entries;
- timestamp;
- author;
- optional private media attachments;
- chronological presentation.

The journal supports process observations, temperatures, pH, troubleshooting, deviations, curing notes, mold setup, photographs, and any workshop-specific information without forcing every maker through a large structured form.

Core inventory, lot, quantity, and release facts remain structured and auditable.

## Finished-Goods Issue

V1 does not manage customer or sales orders.

Finished units may leave stock through simple issue movements:

- shipment/sale;
- sample;
- damaged;
- internal use;
- stock-count adjustment.

Each movement records quantity, date, reason, actor, optional reference, and note.

## Costing

### Indicative Formula Price

Soapkraft's existing main ingredient price remains the current indicative price per kilogram. Updating live costing may update this price memory.

This value supports current formula estimates and the Flash Planner. It is deliberately indicative and mutable.

### Received-Lot Cost

A received ingredient or packaging lot stores the purchase unit cost effective at receipt. Listing price changes never rewrite the lot.

V1 actual production costing includes:

- actual ingredient consumption at each consumed lot's historical unit cost;
- actual packaging consumption at each consumed lot's historical unit cost.

Labor scheduling, energy modelling, tax accounting, overhead allocation, and automatic freight allocation are not part of V1 batch costing. If a maker wants freight reflected in material cost, the received lot's entered unit cost must already include it.

### Actual Batch Cost

Completion snapshots:

- actual quantity per consumed lot;
- unit cost per consumed lot;
- line cost;
- ingredient total;
- packaging total;
- total material cost;
- actual finished units or intermediate output mass;
- cost per finished unit or cost per gram of intermediate.

Historical batch costs are immutable. Later catalog, listing, or lot-price changes do not alter completed production.

## Flash Planner

### Purpose

The Flash Planner is a high-value, disposable what-if sheet for answering:

- what materials would several possible runs require;
- what is currently available;
- what remains to buy;
- what the total material value is;
- how much cash might be needed to cover shortages.

It does not create reservations or persistent production records.

### Inputs

Users may add any number of hypothetical lines. Each line contains:

- published product/formula;
- initial oil mass for soap or total formula mass for cosmetics;
- optional expected finished units when packaging forecasting is wanted;
- optional repetition count.

The Flash Planner never derives batch mass from desired finished units.

### Results

The planner aggregates:

- ingredient requirements;
- optional packaging requirements;
- current physical and available stock;
- incoming quantity;
- forecast quantity;
- shortage quantity;
- missing indicative prices;
- total material value;
- estimated cash needed for shortages.

Example:

- required olive oil: `80 kg`;
- available: `25 kg`;
- shortage: `55 kg`;
- indicative price: `€4.20/kg`;
- total material value: `€336`;
- estimated purchasing cash: `€231`.

The estimates use Soapkraft's current indicative ingredient and packaging prices. They do not select a supplier, optimize pack sizes, or create purchase orders.

### Persistence

The sheet is temporary browser/session state. It supports reset, print, and export.

An explicit future action may convert a hypothetical line to a scheduled production run, but automatic conversion is not required for V1.

## Traceability

Traceability works in both directions.

Backward:

`Finished output lot → production run → intermediate/raw lots → goods receipts → supplier`

Forward:

`Supplier ingredient lot → consuming production runs → intermediate lots → later production runs → finished lots`

Users can search by:

- production batch/run number;
- Soapkraft internal ingredient, intermediate, or finished lot;
- supplier batch/lot number;
- ingredient;
- product;
- purchase order or receipt reference.

Traceability results show explicit missing provenance for opening balances rather than implying complete history.

## User Experience

### Delivery Technology

Production Bench uses full-page Livewire components and Blade components inside the existing Soapkraft application shell.

It is not a separate Filament panel. Filament remains available for internal Soapkraft administration and support tooling only.

Business rules live in domain actions. Livewire pages orchestrate those actions and render state.

### Primary Areas

Production Bench navigation contains:

1. **Home**
2. **Purchasing**
3. **Inventory**
4. **Production**
5. **Traceability**
6. **Flash Planner**

The number of visible top-level destinations may be reduced responsively, but the conceptual areas remain distinct.

### Home

The home screen answers:

- what is scheduled;
- what can start;
- what is short;
- what is arriving;
- what is waiting for release;
- what needs attention today.

It does not lead with decorative KPI cards.

### Production Run Workspace

A production page is one coherent workspace:

1. status and one primary next action;
2. formula, manufacturing basis, and scheduled date;
3. material requirements, forecast, reservations, actuals, and lots;
4. packaging requirements;
5. finished/intermediate output and release;
6. journal and documents;
7. quiet chronological activity history.

### Interaction Principles

- Use progressive disclosure.
- Use workshop language rather than ERP terminology.
- Present one primary lifecycle action at a time.
- Allow inline editing for reversible draft information.
- Use explicit confirmation for posted stock changes.
- Avoid long modal chains and giant tables.
- Show lot availability and expiry during selection.
- Keep Physical, Reserved, Available, Incoming, Quarantined, and Forecast visually distinct.
- Remember common values and provide `Repeat production`.
- Use tablet-friendly target sizes and layouts.
- Use semantic color sparingly for shortage, quarantine, readiness, and completion.
- Let pleasure come from clarity, momentum, and confidence rather than decorative animation.

A maker should be able to schedule a familiar batch in under one minute and identify a stock problem without opening several pages.

## Entitlement, Cancellation, and Retention

### Entitlement

Production Bench is a workspace-level add-on entitlement. It is independent from team seats and the highest subscription plan.

While active, the entitlement enables:

- navigation;
- Livewire routes;
- production mutations;
- purchasing, inventory, traceability, and Flash workflows.

### Cancellation

When the add-on is cancelled:

- Recipe/Product Bench continues unchanged;
- Basic production history remains available;
- Production Bench records are not deleted;
- Production Bench becomes read-only;
- exports remain available;
- new purchases, receipts, runs, reservations, and stock movements are blocked;
- re-enabling restores full access with all data intact.

### Retention

The first 48 months after cancellation are the hot-resume period with immediate read-only access and instant resumption.

After 48 months, records may move to cheaper compliance archive storage, but they are not automatically erased.

The conservative default compliance baseline is at least 10 years after the last relevant batch was placed on the market. Because Soapkraft may not know the legal placement date in every jurisdiction, permanent deletion requires a retention-policy check, customer warning, export opportunity, and legal-hold support.

V1 should not implement automatic final deletion. It should store cancellation and archive-eligibility metadata so archival can be added safely before any customer reaches the threshold.

Soapkraft must not present this retention behavior as legal advice. Customers remain responsible for their jurisdiction and may configure or request longer retention.

The conservative baseline is informed by [Article 11 of the EU Cosmetics Regulation](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=consolidation%3A2009R1223%2F20190813_0210010). US requirements differ by record type; for example, [FDA guidance describes six-year adverse-event retention, or three years for qualifying small businesses](https://www.fda.gov/cosmetics/cosmetics-news-events/fda-issues-draft-guidance-industry-fda-records-access-authority-cosmetics-products).

## Activation and Existing Data

Enabling Production Bench launches a short activation flow:

1. confirm workspace currency;
2. confirm preferred mass display;
3. reuse accessible ingredients, composed ingredients, packaging, products, and published formulas;
4. create suppliers and listings only as needed;
5. enter opening ingredient lots and packaging balances;
6. identify products ready for production.

Existing Basic production snapshots are not converted into purchases, lots, or stock movements.

Opening stock creates explicit `Opening balance` movements. Where historical lot provenance is unavailable, the user may create a clearly marked opening lot with incomplete provenance. Traceability displays that limitation.

## Concurrency and Reliability

All inventory mutations use database transactions and row locks on the affected production run, lots, reservations, and relevant stock projections.

Reliability requirements:

- no read-then-write stock mutation without locking;
- idempotent receipt, completion, release, and reversal actions;
- duplicate action retries cannot create duplicate stock;
- completion is all-or-nothing;
- durable stock and production work is synchronous or queued with retry guarantees, never disposable deferred work;
- independent reporting reads may run concurrently, but dependent stock mutations never do;
- operational lists have explicit ordering;
- workspace authorization applies to every record and action;
- private documents cannot cross workspace boundaries.

The UI should explain recoverable conflicts clearly, reload current state, and preserve unposted notes where practical.

## Error Handling

- An inaccessible or unpublished formula cannot start a production run.
- A scheduled run may show shortages without blocking.
- Reservation reports exact shortages and does not partially reserve silently.
- Starting an unreserved run requires lot allocation and successful reservation.
- Missing indicative prices do not block Flash planning; they are clearly identified.
- Missing actual lot costs are visible and prevent a false claim of complete actual costing.
- Invalid or unsupported mass units are rejected at the boundary.
- Count-based packaging and finished output reject fractional units.
- Receipt quantities must be positive.
- Posted receipts and completed runs cannot be edited directly.
- A failed completion leaves the run, reservations, movements, output, and Basic snapshot unchanged.
- Negative stock is allowed only through explicit actual consumption or adjustment actions and remains visible until reconciled.
- File uploads validate type, extension, size, and workspace ownership.

## Data Model Direction

Exact migrations belong in the implementation plan, but the bounded model requires records equivalent to:

- `suppliers`;
- `supplier_listings`;
- `purchase_orders`;
- `purchase_order_lines`;
- `goods_receipts`;
- `goods_receipt_lines`;
- `stock_lots`;
- `stock_movements`;
- `stock_reservations`;
- `production_runs`;
- `production_run_materials`;
- `production_run_packaging`;
- `production_run_cost_lines`;
- `production_journal_entries`;
- typed private media usages for orders, receipts, lots, runs, and releases.

Important relational rules:

- every customer record is workspace-owned;
- a supplier listing directly references exactly one existing ingredient or packaging item;
- an order line snapshots listing economics and pack quantity;
- a receipt line references its order line when applicable;
- a stock lot has exactly one stock subject, one origin, and one unique internal lot code;
- a supplier batch/lot number may be shared by several receipt lots;
- movements reference a lot and source document;
- reservations reference a released lot and production run;
- a production run references one published formula version;
- a completed run references one Basic `ProductionBatch`;
- a run has exactly one output lot;
- every received ingredient lot has a unique internal lot code, while its supplier batch/lot reference is nullable and non-unique.

Stock positions are derived from movements and reservations. A projection/cache table is optional for performance, but it is never an independent source of truth.

## Testing Strategy

### Unit and Domain Tests

- exact g/kg/oz/lb conversions;
- display conversion never changes canonical quantity;
- formula unit changes convert rather than relabel;
- supplier-pack multiplication;
- order-line listing snapshots;
- indicative Flash calculations;
- total material value versus shortage cash need;
- lot-cost and intermediate-cost propagation;
- stock-position calculations.

### Purchasing Tests

- create several listings for one ingredient and supplier;
- order multiple supplier packs;
- partial receipts;
- several distinct supplier batches for one order line;
- several deliveries with distinct internal lots and the same supplier batch number;
- actual received mass differs from expected mass;
- receipt documents and lot-specific CoAs remain private;
- listing edits do not change posted orders or received lots;
- receipt retry is idempotent;
- reversal creates compensating movements.

### Inventory and Reservation Tests

- forecast demand does not reduce available stock;
- reservation reduces available stock but not physical stock;
- hard over-reservation is blocked;
- two concurrent reservations cannot consume the same availability;
- quarantined lots cannot be reserved;
- actual consumption may exceed reservation and create negative stock;
- adjustment reconciles negative stock without rewriting consumption;
- cancellation releases reservations.

### Production Tests

- soap scales from initial oil mass;
- cosmetics scale from total formula mass;
- finished units never drive formula scaling;
- start requires lot allocation;
- completion is atomic;
- completed run creates one output lot and one Basic snapshot;
- finished output requires integer units;
- intermediate output requires mass;
- intermediate cost propagates to downstream production;
- abort records consumed, returned, and lost quantities;
- quarantine and release affect availability correctly;
- historical costs remain unchanged after catalog/listing price updates.

### Traceability Tests

- finished lot traces backward to every consumed supplier lot;
- supplier lot traces forward through intermediate and finished outputs;
- opening provenance gaps are explicit;
- workspace boundaries prevent cross-customer traceability.

### Entitlement Tests

- Basic users retain current production snapshot workflow;
- Production Bench routes and actions require entitlement;
- cancellation makes premium records read-only without affecting Recipe/Product Bench;
- exports remain available;
- resumption restores write access;
- completed Basic history remains visible after cancellation.

### Livewire and Browser Tests

- activation and opening-balance flow;
- create and receive a purchase order;
- schedule without reserving;
- reserve later;
- run start and completion;
- output release;
- stock adjustment;
- Flash Planner with multiple hypothetical runs;
- tablet-sized critical production workflow;
- accessible keyboard and focus behavior for primary actions and lot selectors.

## Rollout Principles

Implementation should be incremental while preserving the bounded design:

1. correct mass conversion in Recipe/Product Bench;
2. establish suppliers, listings, lots, movements, opening balances, and stock truth;
3. add purchase orders and partial receipts;
4. add scheduled runs, forecasts, reservations, and execution;
5. add output quarantine/release and actual costing;
6. add traceability, documents, journal, and Flash Planner;
7. enable the add-on for selected pilot customers and gather workflow feedback.

Schema choices must allow later improvements, but V1 should not prebuild production waves, equipment scheduling, configurable QC, or supplier optimization.

## Success Criteria

- A familiar production run can be scheduled in under one minute.
- A maker can plan months ahead without accidentally reserving stock.
- Available stock never silently includes hard-reserved or quarantined quantities.
- Every completed output has one production run, one immutable cost snapshot, and traceable input lots.
- A real consumption variance can create negative stock and be reconciled without rewriting history.
- The Flash Planner can aggregate several hypothetical runs and estimate total material value and shortage cash needs.
- Cancelling Production Bench does not break Recipe/Product Bench or delete production records.
- A customer can resume Production Bench with its operational state intact.
- The daily interface feels like a focused workshop tool rather than an administrative ERP.

## Open Decisions

No product decisions remain open for V1. Detailed table columns, indexes, authorization roles, Livewire component boundaries, and delivery sequencing belong in the implementation plan.
