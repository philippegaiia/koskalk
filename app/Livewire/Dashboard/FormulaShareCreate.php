<?php

namespace App\Livewire\Dashboard;

use App\Actions\FormulaSharing\SendFormulaShare;
use App\Livewire\Concerns\InteractsWithFormulaSharingWorkspace;
use App\Models\Recipe;
use App\Services\ContextualHelp\ApplicationHelpTopics;
use App\Services\FormulaSharePresenter;
use App\Services\FormulaShareSnapshotBuilder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FormulaShareCreate extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithFormulaSharingWorkspace;

    #[Locked]
    public string $recipePublicId;

    #[Locked]
    public string $requestKey;

    #[Locked]
    public ?string $recipientName = null;

    #[Locked]
    public ?string $recipientAddress = null;

    #[Locked]
    public ?string $expectedHash = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $display = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $confirmed = false;

    public function mount(Recipe $recipe): void
    {
        $this->workspaceId = $this->workspace()->id;
        $this->recipePublicId = $recipe->public_id;
        $this->product();
        $this->requestKey = (string) Str::uuid();
        $this->form->fill(['recipient_address' => '', 'include_procedure' => false, 'include_description' => false, 'include_line_notes' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('recipient_address')->label(__('sharing.recipient_lookup'))->helperText(__('sharing.recipient_help'))->required()->maxLength(254),
            Toggle::make('include_procedure')->label(__('sharing.include_procedure')),
            Toggle::make('include_description')->label(__('sharing.include_description')),
            Toggle::make('include_line_notes')->label(__('sharing.include_line_notes')),
        ])->statePath('data');
    }

    public function updatedData(): void
    {
        $this->product();
        $this->recipientName = null;
        $this->recipientAddress = null;
        $this->expectedHash = null;
        $this->display = [];
        $this->confirmed = false;
        $this->requestKey = (string) Str::uuid();
    }

    public function resolveRecipient(): void
    {
        $builder = app(FormulaShareSnapshotBuilder::class);
        $data = $this->validatedData();
        $recipient = $builder->resolveRecipient($this->user(), $this->product(), $data['recipient_address']);
        $this->recipientName = $recipient->name;
        $this->recipientAddress = $recipient->public_id;
    }

    public function preview(): void
    {
        $this->resetErrorBag();
        $data = $this->validatedData();
        $builder = app(FormulaShareSnapshotBuilder::class);
        $product = $this->product();
        $recipient = $builder->resolveRecipient($this->user(), $product, $data['recipient_address']);
        $snapshot = $builder->build($this->user(), $product, $this->options($data));
        $this->recipientName = $recipient->name;
        $this->recipientAddress = $recipient->public_id;
        $this->display = app(FormulaSharePresenter::class)->outgoing($snapshot);
        $this->expectedHash = $builder->previewHash($snapshot, $recipient);
        $this->confirmed = false;
    }

    public function send(): mixed
    {
        $data = $this->validatedData();
        $product = $this->product();
        if (! $this->confirmed || $this->expectedHash === null) {
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.confirm')]);
        }
        $builder = app(FormulaShareSnapshotBuilder::class);
        try {
            $recipient = $builder->resolveRecipient($this->user(), $product, $data['recipient_address']);
            $share = app(SendFormulaShare::class)->handle($this->user(), $product, $recipient, $this->options($data), $this->expectedHash, $this->requestKey);
        } catch (ValidationException $exception) {
            $this->expectedHash = null;
            $this->confirmed = false;
            throw $exception;
        }

        return redirect()->route('formula-shares.show', $share);
    }

    public function render(): View
    {
        $product = $this->product();

        return view('livewire.dashboard.formula-share-create', ['productName' => $product->name, 'numberLocale' => $this->user()->number_locale,
            'contextualHelp' => app(ApplicationHelpTopics::class)->resolve('formula-share-create', app()->getLocale())]);
    }

    private function product(): Recipe
    {
        $workspace = $this->workspace();
        $product = Recipe::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('public_id', $this->recipePublicId)->firstOrFail();
        Gate::forUser($this->user())->authorize('share', $product);

        return $product;
    }

    /** @return array<string, mixed> */
    private function validatedData(): array
    {
        $this->product();
        if (array_diff(array_keys($this->data), ['recipient_address', 'include_procedure', 'include_description', 'include_line_notes']) !== []) {
            throw ValidationException::withMessages(['sharing' => __('sharing.validation.options')]);
        }
        app(FormulaShareSnapshotBuilder::class)->options($this->options($this->data));

        return $this->form->getState();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function options(array $data): array
    {
        unset($data['recipient_address']);

        return $data;
    }
}
