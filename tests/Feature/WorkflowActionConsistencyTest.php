<?php

it('uses rectangular mapped actions throughout the recipe workbench', function (): void {
    $instructions = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php'));
    $packaging = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php'));
    $packagingModal = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/packaging-catalog-modal.blade.php'));

    expect($instructions)
        ->toContain('<x-workflow-action-bar max-width="max-w-app" data-instructions-save-bar>')
        ->toContain('class="sk-btn sk-btn-primary"')
        ->not->toContain('rounded-full')
        ->and($packaging)
        ->toContain('class="sk-btn sk-btn-primary"')
        ->toContain('<x-workflow-action-bar max-width="max-w-app" data-packaging-plan-save-bar>')
        ->not->toContain('rounded-full')
        ->and($packagingModal)
        ->toContain('class="sk-btn sk-btn-ghost"')
        ->toContain('class="sk-btn sk-btn-outline"')
        ->toContain('class="sk-btn sk-btn-primary"')
        ->not->toContain('rounded-full');
});

it('provides bottom workflow actions for costing and label output', function (): void {
    $costing = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();
    $output = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isPublicCalculator' => false,
    ])->render();

    expect($costing)->toContain('data-costing-save-bar', '@click="persistCosting()"', 'sk-btn sk-btn-primary');
    expect($output)->toContain('data-output-save-bar', '@click="publish()"', 'sk-btn sk-btn-primary');
});

it('shows printing instead of account saving in guest label output', function (): void {
    $guestOutput = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isPublicCalculator' => true, 'isGuestCalculator' => true,
    ])->render();
    $legacyPublicOutput = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isPublicCalculator' => true,
    ])->render();

    expect($guestOutput)->toContain('data-guest-output-action-bar', '@click="printGuestFormula()"')
        ->not->toContain('data-output-save-bar', '@click="publish()"');
    expect($legacyPublicOutput)->not->toContain('data-output-save-bar', '@click="publish()"', 'data-guest-output-action-bar');
});

it('uses rectangular primary actions for inventory and settings', function (): void {
    $inventory = file_get_contents(resource_path('views/livewire/production-bench/inventory-index.blade.php'));
    $settings = file_get_contents(resource_path('views/livewire/dashboard/settings-index.blade.php'));

    expect($inventory)
        ->toContain('{{ $this->addStockAction }}')
        ->not->toContain('class="rounded-full bg-[var(--color-accent)] px-5 py-2.5')
        ->and(substr_count($settings, 'class="sk-btn sk-btn-primary"'))
        ->toBe(2)
        ->and($settings)
        ->not->toContain('class="rounded-full bg-[var(--color-accent)] px-5 py-2.5');
});
