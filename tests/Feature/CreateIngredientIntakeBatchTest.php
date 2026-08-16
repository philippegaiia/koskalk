<?php

use App\Actions\IngredientIntake\CreateIngredientIntakeBatch;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeInputMethod;
use App\Enums\IngredientIntakeItemStatus;
use App\Enums\IngredientResearchFamily;
use App\Models\Ingredient;
use App\Models\IngredientIntakeBatch;
use App\Models\User;
use App\Services\IngredientIntake\IngredientIntakeParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a draft intake batch for one or more parsed rows', function (int $count): void {
    $admin = User::factory()->admin()->create();
    $contents = collect(range(1, $count))
        ->map(fn (int $index): string => "Ingredient {$index},")
        ->prepend('current_name,inci_name')
        ->implode("\n");
    $rows = app(IngredientIntakeParser::class)->parsePasted($contents);

    $batch = app(CreateIngredientIntakeBatch::class)->handle($admin, [
        'name' => 'Initial oils',
        'notes' => 'Imported for review',
        'input_method' => IngredientIntakeInputMethod::Paste,
        'family_hint' => IngredientResearchFamily::Lipids,
        'allow_gap_research' => true,
    ], $rows);

    expect($batch)->toBeInstanceOf(IngredientIntakeBatch::class)
        ->and($batch->status)->toBe(IngredientIntakeBatchStatus::Draft)
        ->and($batch->total_count)->toBe($count)
        ->and($batch->draft_count)->toBe($count)
        ->and($batch->items)->toHaveCount($count)
        ->and($batch->items->first()->original_current_name)->toBe('Ingredient 1')
        ->and($batch->items->last()->row_number)->toBe($count + 1);
})->with([1, 10, 70]);

it('stores an uploaded csv privately and retains its original filename', function (): void {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $upload = UploadedFile::fake()->createWithContent(
        'identities.csv',
        "current_name,inci_name\nCoconut oil,Cocos Nucifera Oil\n",
    );
    $rows = app(IngredientIntakeParser::class)->parseCsvFile($upload->getRealPath());

    $batch = app(CreateIngredientIntakeBatch::class)->handle($admin, [
        'name' => 'Uploaded identities',
        'input_method' => IngredientIntakeInputMethod::Csv,
        'upload' => $upload,
    ], $rows);

    expect($batch->original_filename)->toBe('identities.csv')
        ->and($batch->storage_disk)->toBe('local')
        ->and($batch->storage_path)->toBeString();

    Storage::disk('local')->assertExists($batch->storage_path);
});

it('detects exact duplicates before a newly created intake batch can start research', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = Ingredient::factory()->create([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'Cocos Nucifera Oil',
    ]);
    $rows = app(IngredientIntakeParser::class)->parsePasted(
        "current_name,inci_name\nCoconut Oil,\nMurumuru Butter,\n",
    );

    $batch = app(CreateIngredientIntakeBatch::class)->handle($admin, [
        'name' => 'Duplicate check',
        'input_method' => IngredientIntakeInputMethod::Paste,
    ], $rows);

    expect($batch->items->first()->status)->toBe(IngredientIntakeItemStatus::NeedsResolution)
        ->and($batch->items->first()->duplicate_candidates)->toContainEqual([
            'candidate_type' => 'ingredient',
            'ingredient_id' => $existing->id,
            'ingredient_public_id' => $existing->public_id,
            'catalog_key' => $existing->catalog_key,
            'label' => $existing->display_name,
            'matched_field' => 'display_name',
            'matched_value' => 'coconut oil',
            'match_type' => 'exact',
            'score' => 100,
        ])
        ->and($batch->items->last()->status)->toBe(IngredientIntakeItemStatus::Draft);
});

it('authorizes intake creation and enforces the separate maximum', function (): void {
    $user = User::factory()->create();
    $rows = app(IngredientIntakeParser::class)->parsePasted("current_name\nCoconut oil\n");

    expect(fn () => app(CreateIngredientIntakeBatch::class)->handle($user, [
        'name' => 'Not allowed',
        'input_method' => IngredientIntakeInputMethod::Paste,
    ], $rows))->toThrow(AuthorizationException::class);

    config()->set('ingredient-enrichment.intake.maximum_batch_size', 1);
    $admin = User::factory()->admin()->create();
    $tooMany = app(IngredientIntakeParser::class)->parsePasted("current_name\nOne\nTwo\n");

    expect(fn () => app(CreateIngredientIntakeBatch::class)->handle($admin, [
        'name' => 'Too many',
        'input_method' => IngredientIntakeInputMethod::Paste,
    ], $tooMany))->toThrow(ValidationException::class);
});
