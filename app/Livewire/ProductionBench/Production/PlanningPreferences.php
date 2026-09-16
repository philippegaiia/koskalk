<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveProductionBenchPreferences;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class PlanningPreferences extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->fillFromWorkspace($this->workspace());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('locations.planning_and_storage'))
                    ->compact()
                    ->schema([
                        TextInput::make('production_daily_limit')
                            ->label(__('locations.daily_production_limit'))
                            ->helperText(__('locations.daily_production_limit_help'))
                            ->required()
                            ->type('text')
                            ->inputMode('numeric'),
                        Toggle::make('uses_production_locations')
                            ->label(__('locations.uses_production_locations')),
                        Toggle::make('uses_storage_locations')
                            ->label(__('locations.uses_storage_locations')),
                    ])
                    ->columns(['md' => 2]),
            ])
            ->statePath('data');
    }

    public function save(SaveProductionBenchPreferences $savePreferences): void
    {
        try {
            /** @var array<string, mixed> $state */
            $state = $this->form->getState();
            $workspace = $savePreferences->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                usesProductionLocations: (bool) ($state['uses_production_locations'] ?? false),
                usesStorageLocations: (bool) ($state['uses_storage_locations'] ?? false),
                productionDailyLimit: $state['production_daily_limit'] ?? '',
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        $this->user()->forgetAccessibleWorkspaceIds();
        $this->fillFromWorkspace($workspace);
        $this->showAppNotification(__('locations.saved'));
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $role = $workspace->roleFor($this->user());
        $isBenchActive = $access->isActive($workspace);
        $isReadOnly = $access->isReadOnly($workspace);
        $canConfigure = in_array($role, [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
        $accessMessage = match (true) {
            $isReadOnly => __('locations.cancelled_read_only'),
            ! $isBenchActive => __('locations.inactive_read_only'),
            $canConfigure => null,
            $role === WorkspaceMemberRole::Editor => __('locations.editor_read_only'),
            default => __('locations.viewer_read_only'),
        };

        return view('livewire.production-bench.production.planning-preferences', [
            'isEditable' => $canConfigure && $isBenchActive && ! $isReadOnly,
            'accessMessage' => $accessMessage,
            'usesProductionLocations' => (bool) $workspace->uses_production_locations,
            'usesStorageLocations' => (bool) $workspace->uses_storage_locations,
        ]);
    }

    private function fillFromWorkspace(Workspace $workspace): void
    {
        $this->form->fill([
            'production_daily_limit' => (string) $workspace->production_daily_limit,
            'uses_production_locations' => (bool) $workspace->uses_production_locations,
            'uses_storage_locations' => (bool) $workspace->uses_storage_locations,
        ]);
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
