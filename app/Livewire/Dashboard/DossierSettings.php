<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dossiers\SaveDossierSettings;
use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use App\Services\Dossiers\DossierSettingsService;
use App\Services\WorkspaceAuthorization;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DossierSettings extends Component
{
    #[Locked]
    public int $workspaceId;

    public string $responsible_person_name = '';

    public string $responsible_person_address = '';

    public ?string $responsible_person_siret = null;

    public ?string $manufacturer_name = null;

    public ?string $manufacturer_address = null;

    public ?string $packer_name = null;

    public ?string $packer_address = null;

    public string $numbering_prefix = '';

    public ?string $assessor_name = null;

    public ?string $assessor_address = null;

    public ?string $assessor_qualification = null;

    public string $status = '';

    public function mount(DossierSettingsService $settings): void
    {
        $workspace = $this->workspace();
        $this->workspaceId = $workspace->id;

        $existing = $settings->forWorkspace($workspace);

        if ($existing instanceof WorkspaceDossierSetting) {
            $this->fill($existing->only(WorkspaceDossierSetting::EDITABLE));
        }
    }

    public function save(SaveDossierSettings $saveDossierSettings): void
    {
        $validated = $this->validate([
            'responsible_person_name' => ['required', 'string', 'max:255'],
            'responsible_person_address' => ['required', 'string', 'max:1000'],
            'responsible_person_siret' => ['nullable', 'regex:/^\d{14}$/'],
            'manufacturer_name' => ['nullable', 'string', 'max:255'],
            'manufacturer_address' => ['nullable', 'string', 'max:1000'],
            'packer_name' => ['nullable', 'string', 'max:255'],
            'packer_address' => ['nullable', 'string', 'max:1000'],
            'numbering_prefix' => ['required', 'regex:/^[A-Z0-9]{1,8}$/'],
            'assessor_name' => ['nullable', 'string', 'max:255'],
            'assessor_address' => ['nullable', 'string', 'max:1000'],
            'assessor_qualification' => ['nullable', 'string', 'max:2000'],
        ], [
            'responsible_person_siret.regex' => __('dossiers.validation.siret'),
            'numbering_prefix.regex' => __('dossiers.validation.numbering_prefix'),
        ]);

        $workspace = Workspace::withoutGlobalScopes()->findOrFail($this->workspaceId);
        $saveDossierSettings->handle($this->user(), $workspace, $validated);

        $this->status = __('dossiers.settings.saved');
    }

    public function render(): View
    {
        return view('livewire.dashboard.dossier-settings');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function workspace(): Workspace
    {
        $workspace = $this->user()->company(fresh: true);

        abort_unless(
            $workspace instanceof Workspace
                && app(WorkspaceAuthorization::class)->canEditModule($this->user(), $workspace->id, WorkspaceModule::ProductDossiers),
            403,
        );

        return $workspace;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'responsible_person_name' => __('dossiers.settings.responsible_person_name'),
            'responsible_person_address' => __('dossiers.settings.responsible_person_address'),
            'responsible_person_siret' => __('dossiers.settings.responsible_person_siret'),
            'numbering_prefix' => __('dossiers.settings.numbering_prefix'),
        ];
    }
}
