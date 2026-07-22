<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores original image names beside persisted upload paths', function () {
    expect(Schema::hasColumns('recipes', ['featured_image_original_name']))->toBeTrue()
        ->and(Schema::hasColumns('ingredients', [
            'featured_image_original_name',
            'icon_image_original_name',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('user_packaging_items', ['featured_image_original_name']))->toBeTrue()
        ->and(Schema::hasColumns('product_types', ['fallback_image_original_name']))->toBeTrue();
});
