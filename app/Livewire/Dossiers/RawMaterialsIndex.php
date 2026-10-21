<?php

namespace App\Livewire\Dossiers;

use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\RecipeItem;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The workspace's raw materials for its dossiers: ingredients used in its formulas or already qualified.
 */
class RawMaterialsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public int $workspaceId;

    public function mount(): void
    {
        $this->workspaceId = $this->workspace()->id;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        abort_unless($workspace->id === $this->workspaceId, 403);

        $usedIds = RecipeItem::withoutGlobalScopes()->where('workspace_id', $workspace->id)->distinct()->pluck('ingredient_id');
        $qualifiedIds = SupplierQualification::query()->where('workspace_id', $workspace->id)->distinct()->pluck('ingredient_id');

        $ingredients = Ingredient::query()
            ->whereIn('id', $usedIds->merge($qualifiedIds)->unique()->all())
            ->withCount([
                'supplierQualifications as validated_suppliers_count' => fn ($query) => $query
                    ->where('workspace_id', $workspace->id)
                    ->where('status', SupplierQualificationStatus::Valide->value),
            ])
            ->orderBy('display_name')
            ->paginate(25);

        $coverageStates = MaterialAssessmentCoverage::query()
            ->where('coverable_type', Ingredient::class)
            ->whereIn('coverable_id', $ingredients->pluck('id'))
            ->get(['coverable_id', 'review_state'])
            ->mapWithKeys(fn (MaterialAssessmentCoverage $coverage): array => [$coverage->coverable_id => $coverage->review_state->value]);

        return view('livewire.dossiers.raw-materials-index', [
            'ingredients' => $ingredients,
            'coverageStates' => $coverageStates,
        ]);
    }

    private function workspace(): Workspace
    {
        $user = auth()->user();
        $workspace = $user instanceof User ? $user->company(fresh: true) : null;

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canViewModule($user, $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }
}
