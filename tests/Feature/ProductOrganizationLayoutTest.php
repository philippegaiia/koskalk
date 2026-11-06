<?php

use App\Livewire\Dashboard\RecipesIndex;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps organization editing in the product header dialog outside formula settings', function () {
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $component = Livewire::actingAs(User::factory()->create())->test(RecipeWorkbench::class, ['productFamilySlug' => 'soap']);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-product-organization-trigger]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="formula-settings-panel"]//*[@id="product-brand"]')->length)->toBe(0)
        ->and($xpath->query('//*[@id="product-organization-dialog"]//*[@id="product-brand"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="product-organization-dialog"]//*[@id="product-collection"]')->length)->toBe(1);
    $component->assertSeeHtml('href="'.route('product-organization.index').'"');
});

it('groups the view selector with result feedback and advertises organization search', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(RecipesIndex::class);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-product-results-toolbar]//*[@data-product-filter-summary]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-product-results-toolbar]//*[@role="group"]')->length)->toBe(1)
        ->and($xpath->query('//input[@type="search"]')->item(0)->getAttribute('placeholder'))->toBe('Search products, brands or collections');
    $component->assertSeeHtml('href="'.route('product-organization.index').'"');
});
