# Deployment readiness after catalogue curation

Use this checklist only after the ingredient catalogue, sellable products, packaging, supplier listings, and interface translations have been reviewed in the application. It records the release work that follows curation; it does not authorize merging a branch or activating a locale.

## Before the release

- Confirm that approved catalogue and sales-data changes are committed and that the target branch has passed its focused tests and review.
- Review the production workflow with the curated data: a manufactured ingredient can be released into ingredient stock, and a finished product can be completed and released.
- Review the non-English values in `database/seeders/data/interface-translations.json` alongside their English keys. Keep the repository catalogue authoritative during the current owner-only pre-launch phase.
- Make sure Forge has the production backup configuration described in [storage-and-backups.md](./storage-and-backups.md). Do not place credentials in the repository or in this checklist.

## Release sequence

From the deployed release directory, make a recoverable backup before applying migrations, then run the migration and translation steps in this order:

```shell
php artisan backup:database
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\SupportedLocaleSeeder' --force
php artisan translations:sync
php artisan translations:catalogue:import --mode=authoritative --force --no-interaction
```

Do not run the full `DatabaseSeeder` for this release, and do not use `translations:sync --prune`. The commands above add missing owned keys and import only the reviewed application-interface catalogue; they do not alter curated ingredients, products, supplier listings, media, or other business data.

When production editorial translation changes become authoritative, replace the final command with the preserve-existing mode documented in [localization.md](./localization.md). Do not activate a locale until its required interface and platform content are complete.

## Standing Forge deploy script

Since the 2026-08-21 release (IFRA amendments and product taxonomy rollout), the Forge deploy script keeps only the locale seeder and translation commands active. The reference-data seeders below shipped that release's taxonomy data once; IFRA categories, product families/areas/categories/types, and their mappings are now settled production data and must not re-run on every deploy — each run overwrites any admin-panel edits to those tables. They stay commented in the Forge script for reuse before launch: uncomment them only for a deploy that ships taxonomy, IFRA, or regulatory-regime changes, keep the exact order below (dependencies), then re-comment afterwards.

```shell
# Reference-data seeders (one-time payload of the 2026-08-21 release; settled since)
$FORGE_PHP artisan db:seed --class='Database\Seeders\ProductFamilySeeder' --force --no-interaction
$FORGE_PHP artisan db:seed --class='Database\Seeders\IfraProductCategorySeeder' --force --no-interaction
$FORGE_PHP artisan db:seed --class='Database\Seeders\IfraAmendmentSeeder' --force --no-interaction
$FORGE_PHP artisan db:seed --class='Database\Seeders\ProductTaxonomySeeder' --force --no-interaction
$FORGE_PHP artisan db:seed --class='Database\Seeders\ProductTypeIfraCategorySeeder' --force --no-interaction

# Regulatory regimes (Canada release; run once, then re-comment)
$FORGE_PHP artisan db:seed --class='Database\Seeders\RegulatoryRegimeSeeder' --force --no-interaction
```

`ProductTaxonomySeeder` requires both product families to exist first, and `ProductTypeIfraCategorySeeder` requires the IFRA categories, amendments, and product types — hence the order above. All five are idempotent (`updateOrCreate`/`upsert`), so a one-off re-run is safe, but it is a deliberate act, never part of routine deploys.

The `RegulatoryRegimeSeeder` shipped with the Canada labelling release and must also run once per environment: it expands the `canada_2026` allergen mapping to the full catalog, renames the US regime, re-points any recipe versions stored against the retired `canada_expanded_preview` regime before deleting it, and leaves EU rules untouched. It is idempotent (`updateOrCreate`), but like the taxonomy seeders it is a deliberate act — uncomment for this deploy, then re-comment afterwards. Canadian declaration names on ingredients are then provisioned through the admin panel (`market_labels`, market `ca`); they are data entry, not seeding.

## Release checks

- Confirm the database backup completed and is recoverable according to the backup runbook.
- Confirm migrations completed and the production-bench translation keys exist in `language_lines`.
- In each enabled locale, trigger a production validation error and verify that its translated message is shown.
- Smoke-test one planned production through stock preparation, start, completion, and output release using curated ingredient and product data.
- Check the production log and browser errors before allowing wider access.

For the detailed backup and restore procedure, use [storage-and-backups.md](./storage-and-backups.md). For catalogue modes, locale activation, and translation recovery, use [localization.md](./localization.md).

## Contextual help rollout

Run the normal migration and supported-locale steps first. For a first installation without existing help content:

```shell
php artisan help:import database/seeders/data/contextual-help.en.json --mode=bootstrap
php artisan help:import database/seeders/data/contextual-help.en.json --mode=bootstrap --apply --force --no-interaction
```

This installs 32 **unpublished English drafts**. Bootstrap never replaces existing revisions or owner edits. Review and publish each topic from Admin → Content → Help Topics, then request translations and accept reviewed candidates as drafts. Publishing remains a separate action. Application-interface translations for panel buttons use the ordinary interface catalogue commands above.

For a replacement installation, restore a downloaded help snapshot **before bootstrap or `help:register-topics`**; `restore-empty` requires all topic, locale, revision, and translation-request tables to be empty. Follow the recovery commands in [storage-and-backups.md](./storage-and-backups.md). Never use `migrate:fresh` on a database with content to preserve.

Help jobs share the ingredient enrichment queue (`INGREDIENT_ENRICHMENT_QUEUE`, default `enrichment`). Use the normal ingredient worker. Restart long-running workers after deployment so they load the new job classes. For the default configuration:

```shell
php artisan queue:work database --queue=enrichment --sleep=3 --timeout=2000 --tries=3
```

Help jobs retain a 120-second timeout; the shared worker allows the longer ingredient jobs. `DB_QUEUE_RETRY_AFTER` must exceed the longest job timeout (keep the existing 2100-second default if other jobs require it). Translation jobs explicitly permit one attempt; uncertain provider calls are never retried automatically. The scheduler redispatches durable pending jobs every five minutes and expires stalled translation attempts after ten minutes. A new paid attempt is requested explicitly in the editor.

Verify a manual snapshot in Admin → Content → Help imports & backups before release. Confirm the recorded snapshot checksum, private download, and an isolated restore. Help reads only published content and falls back to published English when a translation is missing or out of date. Editing English alone does not invalidate a live translation; publishing changed English does.
