<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveProductionLocation;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProductionLocationManager extends Component implements HasForms
{
    use InteractsWithAppNotifications;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public ?string $editingLocationPublicId = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('locations.production_locations.form_title'))
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('locations.production_locations.name'))
                            ->required()
                            ->maxLength(50)
                            ->validationMessages(['max' => __('production_bench.validation.location_name_max')])
                            ->autocomplete('off'),
                        TextInput::make('daily_production_limit')
                            ->label(__('locations.production_locations.daily_limit'))
                            ->required()
                            ->type('text')
                            ->inputMode('numeric')
                            ->autocomplete('off'),
                        Toggle::make('is_active')
                            ->label(__('locations.production_locations.active'))
                            ->default(true),
                    ]),
            ])
            ->statePath('data')
            ->model(ProductionLocation::class);
    }

    public function save(SaveProductionLocation $saveLocation): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        try {
            $saveLocation->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: (string) ($state['name'] ?? ''),
                dailyProductionLimit: $state['daily_production_limit'] ?? '',
                isActive: (bool) ($state['is_active'] ?? true),
                location: $this->editingLocation(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->resetForm();
        $this->showAppNotification(__('locations.saved'));
    }

    public function edit(string $locationPublicId): void
    {
        $location = $this->workspaceLocationByPublicId($locationPublicId);

        $this->editingLocationPublicId = $location->public_id;
        $this->form->fill([
            'name' => $location->name,
            'daily_production_limit' => (string) $location->daily_production_limit,
            'is_active' => $location->is_active,
        ]);
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function archive(string $locationPublicId, SaveProductionLocation $saveLocation): void
    {
        $this->saveStateForLocation($locationPublicId, false, $saveLocation);
    }

    public function restore(string $locationPublicId, SaveProductionLocation $saveLocation): void
    {
        $this->saveStateForLocation($locationPublicId, true, $saveLocation);
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $isBenchActive = $access->isActive($workspace);
        $isReadOnly = $access->isReadOnly($workspace);
        $isEditable = $isBenchActive
            && ! $isReadOnly
            && in_array($workspace->roleFor($this->user()), [
                WorkspaceMemberRole::Owner,
                WorkspaceMemberRole::Admin,
                WorkspaceMemberRole::Editor,
            ], true);

        return view('livewire.production-bench.production.production-location-manager', [
            'accessMessage' => match (true) {
                $isReadOnly => __('locations.cancelled_read_only'),
                ! $isBenchActive => __('locations.inactive_read_only'),
                $isEditable => null,
                default => __('locations.manager_read_only'),
            },
            'isEditable' => $isEditable,
            'isReadOnly' => $isReadOnly,
            'locations' => ProductionLocation::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function saveStateForLocation(
        string $locationPublicId,
        bool $isActive,
        SaveProductionLocation $saveLocation,
    ): void {
        $location = $this->workspaceLocationByPublicId($locationPublicId);

        try {
            $saveLocation->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                name: $location->name,
                dailyProductionLimit: $location->daily_production_limit,
                isActive: $isActive,
                location: $location,
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        if ($this->editingLocationPublicId === $location->public_id) {
            $this->resetForm();
        }

        $this->showAppNotification(__('locations.saved'));
    }

    private function resetForm(): void
    {
        $this->editingLocationPublicId = null;
        $this->form->fill([
            'name' => '',
            'daily_production_limit' => '1',
            'is_active' => true,
        ]);
    }

    private function editingLocation(): ?ProductionLocation
    {
        return $this->editingLocationPublicId === null
            ? null
            : $this->workspaceLocationByPublicId($this->editingLocationPublicId);
    }

    private function workspaceLocationByPublicId(string $publicId): ProductionLocation
    {
        return ProductionLocation::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $errorKey = str_starts_with($field, 'data.') ? $field : 'data.'.$field;

            foreach ($messages as $message) {
                $this->addError($errorKey, $message);
            }
        }
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
