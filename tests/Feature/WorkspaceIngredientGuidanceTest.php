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
use App\Services\WorkspaceIngredientGuidanceService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores one audited guidance override per workspace and platform ingredient', function (): void {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($creator, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_markdown' => '## Overview',
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    expect(Schema::hasColumns('workspace_ingredient_guidances', [
        'workspace_id',
        'ingredient_id',
        'guidance_markdown',
        'created_by_user_id',
        'updated_by_user_id',
    ]))->toBeTrue()
        ->and($override->workspace->is($workspace))->toBeTrue()
        ->and($override->ingredient->is($ingredient))->toBeTrue()
        ->and($override->creator->is($creator))->toBeTrue()
        ->and($override->updater->is($updater))->toBeTrue()
        ->and($workspace->ingredientGuidances->contains($override))->toBeTrue()
        ->and($ingredient->workspaceGuidances->contains($override))->toBeTrue();
});

it('rejects duplicate workspace and ingredient guidance overrides', function (): void {
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

it('cascades guidance overrides when their workspace is deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspace->delete();

    $this->assertModelMissing($override);
});

it('cascades guidance overrides when their ingredient is deleted', function (): void {
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

it('resolves localized platform guidance until a workspace override exists', function (): void {
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

    expect($service->effectiveGuidance($workspace, $ingredient, 'fr'))
        ->toBe('Conseils de la plateforme');

    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_markdown' => 'Workspace text in any language',
    ]);

    expect($service->effectiveGuidance($workspace, $ingredient, 'fr'))
        ->toBe('Workspace text in any language')
        ->and($service->effectiveGuidance($workspace, $ingredient, 'de'))
        ->toBe('Workspace text in any language');
});

it('keeps workspace-owned ingredient guidance independent from workspace overrides', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'info_markdown' => 'Workspace-owned guidance',
    ]);
    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_markdown' => 'Invalid platform override',
    ]);

    expect(app(WorkspaceIngredientGuidanceService::class)
        ->effectiveGuidance($workspace, $ingredient, 'fr'))
        ->toBe('Workspace-owned guidance');
});

it('trims and audits an owner-created platform guidance override', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();

    $override = app(WorkspaceIngredientGuidanceService::class)->save(
        $owner,
        $workspace,
        $ingredient,
        "  ## Workspace guidance\n",
    );

    expect($override->guidance_markdown)->toBe('## Workspace guidance')
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
        ->and($updated->guidance_markdown)->toBe('Updated guidance');
});

it('allows an admin to reset workspace guidance', function (): void {
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

    $service->reset($admin, $workspace, $ingredient);

    $this->assertModelMissing($override);
    expect($service->effectiveGuidance($workspace, $ingredient, 'en'))
        ->toBe('Current platform guidance');
});

it('forbids viewers and non-members from saving or resetting guidance', function (): void {
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
        ->and(fn () => $service->reset($viewer, $workspace, $ingredient))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->reset($nonMember, $workspace, $ingredient))
        ->toThrow(AuthorizationException::class);
});

it('rejects invalid workspace guidance without changing an existing override', function (string $guidance): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);
    $service->save($owner, $workspace, $ingredient, 'Existing guidance');

    expect(fn () => $service->save($owner, $workspace, $ingredient, $guidance))
        ->toThrow(ValidationException::class);

    expect($service->overrideFor($workspace, $ingredient)->guidance_markdown)
        ->toBe('Existing guidance');
})->with([
    'empty after trimming' => '   ',
    'raw html' => '<script>alert(1)</script>',
    'too long unicode value' => str_repeat('界', 2001),
]);

it('accepts the two-thousand-character Unicode boundary and supported Markdown', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $service = app(WorkspaceIngredientGuidanceService::class);

    $boundary = $service->save($owner, $workspace, $ingredient, str_repeat('界', 2000));
    $markdown = $service->save(
        $owner,
        $workspace,
        $ingredient,
        "# Heading\n\n- item\n- **emphasis**\n\n[More](https://example.com)",
    );

    expect(mb_strlen($boundary->guidance_markdown))->toBe(2000)
        ->and($markdown->guidance_markdown)
        ->toBe("# Heading\n\n- item\n- **emphasis**\n\n[More](https://example.com)");
});

it('rejects inactive platform and workspace-owned ingredients for overrides', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $inactivePlatform = Ingredient::factory()->create(['is_active' => false]);
    $workspaceIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
    $service = app(WorkspaceIngredientGuidanceService::class);

    expect(fn () => $service->save($owner, $workspace, $inactivePlatform, 'Guidance'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->save($owner, $workspace, $workspaceIngredient, 'Guidance'))
        ->toThrow(ValidationException::class);
});

it('restores the latest localized platform guidance after reset', function (): void {
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
    $service->reset($owner, $workspace, $ingredient);

    expect($service->effectiveGuidance($workspace, $ingredient, app()->getLocale()))
        ->toBe('Conseils de la plateforme');
});
