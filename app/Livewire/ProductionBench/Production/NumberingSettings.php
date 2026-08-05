<?php

namespace App\Livewire\ProductionBench\Production;

use App\Actions\Production\SaveProductionRunNumberSettings;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionRunNumberService;
use App\Services\ProductionBenchAccess;
use App\WorkspaceMemberRole;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class NumberingSettings extends Component
{
    use InteractsWithAppNotifications;

    public string $permanentPrefix = '';

    public string $nextPermanentSerial = '';

    public string $permanentPadding = '';

    public string $permanentSuffix = '';

    public string $nextPlanningSerial = '';

    public string $example = '';

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function mount(ProductionRunNumberService $numbers): void
    {
        $settings = DB::transaction(
            fn (): ProductionRunNumberSetting => $numbers->lockWorkspaceAndSettings($this->workspace())[1],
            attempts: 5,
        );

        $this->fillSettings($settings, $numbers);
    }

    public function updatedPermanentPrefix(ProductionRunNumberService $numbers): void
    {
        $this->refreshExample($numbers);
    }

    public function updatedNextPermanentSerial(ProductionRunNumberService $numbers): void
    {
        $this->refreshExample($numbers);
    }

    public function updatedPermanentPadding(ProductionRunNumberService $numbers): void
    {
        $this->refreshExample($numbers);
    }

    public function updatedPermanentSuffix(ProductionRunNumberService $numbers): void
    {
        $this->refreshExample($numbers);
    }

    public function save(
        SaveProductionRunNumberSettings $saveNumberSettings,
        ProductionRunNumberService $numbers,
    ): void {
        try {
            $settings = $saveNumberSettings->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                prefix: $this->permanentPrefix,
                suffix: $this->permanentSuffix,
                padding: $this->permanentPadding,
                nextPermanentSerial: $this->nextPermanentSerial,
            );
        } catch (ValidationException $exception) {
            $this->surfaceErrors($exception);

            return;
        }

        $this->fillSettings($settings, $numbers);
        $this->showAppNotification(__('production_bench.settings.numbering_saved'));
    }

    public function render(ProductionBenchAccess $access): View
    {
        $workspace = $this->workspace();
        $canConfigure = in_array($workspace->roleFor($this->user()), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
        $isBenchActive = $access->isActive($workspace);
        $isReadOnly = $access->isReadOnly($workspace);
        $accessMessage = match (true) {
            $isReadOnly => __('production_bench.settings.numbering_cancelled_read_only'),
            ! $isBenchActive => __('production_bench.settings.numbering_inactive_read_only'),
            $canConfigure => null,
            $workspace->roleFor($this->user()) === WorkspaceMemberRole::Editor => __('production_bench.settings.numbering_editor_read_only'),
            default => __('production_bench.settings.numbering_viewer_read_only'),
        };

        return view('livewire.production-bench.production.numbering-settings', [
            'isEditable' => $canConfigure && $isBenchActive && ! $isReadOnly,
            'accessMessage' => $accessMessage,
        ]);
    }

    private function fillSettings(ProductionRunNumberSetting $settings, ProductionRunNumberService $numbers): void
    {
        $this->permanentPrefix = $settings->permanent_prefix;
        $this->nextPermanentSerial = (string) $settings->next_permanent_serial;
        $this->permanentPadding = (string) $settings->permanent_padding;
        $this->permanentSuffix = $settings->permanent_suffix;
        $this->nextPlanningSerial = (string) $settings->next_planning_serial;
        $this->refreshExample($numbers);
    }

    private function refreshExample(ProductionRunNumberService $numbers): void
    {
        $this->example = $numbers->formatPermanentNumber(
            $this->permanentPrefix,
            $this->positiveIntegerOrDefault($this->nextPermanentSerial),
            $this->permanentSuffix,
            $this->positiveIntegerOrDefault($this->permanentPadding),
        );
    }

    private function positiveIntegerOrDefault(string $value): int
    {
        return preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : 1;
    }

    private function surfaceErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $errorKey = match ($field) {
                'permanent_prefix' => 'permanentPrefix',
                'permanent_suffix' => 'permanentSuffix',
                'permanent_padding' => 'permanentPadding',
                'next_permanent_serial', 'batch_number', 'production_bench' => 'nextPermanentSerial',
                default => $field,
            };

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
