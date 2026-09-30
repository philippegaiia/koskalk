<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\CloseFormulaShare;
use App\Enums\FormulaShareStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\FormulaSharePreview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.formula_sharing.enabled' => true]);
    $this->freezeTime();
    $this->travelTo(now()->startOfSecond());
});

it('closes pending offers only from their authorized side and permits the same state retry', function (FormulaShareStatus $status, bool $sender): void {
    $share = FormulaShare::factory()->create();
    $actor = $sender ? $share->sourceWorkspace->owner : $share->recipientWorkspace->owner;
    $other = $sender ? $share->recipientWorkspace->owner : $share->sourceWorkspace->owner;
    $action = app(CloseFormulaShare::class);
    expect(fn () => $action->handle($other, $share, $status))->toThrow(AuthorizationException::class);
    $action->handle($actor, $share, $status);
    $closed = $share->fresh()->closed_at;
    $this->travel(2)->hours();
    $action->handle($actor, $share, $status);
    expect($share->fresh()->status)->toBe($status)->and($share->fresh()->closed_at->eq($closed))->toBeTrue();
})->with([[FormulaShareStatus::Revoked, true], [FormulaShareStatus::Declined, false]]);

it('rejects arbitrary and accepted transitions without destroying accepted content', function (): void {
    extract(FormulaSharingFixtures::offer('cosmetic'));
    $actor = $recipient->owner;
    $accepted = app(AcceptFormulaShare::class)->handle($actor, $share, [], app(FormulaSharePreview::class)->build($actor, $share, [])['expected_hash']);
    $action = app(CloseFormulaShare::class);
    expect(fn () => $action->handle($owner, $share, FormulaShareStatus::Revoked))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($actor, $share, FormulaShareStatus::Declined))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($actor, $share, FormulaShareStatus::Pending))->toThrow(ValidationException::class);
    expect($share->fresh()->status)->toBe(FormulaShareStatus::Accepted)->and($share->fresh()->accepted_recipe_id)->toBe($accepted->id)->and($share->fresh()->snapshot)->not->toBeNull();
});

it('rejects acceptance and closure at the exact expiry boundary and expires before purging thirty days later', function (): void {
    $share = FormulaShare::factory()->create(['expires_at' => now()]);
    $actor = $share->recipientWorkspace->owner;
    expect(fn () => app(AcceptFormulaShare::class)->handle($actor, $share, [], 'unused'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CloseFormulaShare::class)->handle($actor, $share, FormulaShareStatus::Declined))->toThrow(ValidationException::class);
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->status)->toBe(FormulaShareStatus::Expired)->and($share->fresh()->snapshot)->not->toBeNull()->and($share->fresh()->closed_at->eq($share->expires_at))->toBeTrue();
    $this->travel(30)->days();
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->snapshot)->toBeNull()->and($share->fresh()->options)->toBeNull()->and($share->fresh()->import_receipt)->toBeNull()->and($share->fresh()->payload_purged_at)->not->toBeNull();
});

it('retains closed offer payload until the exact thirty day boundary then keeps only its tombstone', function (FormulaShareStatus $status): void {
    $share = FormulaShare::factory()->create(['status' => $status, 'closed_at' => now(), 'import_receipt' => ['decisions' => []]]);
    $this->travelTo($share->closed_at->addDays(30)->subSecond());
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->snapshot)->not->toBeNull();
    $this->travel(1)->seconds();
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->snapshot)->toBeNull()->and($share->fresh()->status)->toBe($status)->and($share->fresh()->public_id)->toBe($share->public_id)->and($share->fresh()->sender_workspace_name)->toBe($share->sender_workspace_name);
})->with([FormulaShareStatus::Declined, FormulaShareStatus::Revoked, FormulaShareStatus::Expired]);

it('allows a current sending Owner to revoke after the original member sender departed', function (): void {
    $share = FormulaShare::factory()->create();
    $former = User::factory()->create(['active_workspace_id' => $share->source_workspace_id]);
    $member = WorkspaceMember::factory()->create(['workspace_id' => $share->source_workspace_id, 'user_id' => $former->id, 'role' => WorkspaceMemberRole::Admin]);
    $share->forceFill(['sent_by_user_id' => $former->id])->save();
    $member->delete();
    app(CloseFormulaShare::class)->handle($share->sourceWorkspace->owner, $share, FormulaShareStatus::Revoked);
    expect($share->fresh()->status)->toBe(FormulaShareStatus::Revoked);
});

it('makes a pending offer unusable after its source Workspace is deleted', function (): void {
    $share = FormulaShare::factory()->create();
    $actor = $share->recipientWorkspace->owner;
    $share->sourceWorkspace->delete();
    expect($share->fresh()->source_workspace_id)->toBeNull()
        ->and(fn () => app(AcceptFormulaShare::class)->handle($actor, $share, [], 'unused'))->toThrow(AuthorizationException::class);
});

it('stamps hard deletion inside its transaction while archive restore and rollback do not start retention', function (): void {
    extract(FormulaSharingFixtures::offer('cosmetic'));
    $actor = $recipient->owner;
    $accepted = app(AcceptFormulaShare::class)->handle($actor, $share, [], app(FormulaSharePreview::class)->build($actor, $share, [])['expected_hash']);
    $accepted->update(['archived_at' => now()]);
    $this->travel(45)->days();
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->accepted_product_deleted_at)->toBeNull()->and($share->fresh()->snapshot)->not->toBeNull();
    $accepted->update(['archived_at' => null]);
    DB::beginTransaction();
    try {
        $accepted->delete();
        expect($share->fresh()->accepted_product_deleted_at->eq(now()))->toBeTrue()->and($share->fresh()->accepted_recipe_id)->toBeNull();
    } finally {
        DB::rollBack();
    }
    expect($share->fresh()->accepted_product_deleted_at)->toBeNull()->and($share->fresh()->accepted_recipe_id)->toBe($accepted->id)
        ->and(Recipe::withoutGlobalScopes()->whereKey($accepted->id)->exists())->toBeTrue();
    Recipe::withoutGlobalScopes()->findOrFail($accepted->id)->delete();
    $deletedAt = $share->fresh()->accepted_product_deleted_at;
    $this->travelTo($deletedAt->addDays(30)->subSecond());
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->snapshot)->not->toBeNull();
    $this->travel(1)->seconds();
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($share->fresh()->snapshot)->toBeNull()->and($share->fresh()->import_receipt)->toBeNull()->and($share->fresh()->status)->toBe(FormulaShareStatus::Accepted)
        ->and(Ingredient::withoutGlobalScopes()->where('workspace_id', $recipient->id)->count())->toBe(2)
        ->and(fn () => app(AcceptFormulaShare::class)->handle($actor, $share, [], 'unused'))->toThrow(ValidationException::class, __('sharing.validation.accepted_deleted'));
});

it('schedules bounded retention cleanup daily even when new sharing operations are disabled', function (): void {
    config(['workspaces.formula_sharing.enabled' => false]);
    $expired = FormulaShare::factory()->create(['expires_at' => now()->subDays(31)]);
    $this->artisan('formula-shares:prune')->assertSuccessful();
    expect($expired->fresh()->snapshot)->toBeNull();
    $events = collect(Schedule::events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'formula-shares:prune'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 4 * * *')->and($events->first()->withoutOverlapping)->toBeTrue();
});
