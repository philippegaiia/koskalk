<?php

use App\Livewire\Dossiers\QuickSupplierCreate;
use App\Livewire\Dossiers\RawMaterialDetail;
use App\Livewire\Dossiers\RawMaterialsIndex;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function rawMaterialWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

it('creates a supplier from the DIP pages and announces it', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)
        ->fillForm(['code' => 'akemia', 'name' => 'Akemia', 'default_currency' => 'EUR', 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('dossier-supplier-created');

    expect(Supplier::query()->where('workspace_id', $workspace->id)->sole()->code)->toBe('AKEMIA');
});

it('refuses the quick supplier form without the DIP module', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)->assertForbidden();
});

function ingredientUsedIn(Workspace $workspace, string $name): Ingredient
{
    $ingredient = Ingredient::factory()->create(['display_name' => $name]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id]);
    RecipeItem::factory()->create(['recipe_version_id' => $version->id, 'workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);

    return $ingredient;
}

it('lists the ingredients used in the workspace formulas', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    ingredientUsedIn($workspace, 'Huile d’olive raffinée bio');
    Ingredient::factory()->create(['display_name' => 'Unused butter']);
    $this->actingAs($owner);

    Livewire::test(RawMaterialsIndex::class)
        ->assertSee('Huile d’olive raffinée bio')
        ->assertDontSee('Unused butter');
});

it('qualifies a supplier and stores a document from the detail page', function (): void {
    Storage::fake(config('dossiers.evidence_disk'));
    [$owner, $workspace] = rawMaterialWorkspace();
    $olive = ingredientUsedIn($workspace, 'Huile d’olive');
    $supplier = Supplier::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Olisud']);
    $this->actingAs($owner);

    $component = Livewire::test(RawMaterialDetail::class, ['ingredient' => $olive->public_id])
        ->fillForm(['supplier_id' => $supplier->id, 'supplier_reference' => 'HV OLIVE', 'status' => 'valide'], 'qualificationForm')
        ->call('saveQualification')
        ->assertHasNoFormErrors([], 'qualificationForm');

    $qualification = SupplierQualification::query()->sole();

    $component
        ->call('startUpload', $qualification->id)
        ->fillForm([
            'type' => 'technical_sheet',
            'issued_on' => '2021-03-01',
            'file' => UploadedFile::fake()->createWithContent('FT olive.pdf', '%PDF-1.4 olive'),
        ], 'evidenceForm')
        ->call('uploadEvidence')
        ->assertHasNoFormErrors([], 'evidenceForm')
        ->assertSee('FT olive.pdf');

    expect(DossierEvidenceItem::query()->sole()->supplier_qualification_id)->toBe($qualification->id);
});

it('shows the raw materials page and link only to workspaces with the DIP module', function (): void {
    [$owner] = rawMaterialWorkspace();
    $this->actingAs($owner)->get(route('dossiers.raw-materials'))->assertOk()->assertSee(__('navigation.items.raw_materials'));

    $plain = User::factory()->create();
    Workspace::factory()->for($plain, 'owner')->create();
    $this->actingAs($plain)->get(route('dossiers.raw-materials'))->assertForbidden();
});
