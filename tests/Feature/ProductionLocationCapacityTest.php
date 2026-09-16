<?php

use App\Enums\ProductionRunStatus;
use App\Models\ProductionHoliday;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\Workspace;
use App\Services\Production\FlashDateProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function locationProposalLine(int $index = 0, ?int $locationId = null, int $batches = 2): array
{
    return ['line_index' => $index, 'recipe_id' => 1, 'recipe_name' => 'Soap', 'whole_batches' => $batches,
        'production_location_id' => $locationId, 'output_ready_delay_days' => 0, 'task_items' => []];
}

it('includes work already planned in the overall daily limit', function (): void {
    $workspace = Workspace::factory()->create();
    ProductionRun::factory()->for($workspace)->create(['planned_for' => '2026-09-21', 'status' => ProductionRunStatus::Scheduled]);
    $dates = app(FlashDateProposalService::class)->propose($workspace, [locationProposalLine()], '2026-09-21', 2);
    expect(array_column($dates, 'production_date'))->toBe(['2026-09-21', '2026-09-22']);
});

it('counts occupied states but not drafts or cancelled runs', function (ProductionRunStatus $status, string $expected): void {
    $workspace = Workspace::factory()->create();
    ProductionRun::factory()->for($workspace)->create(['planned_for' => '2026-09-21', 'status' => $status]);
    $dates = app(FlashDateProposalService::class)->propose($workspace, [locationProposalLine(batches: 1)], '2026-09-21', 1);
    expect($dates[0]['production_date'])->toBe($expected);
})->with([
    [ProductionRunStatus::Draft, '2026-09-21'], [ProductionRunStatus::Cancelled, '2026-09-21'],
    [ProductionRunStatus::Reserved, '2026-09-22'], [ProductionRunStatus::InProduction, '2026-09-22'],
    [ProductionRunStatus::Completed, '2026-09-22'], [ProductionRunStatus::Aborted, '2026-09-22'],
]);

it('finds independent early dates for different production locations', function (): void {
    $workspace = Workspace::factory()->create(['uses_production_locations' => true]);
    $soap = ProductionLocation::factory()->for($workspace)->create(['daily_production_limit' => 1]);
    $lab = ProductionLocation::factory()->for($workspace)->create(['daily_production_limit' => 2]);
    ProductionRun::factory()->for($workspace)->create(['planned_for' => '2026-09-21', 'status' => ProductionRunStatus::Scheduled, 'production_location_id' => $soap->id]);
    $dates = app(FlashDateProposalService::class)->propose($workspace, [locationProposalLine(0, $soap->id, 1), locationProposalLine(1, $lab->id, 1)], '2026-09-21', 3);
    expect(collect($dates)->keyBy('line_index')->map(fn ($row) => $row['production_date'])->all())->toBe([0 => '2026-09-22', 1 => '2026-09-21']);
});

it('ignores saved locations when the option is off', function (): void {
    $workspace = Workspace::factory()->create();
    $location = ProductionLocation::factory()->for($workspace)->create(['daily_production_limit' => 1]);
    $dates = app(FlashDateProposalService::class)->propose($workspace, [locationProposalLine(locationId: $location->id)], '2026-09-21', 2);
    expect(array_column($dates, 'production_date'))->toBe(['2026-09-21', '2026-09-21']);
    expect(array_column($dates, 'production_location_id'))->toBe([null, null]);
});

it('uses the overall limit for unassigned work and ignores another workspace occupancy', function (): void {
    $workspace = Workspace::factory()->create(['uses_production_locations' => true]);
    $other = Workspace::factory()->create();
    ProductionRun::factory()->for($other)->create(['planned_for' => '2026-09-21', 'status' => ProductionRunStatus::Scheduled]);
    $location = ProductionLocation::factory()->for($workspace)->create(['daily_production_limit' => 10]);
    $dates = app(FlashDateProposalService::class)->propose($workspace, [locationProposalLine(0, null, 1), locationProposalLine(1, $location->id, 1)], '2026-09-21', 1);
    expect(array_column($dates, 'production_date'))->toBe(['2026-09-21', '2026-09-22']);
});

it('refreshes calendar holidays between previews using the same service', function (): void {
    $workspace = Workspace::factory()->create();
    $service = app(FlashDateProposalService::class);
    $first = $service->propose($workspace, [locationProposalLine(batches: 1)], '2026-09-21', 1);
    ProductionHoliday::factory()->for($workspace)->create(['date' => '2026-09-21', 'is_recurring' => false]);
    $next = $service->propose($workspace, [locationProposalLine(batches: 1)], '2026-09-21', 1);
    expect($first[0]['production_date'])->toBe('2026-09-21')->and($next[0]['production_date'])->toBe('2026-09-22');
});
