<?php

namespace App\Livewire\Dashboard;

use App\Enums\ProductionOutputType;
use App\Livewire\Concerns\InteractsWithFormulaSharingWorkspace;
use App\Models\FormulaShare;
use App\Models\Recipe;
use App\Services\ContextualHelp\ApplicationHelpTopics;
use App\Services\FormulaSharePresenter;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FormulaSharesIndex extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithFormulaSharingWorkspace;
    use WithPagination;

    #[Url]
    public string $direction = 'inbox';

    #[Url]
    public int $perPage = 25;

    public function mount(): void
    {
        $this->workspaceId = $this->workspace()->id;
    }

    public function shareFormulaAction(): Action
    {
        return Action::make('shareFormula')->label(__('sharing.start'))->modalHeading(__('sharing.choose_product'))
            ->modalSubmitActionLabel(__('sharing.continue'))
            ->schema([
                Select::make('product')->label(__('sharing.product'))->required()->searchable()
                    ->helperText(__('sharing.picker_help'))
                    ->options(fn (): array => $this->productOptions(''))
                    ->getSearchResultsUsing(fn (string $search): array => $this->productOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->shareableProducts()->where('public_id', $value)->first()?->name),
            ])->action(function (array $data): mixed {
                $product = $this->shareableProducts()->where('public_id', $data['product'])->first();
                if ($product === null) {
                    throw ValidationException::withMessages(['product' => __('sharing.validation.product')]);
                }
                Gate::forUser($this->user())->authorize('share', $product);

                return redirect()->route('formula-shares.create', $product);
            });
    }

    private function shareableProducts(): Builder
    {
        $workspace = $this->workspace();

        return Recipe::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereNull('archived_at')
            ->where('production_output_type', ProductionOutputType::FinishedProduct)
            ->whereHas('publishedVersions', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('workspace_id', $workspace->id));
    }

    /** @return array<string, string> */
    private function productOptions(string $search): array
    {
        return $this->shareableProducts()->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(mb_substr(trim($search), 0, 120)).'%'])
            ->with(['productFamily', 'latestPublishedVersion'])->orderBy('name')->orderBy('id')->limit(50)->get()
            ->mapWithKeys(fn (Recipe $product): array => [$product->public_id => $product->name.' · '.($product->productFamily?->name ?? $product->productFamily?->slug).' · '.__('sharing.saved_date', ['date' => $product->latestPublishedVersion?->saved_at?->format('Y-m-d') ?? '—'])])->all();
    }

    public function updatedDirection(): void
    {
        $this->workspace();
        $this->resetPage();
        if (! in_array($this->direction, ['inbox', 'outbox'], true)) {
            $this->direction = 'inbox';
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.filter')]);
        }
    }

    public function updatedPerPage(): void
    {
        $this->workspace();
        $this->resetPage();
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 25;
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.filter')]);
        }
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        if (! in_array($this->direction, ['inbox', 'outbox'], true) || ! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->direction = 'inbox';
            $this->perPage = 25;
            $this->addError('sharing', __('sharing.validation.filter'));
        }
        $offers = FormulaShare::query()->where($this->direction === 'inbox' ? 'recipient_workspace_id' : 'source_workspace_id', $workspace->id)
            ->latest('id')->paginate($this->perPage);
        $offers->through(fn (FormulaShare $share): array => app(FormulaSharePresenter::class)->summary($share, $workspace));

        return view('livewire.dashboard.formula-shares-index', ['offers' => $offers, 'workspaceAddress' => $workspace->public_id,
            'contextualHelp' => app(ApplicationHelpTopics::class)->resolve('formula-sharing', app()->getLocale())]);
    }
}
