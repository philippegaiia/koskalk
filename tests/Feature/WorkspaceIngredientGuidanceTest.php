<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceIngredientGuidanceContent;
use App\Services\WorkspaceIngredientGuidanceService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores one audited guidance record per workspace and ingredient', function (): void {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($creator, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $guidance = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<h2>Overview</h2>',
        'is_active' => false,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    expect(Schema::hasColumns('workspace_ingredient_guidances', [
        'workspace_id',
        'ingredient_id',
        'guidance_html',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ]))->toBeTrue()
        ->and($guidance->is_active)->toBeFalse()
        ->and($guidance->workspace->is($workspace))->toBeTrue()
        ->and($guidance->ingredient->is($ingredient))->toBeTrue()
        ->and($guidance->creator->is($creator))->toBeTrue()
        ->and($guidance->updater->is($updater))->toBeTrue()
        ->and($workspace->ingredientGuidances->contains($guidance))->toBeTrue()
        ->and($ingredient->workspaceGuidances->contains($guidance))->toBeTrue();
});

it('rejects duplicate workspace and ingredient guidance records', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
    ]);

    expect(fn (): WorkspaceIngredientGuidance => WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
    ]))->toThrow(QueryException::class);
});

it('cascades guidance when their workspace is deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspace->delete();

    $this->assertModelMissing($override);
});

it('cascades guidance when their ingredient is deleted', function (): void {
    $ingredient = Ingredient::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'ingredient_id' => $ingredient->id,
    ]);

    $ingredient->delete();

    $this->assertModelMissing($override);
});

it('nulls only the matching audit attribution when an audit user is deleted', function (): void {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    $creator->delete();

    expect($override->fresh()->created_by_user_id)->toBeNull()
        ->and($override->fresh()->updated_by_user_id)->toBe($updater->id);

    $updater->delete();

    expect($override->fresh()->created_by_user_id)->toBeNull()
        ->and($override->fresh()->updated_by_user_id)->toBeNull();
});

it('resolves localized platform guidance until active workspace guidance exists', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => 'Platform guidance in English',
    ]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => 'Conseils de la plateforme',
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);

    expect($service->effectiveHtml($workspace, $ingredient, 'fr'))
        ->toContain('Conseils de la plateforme');

    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<p>Workspace text in any language</p>',
    ]);

    expect($service->effectiveHtml($workspace, $ingredient, 'fr'))
        ->toBe('<p>Workspace text in any language</p>')
        ->and($service->effectiveHtml($workspace, $ingredient, 'de'))
        ->toBe('<p>Workspace text in any language</p>');
});

it('resolves workspace-owned ingredient guidance from the shared workspace table', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'info_markdown' => null,
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<p>Workspace-owned guidance</p>',
    ]);

    expect(app(WorkspaceIngredientGuidanceService::class)
        ->effectiveHtml($workspace, $ingredient, 'fr'))
        ->toBe('<p>Workspace-owned guidance</p>');
});

it('sanitizes and audits owner-created workspace guidance', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();

    $override = app(WorkspaceIngredientGuidanceService::class)->save(
        $owner,
        $workspace,
        $ingredient,
        "  <h2>Workspace guidance</h2>\n",
    );

    expect($override->guidance_html)->toBe('<h2>Workspace guidance</h2>')
        ->and($override->is_active)->toBeTrue()
        ->and($override->created_by_user_id)->toBe($owner->id)
        ->and($override->updated_by_user_id)->toBe($owner->id);
});

it('preserves the creator while auditing an editor update', function (): void {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);
    $service->save($owner, $workspace, $ingredient, 'Original guidance');

    $updated = $service->save($editor, $workspace, $ingredient, 'Updated guidance');

    expect($updated->created_by_user_id)->toBe($owner->id)
        ->and($updated->updated_by_user_id)->toBe($editor->id)
        ->and($updated->guidance_html)->toBe('<p>Updated guidance</p>')
        ->and($updated->is_active)->toBeTrue();
});

it('allows an admin to switch to platform guidance without deleting the workspace version', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create([
        'role' => WorkspaceMemberRole::Admin,
    ]);
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => 'Current platform guidance',
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);
    $override = $service->save($owner, $workspace, $ingredient, 'Workspace guidance');

    $service->usePlatform($admin, $workspace, $ingredient);

    expect($override->fresh()->is_active)->toBeFalse()
        ->and($service->effectiveHtml($workspace, $ingredient, 'en'))
        ->toContain('Current platform guidance');

    $service->useWorkspace($admin, $workspace, $ingredient);

    expect($override->fresh()->is_active)->toBeTrue()
        ->and($service->effectiveHtml($workspace, $ingredient, 'en'))
        ->toBe('<p>Workspace guidance</p>');
});

it('forbids viewers and non-members from saving or switching guidance', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $nonMember = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);

    expect(fn (): WorkspaceIngredientGuidance => $service->save(
        $viewer,
        $workspace,
        $ingredient,
        'Viewer guidance',
    ))->toThrow(AuthorizationException::class)
        ->and(fn (): WorkspaceIngredientGuidance => $service->save(
            $nonMember,
            $workspace,
            $ingredient,
            'Non-member guidance',
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->usePlatform($viewer, $workspace, $ingredient))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->usePlatform($nonMember, $workspace, $ingredient))
        ->toThrow(AuthorizationException::class);
});

it('rejects invalid workspace guidance without changing an existing record', function (string $guidance): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);
    $service->save($owner, $workspace, $ingredient, 'Existing guidance');

    expect(fn () => $service->save($owner, $workspace, $ingredient, $guidance))
        ->toThrow(ValidationException::class);

    expect($service->recordFor($workspace, $ingredient)->guidance_html)
        ->toBe('<p>Existing guidance</p>');
})->with([
    'empty after trimming' => '   ',
    'empty html' => '<p><br></p>',
]);

it('accepts the ten-thousand-character Unicode boundary and supported HTML', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);

    $content = app(WorkspaceIngredientGuidanceContent::class);
    $boundaryHtml = '<p>'.str_repeat('word ', 1999).'wordx</p>';
    $boundary = $service->save($owner, $workspace, $ingredient, $boundaryHtml);

    expect(mb_strlen($content->text($boundary->guidance_html)))->toBe(10000);

    expect(fn () => $service->save($owner, $workspace, $ingredient, $boundaryHtml.'<p>x</p>'))
        ->toThrow(ValidationException::class);

    $markdown = $service->save(
        $owner,
        $workspace,
        $ingredient,
        '<h2>Heading</h2><ul><li>item</li></ul><p><strong>emphasis</strong> <a href="https://example.com">More</a></p>',
    );

    expect($markdown->guidance_html)
        ->toBe('<h2>Heading</h2><ul><li>item</li></ul><p><strong>emphasis</strong> <a href="https://example.com">More</a></p>');
});

it('rejects inactive platform ingredients and cross-workspace private ingredients', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $inactivePlatform = Ingredient::factory()->create(['is_active' => false]);
    $otherWorkspace = Workspace::factory()->create();
    $workspaceIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $otherWorkspace->id,
        'workspace_id' => $otherWorkspace->id,
        'visibility' => Visibility::Private,
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);

    expect(fn () => $service->save($owner, $workspace, $inactivePlatform, 'Guidance'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->save($owner, $workspace, $workspaceIngredient, '<p>Guidance</p>'))
        ->toThrow(ValidationException::class);
});

it('falls back to the latest localized platform guidance while inactive', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => 'English platform guidance',
    ]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => 'Conseils de la plateforme',
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);
    $service->save($owner, $workspace, $ingredient, 'Workspace-authored guidance');

    app()->setLocale('fr');
    $service->usePlatform($owner, $workspace, $ingredient);

    expect($service->effectiveHtml($workspace, $ingredient, app()->getLocale()))
        ->toContain('Conseils de la plateforme');
});
