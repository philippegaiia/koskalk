<?php

use Illuminate\Support\Facades\Blade;

it('renders a compact sticky workflow surface with leading and trailing actions', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar data-example-save-bar>
            <x-slot:leading>
                <button type="button" class="sk-btn sk-btn-danger">Delete</button>
            </x-slot:leading>

            <a href="/cancel" class="sk-btn sk-btn-ghost">Cancel</a>
            <button type="submit" class="sk-btn sk-btn-primary">Save</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('data-workflow-action-bar')
        ->toContain('data-example-save-bar')
        ->toContain('fixed bottom-0 left-0 right-0')
        ->toContain('lg:left-[var(--app-sidebar-width,0rem)]')
        ->toContain('backdrop-blur-md')
        ->toContain('bg-[color-mix(in_oklab,var(--color-panel)_88%,transparent)]')
        ->toContain('sk-btn sk-btn-danger')
        ->toContain('sk-btn sk-btn-ghost')
        ->toContain('sk-btn sk-btn-primary');
});

it('right-aligns actions when no leading action is provided', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar>
            <button type="submit" class="sk-btn sk-btn-primary">Create</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('ml-auto flex flex-wrap items-center justify-end gap-2')
        ->not->toContain('rounded-full');
});
