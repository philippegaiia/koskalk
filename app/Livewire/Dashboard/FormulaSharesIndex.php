<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\InteractsWithFormulaSharingWorkspace;
use App\Models\FormulaShare;
use App\Services\FormulaSharePresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FormulaSharesIndex extends Component
{
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

        return view('livewire.dashboard.formula-shares-index', ['offers' => $offers, 'workspaceAddress' => $workspace->public_id]);
    }
}
