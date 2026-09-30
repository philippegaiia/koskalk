<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\SendFormulaShare;
use App\Enums\FormulaShareStatus;
use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\IngredientShareMapping;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersionCosting;
use App\Services\FormulaSharePreview;
use App\Services\FormulaShareSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true, 'workspaces.formula_sharing.rate_limits.preview.actor_per_minute' => 100, 'workspaces.formula_sharing.rate_limits.preview.workspace_per_minute' => 100]);
});

it('accepts a normal published offer as an independent saved and working Product without inherited review or costing', function (string $familySlug): void {
    extract(FormulaSharingFixtures::offer($familySlug));
    $receiver = $recipient->owner;
    $this->actingAs($receiver);
    $preview = app(FormulaSharePreview::class)->build($receiver, $share, []);
    $accepted = app(AcceptFormulaShare::class)->handle($receiver, $share, [], $preview['expected_hash']);
    $saved = $accepted->latestPublishedVersion;
    $current = $accepted->currentVersion;
    expect($accepted->workspace_id)->toBe($recipient->id)->and($accepted->product_family_id)->toBe($family->id)->and($accepted->product_type_id)->toBe($type->id)
        ->and($accepted->locked_at)->toBeNull()->and($saved)->not->toBeNull()->and($current)->not->toBeNull()
        ->and($saved->batch_size)->toBe('1000.125')->and($saved->batch_unit)->toBe('g')
        ->and($accepted->description)->toBe('<p>Description &amp; details.</p>')->and($saved->manufacturing_instructions)->toBe('<p>Mix &amp; rest.</p>')
        ->and($saved->catalog_reviewed_at)->toBeNull()->and($current->catalog_reviewed_at)->toBeNull()
        ->and($saved->final_ingredient_list)->toBeNull()->and($current->final_ingredient_list_basis_hash)->toBeNull()
        ->and($saved->final_plain_ingredient_list)->toBeNull()->and($current->final_plain_ingredient_list_basis_hash)->toBeNull()
        ->and(RecipeVersionCosting::query()->whereIn('recipe_version_id', $accepted->versions()->pluck('id'))->exists())->toBeFalse()
        ->and($saved->packagingItems)->toHaveCount(0)->and($accepted->output_ingredient_id)->toBeNull()->and($accepted->featured_image_path)->toBeNull()
        ->and($share->fresh()->status)->toBe(FormulaShareStatus::Accepted)->and($share->fresh()->accepted_recipe_id)->toBe($accepted->id)
        ->and($saved->items()->where('note', 'Keep line note')->exists())->toBeTrue();
    $map = $share->fresh()->import_receipt['ingredient_map'];
    expect($saved->phases->pluck('slug')->all())->toBe(collect($share->snapshot['formula']['phases'])->pluck('slug')->all());
    foreach ($share->snapshot['formula']['phases'] as $offeredPhase) {
        $receivedPhase = $saved->phases->firstWhere('slug', $offeredPhase['slug']);
        $offeredRows = collect($offeredPhase['items'])->map(fn (array $row): array => ['ingredient_id' => $map[$row['ingredient_key']], 'percentage' => $row['percentage'], 'weight' => $row['weight'], 'note' => $row['note']])->all();
        expect($receivedPhase->items->map(fn (RecipeItem $row): array => $row->only(['ingredient_id', 'percentage', 'weight', 'note']))->all())->toBe($offeredRows);
    }
    if ($familySlug === 'cosmetic') {
        expect($saved->phases->pluck('slug')->all())->toBe(['phase_a', 'phase_b'])
            ->and($saved->items->pluck('percentage')->all())->toBe(['70.1234', '29.8766']);
    } else {
        expect($saved->calculation_context['lye_type'])->toBe('dual')->and($saved->calculation_context['koh_purity_percentage'])->toBe(90)
            ->and($saved->calculation_context['dual_lye_koh_percentage'])->toBe(40);
    }
})->with(['soap', 'cosmetic']);

it('returns the same accepted Product despite expiry and source deletion and never recreates a deleted result', function (): void {
    extract(FormulaSharingFixtures::offer());
    $actor = $recipient->owner;
    $preview = app(FormulaSharePreview::class)->build($actor, $share, []);
    $action = app(AcceptFormulaShare::class);
    $accepted = $action->handle($actor, $share, [], $preview['expected_hash']);
    $this->travel(15)->days();
    $recipe->delete();
    $source->delete();
    expect($action->handle($actor, $share, [], 'ignored after authorized acceptance')->id)->toBe($accepted->id);
    $accepted->delete();
    expect(fn () => $action->handle($actor, $share, [], $preview['expected_hash']))->toThrow(ValidationException::class, __('sharing.validation.accepted_deleted'));
    expect(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0);
});

it('reuses imported material for a different grant but creates a new independent Product each time', function (): void {
    extract(FormulaSharingFixtures::offer('cosmetic'));
    $builder = app(FormulaShareSnapshotBuilder::class);
    $snapshot = $builder->build($owner, $recipe, $share->options);
    $other = app(SendFormulaShare::class)->handle($owner, $recipe, $recipient, $share->options, $builder->previewHash($snapshot, $recipient), (string) Str::uuid());
    $actor = $recipient->owner;
    $preview = app(FormulaSharePreview::class);
    $first = app(AcceptFormulaShare::class)->handle($actor, $share, [], $preview->build($actor, $share, [])['expected_hash']);
    $secondPreview = $preview->build($actor, $other, []);
    expect($secondPreview['import_count'])->toBe(0);
    $second = app(AcceptFormulaShare::class)->handle($actor, $other, [], $secondPreview['expected_hash']);
    expect($second->id)->not->toBe($first->id)->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2)->and(IngredientShareMapping::query()->count())->toBe(2);
});

it('rechecks a stale chosen substitute and remaps real dilution references with its own ancestry', function (): void {
    extract(FormulaSharingFixtures::offer());
    $actor = $recipient->owner;
    $this->actingAs($actor);
    $substitute = Ingredient::factory()->create(['workspace_id' => $recipient->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $recipient->id]);
    $phase = collect($share->snapshot['formula']['phases'])->firstWhere('slug', 'lye_water');
    $key = $phase['items'][0]['ingredient_key'];
    $decisions = [['key' => $key, 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    $preview = app(FormulaSharePreview::class);
    $oldHash = $preview->build($actor, $share, $decisions)['expected_hash'];
    $substitute->update(['inci_name' => 'Changed substitute']);
    expect(fn () => app(AcceptFormulaShare::class)->handle($actor, $share, $decisions, $oldHash))->toThrow(ValidationException::class);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(1)->and($share->fresh()->status)->toBe(FormulaShareStatus::Pending);
    $accepted = app(AcceptFormulaShare::class)->handle($actor, $share, $decisions, $preview->build($actor, $share, $decisions)['expected_hash']);
    $phase = $accepted->latestPublishedVersion->phases->firstWhere('slug', 'lye_water');
    expect($phase->items->first()->ingredient_id)->toBe($substitute->id)->and($phase->items->first()->percentage)->toBe('25.0000')->and($substitute->fresh()->share_lineage_key)->toBeNull();
});

it('validates actual saved phase slugs through real publish issue and preview before creating anything', function (): void {
    extract(FormulaSharingFixtures::offer());
    $actor = $recipient->owner;
    $substitute = Ingredient::factory()->create(['workspace_id' => $recipient->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $recipient->id, 'category' => IngredientCategory::SoapmakingAlkalis]);
    $phases = collect($share->snapshot['formula']['phases']);
    expect($phases->firstWhere('slug', 'lye_water')['phase_type'])->toBe('reaction_medium');
    $decisions = [['key' => $phases->firstWhere('slug', 'lye_water')['items'][0]['ingredient_key'], 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    expect(fn () => app(FormulaSharePreview::class)->build($actor, $share, $decisions))->toThrow(ValidationException::class);
    $decisions = [['key' => $phases->firstWhere('slug', 'saponified_oils')['items'][0]['ingredient_key'], 'mode' => 'substitute', 'ingredient_public_id' => $substitute->public_id]];
    expect(fn () => app(FormulaSharePreview::class)->build($actor, $share, $decisions))->toThrow(ValidationException::class);
    expect($share->fresh()->status)->toBe(FormulaShareStatus::Pending)->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0);
});

it('rolls back imported Ingredients mappings and Product when publication fails its destination quota or late validation', function (string $failure): void {
    extract(FormulaSharingFixtures::offer('cosmetic', $failure === 'quota' ? 0 : 100, $failure === 'ingredient_quota' ? 0 : 100));
    $actor = $recipient->owner;
    if ($failure === 'validation') {
        $snapshot = $share->snapshot;
        $snapshot['formula']['phases'][0]['items'][0]['percentage'] = '65.0000';
        $share->forceFill(['snapshot' => $snapshot])->save();
    }
    $preview = app(FormulaSharePreview::class)->build($actor, $share, []);
    expect(fn () => app(AcceptFormulaShare::class)->handle($actor, $share, [], $preview['expected_hash']))->toThrow(ValidationException::class);
    expect(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0)->and(IngredientShareMapping::query()->count())->toBe(0)
        ->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0)->and($share->fresh()->status)->toBe(FormulaShareStatus::Pending);
})->with(['quota', 'ingredient_quota', 'validation']);

it('accepts captured private facts after source edits and source Saved formula and Product deletion', function (): void {
    extract(FormulaSharingFixtures::offer('cosmetic'));
    $oil->update(['inci_name' => 'Changed after sending']);
    $saved->delete();
    $recipe->delete();
    $actor = $recipient->owner;
    $preview = app(FormulaSharePreview::class)->build($actor, $share, []);
    $accepted = app(AcceptFormulaShare::class)->handle($actor, $share, [], $preview['expected_hash']);
    expect($accepted->workspace_id)->toBe($recipient->id)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->where('inci_name', 'Changed after sending')->exists())->toBeFalse()
        ->and($share->fresh()->source_recipe_id)->toBeNull()->and($share->fresh()->source_version_id)->toBeNull();
});

it('rejects inconsistent saved phase classification at capture and in a persisted offer', function (): void {
    extract(FormulaSharingFixtures::offer());
    $saved->phases()->withoutGlobalScopes()->where('slug', 'lye_water')->update(['phase_type' => 'reaction_core']);
    expect(fn () => app(FormulaShareSnapshotBuilder::class)->build($owner, $recipe, []))->toThrow(ValidationException::class, __('sharing.validation.formula'));
    $snapshot = $share->snapshot;
    $snapshot['formula']['phases'][1]['phase_type'] = 'reaction_core';
    $share->forceFill(['snapshot' => $snapshot])->save();
    expect(fn () => app(FormulaSharePreview::class)->build($recipient->owner, $share, []))->toThrow(ValidationException::class, __('sharing.validation.formula'));
    expect(FormulaShare::query()->count())->toBe(1)->and(Recipe::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(0);
});
