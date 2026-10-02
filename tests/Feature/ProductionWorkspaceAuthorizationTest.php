<?php

use App\Actions\Inventory\DetachProductionDocument;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\SaveDepartment;
use App\Actions\Purchasing\DeleteSupplier;
use App\Actions\Purchasing\SaveSupplier;
use App\Enums\ProcurementStage;
use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\BatchSizeForm;
use App\Livewire\ProductionBench\Production\BatchSizeIndex;
use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Livewire\ProductionBench\Production\TaskSetForm;
use App\Livewire\ProductionBench\Purchasing\SupplierCreate;
use App\Models\Department;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\ProductionDocument;
use App\Models\ProductionRun;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

/** @return array{User, Workspace, User, WorkspaceMember} */
function productionAuthorizationContext(WorkspaceMemberRole $role = WorkspaceMemberRole::Editor): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    $member = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace, $member, $membership];
}

it('allows operational writes but reserves settings and deletion for managers', function (WorkspaceMemberRole $role): void {
    [$owner, $workspace, $member] = productionAuthorizationContext($role);
    $actor = $role === WorkspaceMemberRole::Owner ? $owner : $member;
    $access = app(ProductionBenchAccess::class);
    $access->assertReadable($actor, $workspace);
    expect($access->canWrite($actor, $workspace))->toBe($role !== WorkspaceMemberRole::Viewer);

    $supplier = Supplier::factory()->for($workspace)->create();
    if (in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
        $department = app(SaveDepartment::class)->handle($actor, $workspace, 'Production team');
        expect($department->workspace_id)->toBe($workspace->id);
        expect(app(DeleteSupplier::class)->handle($actor, $workspace, $supplier))->toBeTrue();
    } else {
        expect(fn () => app(SaveDepartment::class)->handle($actor, $workspace, 'Production team'))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteSupplier::class)->handle($actor, $workspace, $supplier))->toThrow(AuthorizationException::class);
        expect($supplier->fresh())->not->toBeNull();
    }
})->with(WorkspaceMemberRole::cases());

it('retains cancellation evidence when an editor cancels a production', function (): void {
    [, $workspace, $editor] = productionAuthorizationContext();
    $production = ProductionRun::factory()->for($workspace)->create();

    app(CancelProduction::class)->handle($editor, $production, 'Customer postponed order', editing: ProductionEditingFixture::command($editor, $production));

    expect($production->fresh())
        ->status->toBe(ProductionRunStatus::Cancelled)
        ->cancelled_by_user_id->toBe($editor->id)
        ->cancellation_reason->toBe('Customer postponed order');
});

it('rejects mounted purchasing forms after workspace switching', function (): void {
    [$owner, $workspace] = productionAuthorizationContext();
    $other = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $other->id])->save();
    app(ProductionBenchAccess::class)->activate($owner, $other);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $page = Livewire::actingAs($owner)->test(SupplierCreate::class)
        ->fillForm(['code' => 'SWITCHED', 'name' => 'Unsaved supplier']);

    User::query()->whereKey($owner->id)->update(['active_workspace_id' => $other->id]);

    $page->call('save')->assertForbidden();
    expect(Supplier::query()->where('code', 'SWITCHED')->exists())->toBeFalse();
});

it('rechecks live membership before a mounted supplier form saves', function (string $change): void {
    [$owner, $workspace, $editor, $membership] = productionAuthorizationContext();
    $page = Livewire::actingAs($editor)->test(SupplierCreate::class)
        ->fillForm(['code' => 'REVOKED', 'name' => 'Unsaved supplier']);

    match ($change) {
        'demotion' => $membership->update(['role' => WorkspaceMemberRole::Viewer]),
        'removal' => $membership->delete(),
        'entitlement' => $owner->entitlements()->update(['status' => 'expired']),
    };

    $page->call('save')->assertStatus($change === 'removal' ? 404 : 403);
    expect(Supplier::query()->where('code', 'REVOKED')->exists())->toBeFalse();
})->with(['demotion', 'removal', 'entitlement']);

it('preserves cancelled bench reads but blocks mutations for an editor', function (): void {
    [$owner, $workspace, $editor] = productionAuthorizationContext();
    $access = app(ProductionBenchAccess::class);
    $access->cancel($owner, $workspace);
    $access->assertReadable($editor, $workspace);

    expect(fn () => app(SaveSupplier::class)->handle($editor, $workspace, ['code' => 'CANCELLED', 'name' => 'Blocked']))
        ->toThrow(ValidationException::class);
    $this->actingAs($editor)->get(route('production-bench.purchasing.suppliers'))->assertOk();
    expect(Supplier::query()->where('code', 'CANCELLED')->exists())->toBeFalse();
});

it('allows viewer procurement exports after cancellation and denies revoked collaboration', function (): void {
    [$owner, $workspace, $viewer] = productionAuthorizationContext(WorkspaceMemberRole::Viewer);
    $supplier = Supplier::factory()->for($workspace)->create();
    $order = PurchaseOrder::factory()->for($workspace)->for($supplier)->create([
        'stage' => ProcurementStage::Quotation,
        'quotation_snapshot' => [
            'reference' => 'RFQ-PRIVATE',
            'supplier' => ['name' => $supplier->name],
            'lines' => [],
        ],
    ]);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    $this->actingAs($viewer)->get(route('production-bench.purchasing.documents.print', $order))
        ->assertOk()->assertSee('RFQ-PRIVATE');

    $owner->entitlements()->update(['status' => 'expired']);

    $this->get(route('production-bench.purchasing.documents.print', $order))->assertForbidden();
});

it('rejects direct operational writes from a viewer and an editor in another selected workspace', function (): void {
    [, $workspace, $viewer] = productionAuthorizationContext(WorkspaceMemberRole::Viewer);
    expect(fn () => app(SaveSupplier::class)->handle($viewer, $workspace, ['code' => 'NO', 'name' => 'Blocked']))
        ->toThrow(AuthorizationException::class);

    [, $otherWorkspace, $editor] = productionAuthorizationContext();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    expect(fn () => app(SaveSupplier::class)->handle($editor, $workspace, ['code' => 'NO', 'name' => 'Blocked']))
        ->toThrow(AuthorizationException::class);
    expect($editor->company()->id)->toBe($otherWorkspace->id);
});

it('keeps settings readable without exposing editor mutations', function (): void {
    [, $workspace, $editor] = productionAuthorizationContext();
    $department = Department::factory()->for($workspace)->create();

    Livewire::actingAs($editor)->test(SettingsIndex::class, ['section' => 'departments'])
        ->assertSee($department->name)
        ->assertDontSeeHtml('wire:click="editDepartment(')
        ->assertDontSeeHtml('wire:click="deleteDepartment(')
        ->assertDontSeeHtml('type="submit"');

    Livewire::test(BatchSizeIndex::class)
        ->assertDontSeeHtml(route('production-bench.production.settings.presets.create'));
    Livewire::test(TaskSetForm::class)->assertForbidden();
    Livewire::test(BatchSizeForm::class)->assertForbidden();
});

it('requires a manager to detach production evidence while retaining the media asset', function (): void {
    [, $workspace, $editor, $membership] = productionAuthorizationContext();
    $document = ProductionDocument::factory()->for($workspace)->create();
    $assetId = $document->media_asset_id;

    expect(fn () => app(DetachProductionDocument::class)->handle($editor, $document))
        ->toThrow(AuthorizationException::class);
    expect($document->fresh())->not->toBeNull();

    $membership->update(['role' => WorkspaceMemberRole::Admin]);
    app(DetachProductionDocument::class)->handle($editor, $document);

    expect($document->fresh())->toBeNull();
    expect(MediaAsset::withoutGlobalScopes()->find($assetId))->not->toBeNull();
});
