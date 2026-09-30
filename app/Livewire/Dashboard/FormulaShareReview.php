<?php

namespace App\Livewire\Dashboard;

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Actions\FormulaSharing\CloseFormulaShare;
use App\Enums\FormulaShareStatus;
use App\Livewire\Concerns\InteractsWithFormulaSharingWorkspace;
use App\Models\FormulaShare;
use App\Models\Ingredient;
use App\Services\FormulaSharePresenter;
use App\Services\FormulaSharePreview;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FormulaShareReview extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithFormulaSharingWorkspace;

    #[Locked]
    public string $sharePublicId;

    /** @var array<string, mixed> */
    #[Locked]
    public array $display = [];

    #[Locked]
    public ?string $expectedHash = null;

    /** @var array<string, array<string, mixed>> */
    public array $choices = [];

    public bool $confirmed = false;

    public function mount(FormulaShare $share): void
    {
        $this->workspaceId = $this->workspace()->id;
        $this->sharePublicId = $share->public_id;
        $this->refreshPreview();
        $this->form->fill([]);
    }

    public function form(Schema $schema): Schema
    {
        $this->offer();
        $fields = [];
        foreach ($this->display['ingredients'] ?? [] as $row) {
            if ($row['kind'] !== 'private') {
                continue;
            }
            $fields[] = Section::make($row['name'])->schema([
                Select::make($row['key'].'.mode')->label(__('sharing.material_decision'))
                    ->options(['automatic' => __('sharing.modes.automatic'), 'import' => __('sharing.modes.import'), 'reuse' => __('sharing.modes.reuse'), 'substitute' => __('sharing.modes.substitute')])
                    ->default('automatic')->live(),
                Select::make($row['key'].'.ingredient_public_id')->label(__('sharing.local_ingredient'))
                    ->options(fn (): array => $this->localIngredients())->searchable()->live(),
                Toggle::make($row['key'].'.confirmed')->label(__('sharing.confirm_substitute'))->default(false),
            ])->columns(['md' => 2]);
        }

        return $schema->components($fields)->statePath('choices');
    }

    public function updatedChoices(mixed $value, ?string $key = null): void
    {
        $this->offer();
        if ($key !== null && (str_ends_with($key, '.mode') || str_ends_with($key, '.ingredient_public_id'))) {
            $node = explode('.', $key)[0];
            $this->choices[$node]['confirmed'] = false;
        }
        $this->expectedHash = null;
        $this->confirmed = false;
    }

    public function refreshPreview(): void
    {
        $share = $this->offer();
        $this->resetErrorBag();
        $this->confirmed = false;
        $this->expectedHash = null;
        if ($share->recipient_workspace_id === $this->workspaceId && $share->isPending() && $share->source_workspace_id !== null) {
            $this->display = app(FormulaSharePreview::class)->build($this->user(), $share, $this->decisions());
            $this->expectedHash = $this->display['expected_hash'];
            unset($this->display['expected_hash']);
        } elseif ($share->source_workspace_id === $this->workspaceId && $share->snapshot !== null) {
            $this->display = app(FormulaSharePresenter::class)->outgoing($share->snapshot);
        } else {
            $this->display = [];
        }
    }

    public function accept(): mixed
    {
        $share = $this->offer();
        if ($share->status !== FormulaShareStatus::Accepted && (! $share->isPending() || $share->source_workspace_id === null)) {
            $this->addError('sharing', __('sharing.validation.unavailable'));

            return null;
        }
        Gate::forUser($this->user())->authorize('accept', $share);
        if ($share->status !== FormulaShareStatus::Accepted && (! $this->confirmed || $this->expectedHash === null || ($this->display['remaining_keys'] ?? []) !== [])) {
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.confirm')]);
        }
        try {
            $product = app(AcceptFormulaShare::class)->handle($this->user(), $share, $this->decisions(), $this->expectedHash ?? '');
        } catch (ValidationException $exception) {
            $this->expectedHash = null;
            $this->confirmed = false;
            throw $exception;
        }

        return redirect()->route('recipes.saved', $product);
    }

    public function close(): void
    {
        $share = $this->offer();
        $status = $share->source_workspace_id === $this->workspaceId ? FormulaShareStatus::Revoked : FormulaShareStatus::Declined;
        app(CloseFormulaShare::class)->handle($this->user(), $share, $status);
        $this->display = [];
        $this->expectedHash = null;
        $this->confirmed = false;
    }

    public function render(): View
    {
        $share = $this->offer();

        return view('livewire.dashboard.formula-share-review', ['summary' => app(FormulaSharePresenter::class)->summary($share, $this->workspace())]);
    }

    private function offer(): FormulaShare
    {
        $this->workspace();
        $share = FormulaShare::query()->where('public_id', $this->sharePublicId)->firstOrFail();
        Gate::forUser($this->user())->authorize('view', $share);

        return $share;
    }

    /** @return array<string, string> */
    private function localIngredients(): array
    {
        $workspace = $this->workspace();

        return Ingredient::withoutGlobalScopes()->where('is_active', true)->where('workspace_id', $workspace->id)->where(fn ($query) => $query->where('owner_type', '!=', 'workspace')->orWhereNull('owner_type')->orWhere('owner_id', $workspace->id))
            ->orderBy('display_name')->get()->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->public_id => $ingredient->localizedDisplayName() ?? $ingredient->display_name ?? $ingredient->public_id])->all();
    }

    /** @return list<array<string, mixed>> */
    private function decisions(): array
    {
        $decisions = [];
        foreach ($this->choices as $key => $choice) {
            if (! is_array($choice) || array_diff(array_keys($choice), ['mode', 'ingredient_public_id', 'confirmed']) !== []) {
                throw ValidationException::withMessages(['choices' => __('sharing.validation.decisions')]);
            }
            $mode = $choice['mode'] ?? 'automatic';
            if ($mode === 'automatic') {
                continue;
            }
            if ($mode === 'substitute' && ($choice['confirmed'] ?? false) !== true) {
                throw ValidationException::withMessages(['choices' => __('sharing.validation.substitute_confirm')]);
            }
            $decision = ['key' => $key, 'mode' => $mode];
            if ($mode !== 'import') {
                $decision['ingredient_public_id'] = $choice['ingredient_public_id'] ?? null;
            }
            $decisions[] = $decision;
        }

        return $decisions;
    }
}
