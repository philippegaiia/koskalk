<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('renames is_draft to is_current on recipe_versions', function () {
    $columns = Schema::getColumns('recipe_versions');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->toContain('is_current')
        ->not->toContain('is_draft');
});

it('defaults is_current to true', function () {
    $columns = Schema::getColumns('recipe_versions');
    $column = collect($columns)->first(fn ($c) => $c['name'] === 'is_current');

    expect($column)->not->toBeNull();
    expect((bool) $column['default'])->toBeTrue();
});
