<?php

use App\Enums\FileExportType;
use App\Enums\PackagingCategory;
use App\Enums\WorkspaceMemberRole;
use App\Jobs\GenerateFileExport;
use App\Models\CurrentMaterialPrice;
use App\Models\FileExport;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

/** @return list<list<string|null>> */
function packagingCsvRows(string $content): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

it('queues all matching packaging across pages and excludes other workspaces', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    foreach (range(1, 30) as $number) {
        PackagingItem::factory()->for($workspace)->create([
            'name' => sprintf('Box %02d', $number), 'notes' => 'Matched reference',
            'is_active' => $number !== 30,
        ]);
    }
    PackagingItem::factory()->create(['name' => 'Foreign box', 'notes' => 'Matched reference']);
    PackagingItem::factory()->for($workspace)->create(['name' => 'Unmatched bottle']);

    $response = $this->actingAs($actor)->postJson(route('packaging-items.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv', 'search' => 'Matched reference',
        'sortField' => 'name', 'sortDirection' => 'desc',
    ])->assertStatus(202)->assertJsonPath('status', 'pending');
    $export = FileExport::query()->sole();
    expect($export->type)->toBe(FileExportType::Packaging);
    Queue::assertPushed(GenerateFileExport::class, fn ($job): bool => $job->exportId === $export->id && $job->queue === 'exports');
    Storage::disk('local')->assertDirectoryEmpty('exports');
    $rows = packagingCsvRows(queuedExportDownload($response)->streamedContent());

    expect(array_column(array_slice($rows, 1), 0))->toBe(
        collect(range(30, 1))->map(fn (int $number): string => sprintf('Box %02d', $number))->all(),
    );
});

it('preserves recorded currencies, zero and missing prices in CSV and typed XLSX', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create(['default_currency' => 'GBP']);
    $priced = PackagingItem::factory()->for($workspace)->create([
        'name' => 'Bottle', 'material_code' => 'PK-BOT', 'category' => PackagingCategory::Bottle,
        'notes' => '=HYPERLINK("private")',
    ]);
    $zero = PackagingItem::factory()->for($workspace)->create(['name' => 'Free label']);
    PackagingItem::factory()->for($workspace)->create(['name' => 'Unpriced jar']);
    foreach ([[$priced, '0.035', 'EUR'], [$zero, '0', 'USD']] as [$item, $amount, $currency]) {
        CurrentMaterialPrice::factory()->create([
            'workspace_id' => $workspace->id, 'ingredient_id' => null, 'packaging_item_id' => $item->id,
            'price_per_canonical_unit' => $amount, 'currency' => $currency, 'recorded_at' => '2026-10-01 12:00:00',
        ]);
    }
    $parameters = ['workspace' => $workspace->public_id, 'format' => 'csv'];
    $csv = queuedExportDownload($this->actingAs($actor)->postJson(route('packaging-items.export'), $parameters));
    $rows = packagingCsvRows($csv->streamedContent());
    expect($rows[0])->toBe(['Name', 'Internal material code', 'Category', 'Unit price', 'Currency', 'Price recorded', 'Notes', 'Workspace']);
    expect($rows[1])->toBe(['Bottle', 'PK-BOT', 'Bottle', '0.035', 'EUR', '2026-10-01', "'=HYPERLINK(\"private\")", $workspace->name]);
    expect($rows[2][3])->toBe('0.00')->and($rows[2][4])->toBe('USD');
    expect($rows[3][3])->toBe('')->and($rows[3][4])->toBe('');

    $xlsx = queuedExportDownload($this->postJson(route('packaging-items.export'), [...$parameters, 'format' => 'xlsx']));
    expect(xlsxCellNumberFormat($xlsx->streamedContent(), 'D6'))->toBe('0.00##');
    $path = tempnam(sys_get_temp_dir(), 'packaging-export-test-');
    try {
        file_put_contents($path, $xlsx->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $sheetRows = collect($reader->getSheetIterator()->current()->getRowIterator())->values();
        $bottleRow = $sheetRows->first(fn ($row): bool => $row->getCells()[0]->getValue() === 'Bottle');
        expect($bottleRow->getCells()[3])->toBeInstanceOf(NumericCell::class);
        expect($bottleRow->getCells()[3]->getValue())->toBe(0.035);
        $reader->close();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        expect($xml)->not->toContain('<f>')->toContain('HYPERLINK');
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('exports the same code and price sorting as the list', function (string $field, string $direction, array $expected): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    foreach ([['Box', 'PK-C', '2'], ['Jar', 'PK-A', '3'], ['Label', 'PK-B', '1']] as [$name, $code, $price]) {
        createPackagingItemForWorkspace(['user_id' => $actor->id, 'workspace_id' => $workspace->id, 'name' => $name, 'material_code' => $code, 'unit_cost' => $price]);
    }
    $response = $this->actingAs($actor)->postJson(route('packaging-items.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv', 'sortField' => $field, 'sortDirection' => $direction,
    ]);

    $rows = packagingCsvRows(queuedExportDownload($response)->streamedContent());

    expect(array_column(array_slice($rows, 1), 0))->toBe($expected);
})->with([
    'code ascending' => ['material_code', 'asc', ['Jar', 'Label', 'Box']],
    'code descending' => ['material_code', 'desc', ['Box', 'Label', 'Jar']],
    'price ascending' => ['unit_cost', 'asc', ['Label', 'Box', 'Jar']],
    'price descending' => ['unit_cost', 'desc', ['Jar', 'Box', 'Label']],
]);

it('searches packaging names, internal codes and notes', function (string $field): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $matched = PackagingItem::factory()->for($workspace)->create([$field => 'Needle']);
    PackagingItem::factory()->for($workspace)->create(['name' => 'Different']);

    $response = $this->actingAs($actor)->postJson(route('packaging-items.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv', 'search' => 'Needle',
    ]);
    $rows = packagingCsvRows(queuedExportDownload($response)->streamedContent());

    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe($matched->name);
})->with(['name', 'material_code', 'notes']);

it('cleans up when the packaging list exceeds the limit during generation', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    PackagingItem::factory()->for($workspace)->create();
    config(['workspaces.list_output_limits.max_rows' => 1, 'workspaces.list_output_limits.chunk_size' => 1]);
    $this->actingAs($actor)->postJson(route('packaging-items.export'), [
        'workspace' => $workspace->public_id, 'format' => 'xlsx',
    ])->assertStatus(202);
    $export = FileExport::query()->sole();
    $changed = false;
    DB::listen(function (QueryExecuted $query) use ($workspace, &$changed): void {
        if (! $changed && str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'packaging_items')) {
            $changed = true;
            PackagingItem::factory()->for($workspace)->create();
        }
    });

    app()->call([new GenerateFileExport($export->id), 'handle']);

    $this->getJson(route('file-exports.status', $export))->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', __('packaging.exports.validation.changed'))->assertJsonPath('download_url', null);
    Storage::disk('local')->assertDirectoryEmpty('exports');
    Storage::disk('local')->assertDirectoryEmpty('export-tmp');
});

it('shows export controls and allows a viewer to download packaging without Production Bench access', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    UserEntitlement::factory()->for($owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    PackagingItem::factory()->for($workspace)->create(['name' => 'Shared box']);

    $this->actingAs($viewer)->get(route('packaging-items.index'))->assertSee('Export packaging')
        ->assertSee('Excel workbook (.xlsx)')->assertSee('CSV file (.csv)')
        ->assertSee(route('packaging-items.export'), false)->assertSee($workspace->public_id, false)
        ->assertSeeHtml('data-export-livewire-filters=\'["sortField","sortDirection"]\'')
        ->assertDontSee('Add packaging');
    $response = $this->postJson(route('packaging-items.export'), ['workspace' => $workspace->public_id, 'format' => 'csv']);
    expect(queuedExportDownload($response)->streamedContent())->toContain('Shared box');
});

it('rejects empty and oversized packaging exports before dispatch', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $parameters = ['workspace' => $workspace->public_id, 'format' => 'csv'];

    $this->actingAs($actor)->postJson(route('packaging-items.export'), $parameters)
        ->assertUnprocessable()->assertJsonPath('errors.export.0', __('packaging.exports.validation.empty'));
    PackagingItem::factory()->count(2)->for($workspace)->create();
    config(['workspaces.list_output_limits.max_rows' => 1]);
    $this->postJson(route('packaging-items.export'), $parameters)
        ->assertUnprocessable()->assertJsonPath('errors.export.0', __('packaging.exports.validation.too_many', ['limit' => '1']));
    expect(FileExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('shares the company export rate limit with other members and ingredient exports', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    UserEntitlement::factory()->for($owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    Ingredient::factory()->create(['owner_type' => null, 'is_active' => true]);
    PackagingItem::factory()->for($workspace)->create();
    config(['workspaces.resource_limits.exports.workspace_per_minute' => 1]);
    $parameters = ['workspace' => $workspace->public_id, 'format' => 'csv'];

    $this->actingAs($owner)->postJson(route('ingredients.export'), $parameters)->assertStatus(202);
    $this->actingAs($viewer)->postJson(route('packaging-items.export'), $parameters)->assertTooManyRequests();

    expect(FileExport::query()->count())->toBe(1);
    Queue::assertPushed(GenerateFileExport::class, 1);
});

it('rejects guests and inaccessible or switched workspaces', function (): void {
    $this->postJson(route('packaging-items.export'))->assertUnauthorized();
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $foreign = Workspace::factory()->create();
    $this->actingAs($actor)->postJson(route('packaging-items.export'), ['workspace' => $foreign->public_id, 'format' => 'csv'])->assertForbidden();
    $selected = Workspace::factory()->for($actor, 'owner')->create();
    $actor->forceFill(['active_workspace_id' => $selected->id])->save();

    $this->postJson(route('packaging-items.export'), ['workspace' => $workspace->public_id, 'format' => 'csv'])->assertStatus(409);

    expect(FileExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects malformed packaging export parameters', function (array $invalid, array $fields): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();

    $this->actingAs($actor)->postJson(route('packaging-items.export'), [
        'workspace' => $workspace->public_id, 'format' => 'csv', ...$invalid,
    ])->assertUnprocessable()->assertJsonValidationErrors($fields);

    Queue::assertNothingPushed();
})->with([
    'format' => [['format' => 'pdf'], ['format']],
    'workspace' => [['workspace' => 'not-a-uuid'], ['workspace']],
    'sort field' => [['sortField' => 'id desc'], ['sortField']],
    'sort direction' => [['sortDirection' => 'invalid'], ['sortDirection']],
    'long search' => [['search' => str_repeat('x', 256)], ['search']],
]);

it('rechecks the selected workspace before generating queued packaging', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    PackagingItem::factory()->for($workspace)->create();
    $this->actingAs($actor)->postJson(route('packaging-items.export'), ['workspace' => $workspace->public_id, 'format' => 'csv'])->assertStatus(202);
    $export = FileExport::query()->sole();
    $selected = Workspace::factory()->for($actor, 'owner')->create();
    $actor->forceFill(['active_workspace_id' => $selected->id])->save();

    expect(fn () => app()->call([new GenerateFileExport($export->id), 'handle']))->toThrow(HttpException::class);

    expect($export->fresh()->status)->toBe('failed');
    Storage::disk('local')->assertDirectoryEmpty('exports');
});
