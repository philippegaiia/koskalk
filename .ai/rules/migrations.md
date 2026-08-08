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
