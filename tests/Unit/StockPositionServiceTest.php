<?php

use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Workspace;
use App\Services\StockPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('separates physical quarantined and available stock', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $released = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $quarantined = StockLot::factory()->for($workspace)->for($ingredient)->create();

    StockMovement::factory()->for($released)->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '1000',
    ]);
    StockMovement::factory()->for($quarantined)->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '250',
    ]);

    $positions = app(StockPositionService::class)->forWorkspaceSubject($workspace, $ingredient);

    expect($positions)->toBe([
        'physical' => '1250.000000000',
        'quarantined' => '250.000000000',
        'reserved' => '0.000000000',
        'available' => '1000.000000000',
        'incoming' => '0.000000000',
        'forecast' => '1000.000000000',
    ]);
});

it('allows physical and available stock to become negative', function (): void {
    $lot = StockLot::factory()->released()->create();
    StockMovement::factory()->for($lot)->create([
        'workspace_id' => $lot->workspace_id,
        'quantity_delta' => '-4',
    ]);

    expect(app(StockPositionService::class)->forLot($lot))
        ->physical->toBe('-4.000000000')
        ->available->toBe('-4.000000000');
});
