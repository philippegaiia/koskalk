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
