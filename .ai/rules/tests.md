---
paths:
  - 'tests/**'
---

# Tests

## Declare RefreshDatabase per file via uses()
Declare the DB reset trait per file — uses(RefreshDatabase::class); in Feature tests and uses(TestCase::class, RefreshDatabase::class); in Unit tests that touch the DB — since the global binding in tests/Pest.php is commented out. Prefer this explicit form; re-enabling a global binding would be a valid alternative.

## Factories for test records, seeders for reference data
Build test-owned records with model factories (pass attributes inline as needed). Use $this->seed(...) only for shared reference data (locales, plans, fatty acids, catalogs). Reserve raw DB::table()->insert() for schema-integrity boundary tests that assert rejected writes.

## Assert JSON with atomic chained assertions
Assert JSON responses with atomic chained assertions on the response (->assertJsonPath('path', value), ->assertJsonCount(...), ->assertJsonStructure([...]), ->assertJsonFragment([...])). Do not use whole-payload assertJson([...]) arrays or the fluent AssertableJson API.

## Guard and reset disposable formula sharing PostgreSQL sessions
Real sharing races require VERIFY_FORMULA_SHARING_POSTGRES=true plus FORMULA_SHARING_POSTGRES_DATABASE matching a koskalk_formula_sharing_test_YYYYMMDD name. Verify current_database() before destructive public-schema reset; this clears residual migration trigger functions. Finish/join forked child sessions before another reset. SQLite skips are never evidence of PostgreSQL concurrency behavior.

## Run the suite under a UTF-8 locale, not LANG=C
A shell with LANG=C (or no locale) leaves PHP's intl default locale empty, so Symfony Intl's Currencies::exists() and isValidInAnyCountry() fail for every code. CurrencyCatalogTest then fails 2 of 4 and currency validation errors cascade into dozens of unrelated failures that look like pre-existing breakage. Export LANG=en_US.UTF-8 (or any UTF-8 locale) before running the suite. Confirm a suspected environment failure by re-running the single file with the locale set before concluding the repo is broken.
