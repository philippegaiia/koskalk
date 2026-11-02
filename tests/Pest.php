<?php

use App\Enums\MaterialPriceSource;
use App\Enums\PackagingCategory;
use App\Jobs\GenerateFileExport;
use App\Models\CurrentMaterialPrice;
use App\Models\FileExport;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** @param array<string, mixed> $attributes */
function createPackagingItemForWorkspace(array $attributes): PackagingItem
{
    $userId = $attributes['created_by_user_id'] ?? $attributes['user_id'] ?? null;
    $user = is_numeric($userId) ? User::query()->findOrFail((int) $userId) : null;
    $workspaceId = $attributes['workspace_id'] ?? ($user instanceof User
        ? Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->value('id')
        : null);
    $workspace = is_numeric($workspaceId)
        ? Workspace::withoutGlobalScopes()->findOrFail((int) $workspaceId)
        : null;

    if (! $workspace instanceof Workspace && $user instanceof User) {
        $workspace = Workspace::factory()->for($user, 'owner')->create();
    }

    if (! $workspace instanceof Workspace) {
        $workspace = Workspace::factory()->create();
    }

    $actor = $user ?? $workspace->owner;
    $price = $attributes['unit_cost'] ?? null;
    $currency = (string) ($attributes['currency'] ?? $workspace->default_currency ?? 'EUR');
    unset($attributes['user_id'], $attributes['unit_cost'], $attributes['currency']);

    $packagingItem = PackagingItem::query()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $actor?->id,
        'category' => PackagingCategory::Other,
        'is_active' => true,
        ...$attributes,
    ]);

    if ($price !== null && $actor instanceof User) {
        app(CurrentMaterialPriceService::class)->rememberPackaging(
            workspace: $workspace,
            packagingItem: $packagingItem,
            pricePerItem: (string) $price,
            currency: $currency,
            source: MaterialPriceSource::ManualCosting,
            sourceId: null,
            actor: $actor,
        );
    }

    return $packagingItem->load('currentPrice');
}

function rememberIngredientPriceForWorkspace(
    User $user,
    Ingredient $ingredient,
    string $pricePerKilogram,
    ?string $currency = null,
): CurrentMaterialPrice {
    $workspace = Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->first()
        ?? Workspace::factory()->for($user, 'owner')->create();

    return app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: $pricePerKilogram,
        massUnit: 'kg',
        currency: $currency ?? $workspace->default_currency ?? 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $user,
    );
}

function testProductTypeIdForFamily(string $familySlug): int
{
    $family = ProductFamily::query()
        ->where('slug', $familySlug)
        ->latest('id')
        ->first()
        ?? ProductFamily::query()->latest('id')->first()
        ?? ProductFamily::factory()->create([
            'name' => str($familySlug)->headline()->toString(),
            'slug' => $familySlug,
        ]);
    $existingProductTypeId = ProductType::query()
        ->whereHas('productFamilies', fn ($query) => $query->whereKey($family->id))
        ->where('is_active', true)
        ->value('id');

    if ($existingProductTypeId !== null) {
        return (int) $existingProductTypeId;
    }

    return ProductType::factory()->create([
        'product_family_id' => $family->id,
        'name' => "Test {$family->name} Product Type",
        'slug' => "test-{$familySlug}-product-type-{$family->id}",
    ])->id;
}

function queuedExportDownload(TestResponse $response): TestResponse
{
    $response->assertStatus(202)->assertJsonPath('status', 'pending');
    $publicId = basename(parse_url($response->json('status_url'), PHP_URL_PATH));
    $export = FileExport::query()->where('public_id', $publicId)->firstOrFail();
    $actor = auth()->user();
    auth()->forgetUser();
    try {
        app()->call([new GenerateFileExport($export->id), 'handle']);
        expect(auth()->user())->toBeNull();
    } finally {
        test()->actingAs($actor);
    }
    test()->getJson($response->json('status_url'))->assertOk()->assertJsonPath('status', 'ready');

    return test()->get(route('file-exports.download', $export));
}

function xlsxCellNumberFormat(string $contents, string $reference, int $sheet = 1): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-format-');
    $archive = new ZipArchive;

    try {
        file_put_contents($path, $contents);
        $archive->open($path);
        $worksheet = new DOMDocument;
        $worksheet->loadXML($archive->getFromName("xl/worksheets/sheet{$sheet}.xml"));
        $styles = new DOMDocument;
        $styles->loadXML($archive->getFromName('xl/styles.xml'));
        $cell = (new DOMXPath($worksheet))->query('//*[local-name()="c" and @r="'.$reference.'"]')->item(0);
        $styleIndex = (int) $cell->getAttribute('s');
        $style = (new DOMXPath($styles))->query('//*[local-name()="cellXfs"]/*')->item($styleIndex);
        $formatId = $style->getAttribute('numFmtId');
        $customFormat = (new DOMXPath($styles))->query('//*[local-name()="numFmt" and @numFmtId="'.$formatId.'"]')->item(0);

        return $customFormat?->getAttribute('formatCode') ?? match ($formatId) {
            '0' => 'General',
            '1' => '0',
            '2' => '0.00',
            default => throw new RuntimeException('Unknown spreadsheet number format.'),
        };
    } finally {
        $archive->close();
        unlink($path);
    }
}

/**
 * A landscape JPEG (width × height as stored) carrying an EXIF orientation tag.
 */
function jpegWithExifOrientation(int $width, int $height, int $orientation): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // Big-endian TIFF header, one IFD entry: Orientation (0x0112), SHORT, count 1.
    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1)
        .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0)
        .pack('N', 0);
    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function runRecipeWorkbenchScenario(string $scenario): void
{
    $bootstrap = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

// Resolve the same extensionless local imports Vite accepts, keeping production modules intact.
const modules = new Map();
function loadModule(filename) {
    filename = path.resolve(filename);
    if (!modules.has(filename)) {
        modules.set(filename, new vm.SourceTextModule(fs.readFileSync(filename, 'utf8'), { identifier: filename }));
    }
    return modules.get(filename);
}
const entry = loadModule('resources/js/recipe-workbench/component.js');
await entry.link((specifier, referencing) => {
    const filename = path.resolve(path.dirname(referencing.identifier), specifier);
    return loadModule(path.extname(filename) ? filename : `${filename}.js`);
});
await entry.evaluate();
const registryModule = loadModule('resources/js/dirty-state-registry.js');
await registryModule.link(() => {});
await registryModule.evaluate();
const registry = registryModule.namespace.createDirtyStateRegistry();
globalThis.window = { location: { hash: '' } };
const baseline = { recipe_revision: 4, current_version_id: 12, costing_revision: 2, status: 'acquired' };
const workbench = entry.namespace.createRecipeWorkbench({
    canPersist: true, recipe: { id: 30, current_version_id: 12 }, editing: baseline,
    ingredients: [], phases: [],
}, () => registry);
workbench.editingStatus = 'acquired';
workbench.editingOwnsLease = true;
workbench.$wire = { async releaseEditing() { return { ok: true }; } };
function deferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
}
function saved(revision, units, costingRevision = revision) {
    return {
        ok: true,
        editing: { ...baseline, recipe_revision: revision, costing_revision: costingRevision },
        costing: { settings: { unitsProduced: units }, item_prices: [], packaging_items: [] },
    };
}
JS;

    $process = new Process(['node', '--experimental-vm-modules', '--input-type=module', '--eval', $bootstrap."\n".$scenario], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
}
