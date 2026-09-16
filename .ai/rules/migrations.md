---
paths:
  - 'database/migrations/**'
---

# Migrations

## Define foreign keys with foreignId()->constrained()
Define foreign keys with $table->foreignId('column')->constrained()->cascadeOnDelete() (or ->nullOnDelete()); add the onDelete modifier explicitly. Reserve manual ->foreign()->references()->on() for cases needing custom index names or restrictOnDelete.

## Write real reverse logic in down()
Every migration (except vendor ones) must define down() that reverses up(): dropColumn/dropIndex/dropForeign/dropConstrainedForeignId for alters, dropIfExists for creates. No one-way migrations.

## Store enum-backed columns as string() with a PHP enum cast
Store enum-backed columns as $table->string('column', n) in migrations and cast to a PHP enum in the model's casts() (enum classes live in app/Enums). Use DB enum() columns only for the legacy owner_type/visibility/role tenancy trio.

## Preserve SQLite triggers and partial indexes on table rebuild
Adding foreign keys or changing columns through Schema may rebuild SQLite tables, dropping triggers and converting partial indexes into unconditional indexes. Preserve existing trigger SQL and partial index definitions on both migration directions, or use native compatible ALTER statements; run the production integrity and schema tests.
