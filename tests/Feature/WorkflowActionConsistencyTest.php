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
    $costing = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php'));
    $output = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));

    expect($costing)
        ->toContain('<x-workflow-action-bar max-width="max-w-app" data-costing-save-bar>')
        ->toContain('@click="persistCosting()"')
        ->toContain('class="sk-btn sk-btn-primary"')
        ->and($output)
        ->toContain('@unless ($isPublicCalculator)')
        ->toContain('<x-workflow-action-bar max-width="max-w-app" data-output-save-bar>')
        ->toContain('@click="publish()"')
        ->toContain('class="sk-btn sk-btn-primary"');
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
