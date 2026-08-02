# Production Bench Catalogue Foundation Redesign

## Scope

This design corrects the catalogue and purchasing boundaries before further
Production Bench implementation. It supersedes the catalogue-ownership and Basic
production-snapshot integration sections of the earlier Production Bench designs.

The application has no customer production data that must be preserved. Demo
recipes, private ingredients, packaging items, and related development records may
be removed while applying this redesign. The implementation should produce the
clean target structure rather than retain compatibility fields or parallel legacy
paths.

## Catalogue Boundary

The Recipe/Product Bench remains the only place where catalogue records are
created and edited.

- `ingredients` stores generic ingredient identity and technical data.
- `packaging_items` stores reusable workspace packaging components.
- recipes and recipe versions reference those catalogue records.
- the Production Bench never creates a second ingredient, packaging, or generic
  material catalogue.

The Production Bench starts with existing catalogue records. It adds commercial
and operational information through suppliers, supplier listings, procurement
documents, receipts, stock lots, stock movements, reservations, and production
runs.

When a required catalogue item is missing during supplier-listing creation, the
user follows a contextual link to the central Ingredients or Packaging page. A
validated return destination brings the user back to the listing form and
preselects the newly created item. Catalogue creation is not duplicated inline in
the Production Bench.

## No Generic Materials Catalogue

Ingredients and packaging remain separate domain entities because their formula,
technical, measurement, and lifecycle data differ. The design does not introduce
a `materials` table that duplicates their identity.

Shared purchasing is provided by shared transactional tables. A purchase order
has one supplier and may contain any mixture of ingredient and packaging supplier
listings. Each line retains direct foreign keys to exactly one catalogue subject.

## Ingredient Catalogue

An ingredient describes a generic material such as Olive oil. It is not tied to a
supplier until a supplier listing exists.

Remove these fields from `ingredients` and every Ingredient authoring, export,
factory, test, and presentation path:

- `supplier_name`;
- `supplier_reference`;
- `is_organic`.

The Ingredient catalogue retains its existing platform/workspace ownership,
technical composition, regulatory data, category, media, and active state. It
also has a nullable plain-text `notes` field for information about the generic
material. Supplier, purchasing, organic, certification, and lot information must
not be stored in this note.

## Packaging Catalogue

Replace the user-owned packaging catalogue with a workspace-owned catalogue:

### `packaging_items`

- `id`;
- `public_id`;
- `workspace_id`;
- nullable `created_by_user_id` for attribution;
- `name`;
- `category`;
- nullable `notes` plain text;
- active state;
- existing image/media relationship;
- timestamps.

The old `user_packaging_items` name and `UserPackagingItem` model are replaced by
`packaging_items` and `PackagingItem`. Foreign keys named
`user_packaging_item_id` are renamed to `packaging_item_id` throughout formulas,
costings, supplier listings, purchasing, stock, and Basic production snapshots.

Packaging items are archived by changing their active state once they have been
used. They are not deleted when referenced by formulas, listings, stock, issued
documents, receipts, or production history.

### Packaging categories

`packaging_items.category` is a normal bounded string column cast to a PHP backed
enum. It is not a PostgreSQL enum and does not use a categories table.

The V1 values are:

- `box`;
- `jar`;
- `bottle`;
- `lid`;
- `cap`;
- `label`;
- `tube`;
- `pump`;
- `shipping`;
- `other`.

The customer-facing labels use packaging-specific translation keys under
`packaging.categories.*`. Translations are contextual and do not reuse generic
dictionary labels. Enum values remain language-neutral.

A categories table is deferred unless workspaces later need to create, rename,
reorder, or deactivate their own categories.

## Supplier Listings

A supplier listing represents one commercial way to buy one existing catalogue
item. It never creates or replaces that item.

Each `supplier_listings` row references exactly one of:

- `ingredient_id`; or
- `packaging_item_id`.

The application and a database check constraint enforce the exclusive
relationship. A supplier may have several listings for the same item, and the same
item may have listings from several suppliers.

Supplier-specific Ingredient fields belong here:

- supplier SKU/reference;
- supplier-facing item name;
- `organic_status` for Ingredient listings;
- purchase format;
- net quantity and original unit of measure;
- canonical quantity per purchase format;
- price basis, amount, currency, and recorded time;
- minimum order, active state, documents, and notes.

`organic_status` is a bounded string cast to a PHP enum with the values `unknown`,
`conventional`, and `organic`. This prevents missing information from being
silently presented as conventional. Packaging listings do not use this field.
Organic status and relevant certification are snapshotted into later commercial
and receipt records.

## Mixed Purchase Orders

A purchase order belongs to one supplier. Its single line table may contain both
Ingredient and Packaging listings from that supplier.

Each line snapshots:

- supplier listing identity;
- exactly one `ingredient_id` or `packaging_item_id`;
- catalogue item name and type;
- supplier SKU and supplier-facing description;
- organic status when applicable;
- purchase format and canonical content;
- ordered purchase-format count;
- entered and calculated price values;
- currency and expected total.

Database constraints enforce exactly one catalogue subject per line. Issued lines
are immutable. Later changes to the catalogue item or supplier listing do not
rewrite the issued document.

Quotation request and purchase order are stages of one procurement record with
one reusable set of lines. Issuing either document creates an immutable document
snapshot. A user selects the listings once and does not recreate lines during
conversion.

## Current Indicative Prices

Prospective Formula Bench costing, catalogue displays, and Flash planning use one
workspace-scoped current-price projection rather than supplier data stored on an
Ingredient.

### `current_material_prices`

- `id`;
- `workspace_id`;
- nullable `ingredient_id`;
- nullable `packaging_item_id`;
- `price_per_canonical_unit`;
- `currency`;
- `recorded_at`;
- nullable `source_type` and `source_id`;
- nullable `created_by_user_id`;
- timestamps.

There is no `unit_kind` column because the subject determines the canonical unit:

- Ingredient price is stored per canonical gram;
- Packaging price is stored per item.

An exactly-one database constraint requires one catalogue subject. Partial unique
indexes enforce one current price for each workspace and Ingredient, and one
current price for each workspace and Packaging item.

An applicable confirmed price updates this projection at the earliest of:

1. confirmed supplier quotation;
2. issued priced purchase order;
3. priced direct receipt;
4. receipt when no earlier confirmed price exists;
5. manual Formula Bench costing.

The most recently recorded applicable price wins. `source_type` and `source_id`
record its provenance. Historical prices remain immutable in issued procurement
snapshots, receipt records, stock lots, and completed Production Runs.

## Receipts and Stock

Receipts always retain a supplier and supplier listing. Direct purchases skip a
formal purchase order but do not skip those relationships.

Opening stock also requires an existing supplier listing, a current remaining
quantity, a price, and an opening date. It does not accept a bare Ingredient or
Packaging item.

A posted receipt or opening-stock entry creates an internal lot and immutable
stock movement. The lot references exactly one Ingredient or Packaging item and
snapshots the organic status, acquisition price, supplier batch information, and
applicable documents.

Raw Ingredient and Packaging receipts become available immediately in V1.
Ingredient receipt quarantine is not exposed. Quarantine and release apply to
manufactured production output.

All catalogue selection and mutation actions revalidate workspace access on the
server. UI filtering is not treated as authorization.

## Professional Production Records

The existing `production_batches` feature remains an independent Basic history
feature. It is not the storage, compatibility fallback, or completion snapshot for
the professional Production Bench.

The professional module uses self-contained `production_runs` and supporting
tables for:

- immutable source formula/version snapshot;
- planning and requirements;
- lot-specific reservations;
- actual Ingredient and Packaging lot consumption;
- historical consumed-lot costs;
- actual output and output lot;
- journal entries and documents;
- lifecycle and operator attribution.

Completing a Production Run posts inventory movements and preserves its own
history. It does not create or link a Basic `production_batches` record.

Disabling the Production Bench leaves professional Production Run history
read-only. Resuming restores operational access to the same records.

## Data Cleanup and Migration Direction

The target model is preferred over legacy compatibility because the application
has no customer catalogue or production data to retain.

Implementation may remove the existing demo recipes, private ingredients,
packaging items, and dependent development records. It should then:

- remove obsolete Ingredient commercial fields;
- rename the packaging table, model, and foreign keys cleanly;
- move packaging ownership to the workspace;
- replace old user-scoped indicative price storage with the approved
  workspace-scoped projection;
- correct opening-stock and receipt relationships;
- keep the Basic production snapshot feature independent.

The deployment still uses explicit, reviewable Laravel migrations. The absence of
legacy data removes the need for compatibility behavior; it does not justify
untracked manual production-schema changes.

## Integrity and Deletion Rules

- Catalogue, supplier, listing, procurement, receipt, lot, and production records
  are workspace-scoped.
- A listing, procurement line, current price, or stock lot references exactly one
  supported catalogue subject.
- A purchase order contains only listings belonging to its selected supplier.
- One purchase order may mix Ingredient and Packaging lines.
- Issued documents, receipts, stock movements, and completed Production Runs are
  immutable.
- Used catalogue items, suppliers, and supplier listings are archived rather than
  deleted.
- Display conversion and rounding never alter canonical stored quantities or
  prices.
- Organic claims are commercial and lot facts, not generic Ingredient facts.

## Testing

Tests must prove:

- Ingredient authoring, export, and duplication no longer expose supplier or
  organic fields;
- Packaging items are workspace-owned, categorized, translatable, and archived;
- Packaging category values and contextual translation keys are complete;
- cross-workspace catalogue records are rejected by every purchasing and stock
  action;
- one supplier can have several Ingredient and Packaging listings;
- one purchase order can mix those listing types;
- exclusive-subject database constraints reject zero-subject and two-subject
  rows;
- applicable commercial prices update the correct workspace price without
  rewriting historical snapshots;
- direct receipt and opening stock require a supplier listing and price;
- raw purchased stock is available without an Ingredient quarantine step;
- professional Production Runs are independent from Basic production snapshots;
- disabled Production Bench workspaces retain read-only professional history.

## Acceptance Criteria

The foundation is ready when a user can create generic Ingredients and Packaging
items once in the Recipe/Product Bench, attach them to products, create several
supplier listings for each item, place one mixed order to a wholesaler, receive
traceable stock, and see current prospective prices without duplicating catalogue
identity or modifying historical commercial and production records.
