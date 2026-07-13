# Ingredient Replacement, Removal, and Sticky Save Design

## Objective

Make private-ingredient limits practical without forcing users to edit every current formula and recovery backup manually. Users can replace a private ingredient everywhere or deliberately remove it everywhere, then permanently delete it and free one plan slot.

The ingredient catalog and editor also receive two small clarity improvements: platform rows show no action, and the ingredient save action remains reachable while editing long tabs.

## Product Decisions

- Private ingredients referenced by formulas remain permanently deletable.
- The preferred workflow is **Replace everywhere and delete**.
- **Remove everywhere and delete** remains available as a more destructive fallback.
- Both operations affect current formulas, archived formulas, saved backups, and formula costing rows.
- Recovery backups are deliberately rewritten by these operations because retaining the old ingredient would prevent deletion and continue consuming a private-ingredient slot.
- The catalog describes impact in formulas only, for example `Used in 4 formulas`. It does not expose version-record counts.
- Automatic operations never mutate a formula the user cannot update.

## Ingredient Catalog Actions

### Platform ingredients

The Actions cell displays an em dash with an accessible `Not applicable` label. The current `Reference` text is removed.

### Unused private ingredients

The delete icon opens the existing simple permanent-deletion confirmation.

### Used private ingredients

The row keeps the focusable `Used in N formulas` disclosure and its formula links. A delete/manage icon remains available instead of being disabled.

Activating the icon opens a removal dialog that states only the distinct formula count and offers:

1. **Replace everywhere and delete**
2. **Remove everywhere and delete**
3. **Cancel**

The replacement path is visually primary. The remove-only path uses destructive styling and explicit consequence copy.

## Replacement Compatibility

Replacement candidates must be active ingredients accessible to the current user and must exclude the ingredient being deleted.

- `Essential Oil`, `Fragrance Oil`, and `CO2 Extract` form one interchangeable aromatic family.
- A carrier oil can replace another carrier oil. If any affected formula uses the ingredient in soap chemistry, the replacement must be eligible for soap calculation and have the required SAP profile.
- Every other category can be replaced only by the same category.
- If no compatible candidate exists, the automatic replacement option is unavailable and the user must edit the formulas manually or choose remove everywhere.

## Replace Everywhere Transaction

The operation runs in one database transaction and rechecks all conditions at execution time.

1. Confirm the ingredient is a private ingredient owned by the current user.
2. Resolve every affected recipe and recipe version, including current versions, archived recipes, and saved backups.
3. Confirm the user can update every affected recipe. If any recipe is not editable, abort without changing anything and identify those formulas.
4. Revalidate that the selected replacement is accessible, active, and compatible with every affected formula context.
5. Replace the ingredient ID in each formula row while preserving percentage, weight, phase, position, and notes.
6. Replace the ingredient in costing rows. Use the current user's remembered price for the replacement, or `null` when no remembered price exists. Never retain the deleted ingredient's price under the replacement identity.
7. Invalidate stored generated ingredient-list values and basis hashes on affected versions so future sheets and exports regenerate from the new formula composition.
8. Delete the old ingredient and its remaining dependent catalog data.

If the replacement already appears elsewhere in a formula, both rows remain. The operation does not merge percentages or reorder formula rows implicitly.

## Remove Everywhere Transaction

The dialog warns that removal can leave formulas incomplete or invalid, particularly when removing a carrier oil.

The operation uses the same ownership, permission, transaction, and stale-state checks as replacement. It then:

1. Removes every formula row and costing row referencing the ingredient across current versions, archived recipes, and saved backups.
2. Invalidates generated ingredient-list values and basis hashes on affected versions.
3. Permanently deletes the private ingredient, freeing one plan slot.

Formulas are not deleted automatically when they become empty or no longer total 100%. Existing formula validation and diagnostics communicate their incomplete state when opened.

## Failure Handling

- Permission, candidate compatibility, or stale-data failures abort the complete transaction.
- The dialog stays open and presents concise guidance.
- Non-editable formulas are listed with links so the user understands why automatic replacement or removal is unavailable.
- Server-side authorization and compatibility checks are authoritative; the modal state is never trusted by itself.
- Concurrent deletion or replacement of either ingredient produces a safe error rather than a partial update.

## Sticky Ingredient Save Action

The ingredient editor form retains one submit action for create and edit.

- The action sits in a slim, opaque sticky bar at the bottom of the viewport, outside a card.
- It remains visible across Identity, Composition, Soap Chemistry, and Compliance tabs.
- The bar uses a structural top border and the authenticated product surface color, with no glass effect or decorative elevation.
- The button is right-aligned on desktop and full-width on mobile.
- Safe-area bottom padding keeps the action usable on mobile devices.
- Validation, loading, disabled, focus, and success/error states continue to use the existing form behavior.

## Testing

Automated coverage must prove:

- Platform rows render an accessible not-applicable action instead of `Reference`.
- Used ingredients remain manageable and show only the distinct formula count.
- Replacement preserves formula row values across current, archived, and backup versions.
- Aromatic cross-category replacement is allowed.
- Carrier-oil replacement requires an eligible carrier oil when soap chemistry is affected.
- Unsupported cross-category replacement is rejected.
- Replacement costings use the replacement's remembered price or `null`.
- Replace and remove operations invalidate generated ingredient-list values.
- Remove everywhere deletes all formula and costing references and frees the private-ingredient count.
- A user cannot mutate a formula they cannot update.
- Any failure rolls back every change.
- The sticky action bar renders the responsive and accessibility class contract.

Run focused Pest tests, the broader ingredient and recipe suites, Laravel Pint, the production frontend build, and `graphify update .`.

## Out of Scope

- Merging duplicate replacement rows.
- Rebalancing formula percentages after removal.
- Suggesting a replacement automatically.
- Replacing packaging items or production-batch snapshots.
- Restoring deleted ingredients.
