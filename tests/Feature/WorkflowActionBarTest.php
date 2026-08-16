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
        ->toContain('sk-workflow-action-bar')
        ->toContain('flex-nowrap')
        ->not->toContain('flex-wrap')
        ->toContain('backdrop-blur-md')
        ->toContain('bg-[color-mix(in_oklab,var(--color-panel)_80%,transparent)]')
        ->toContain('sk-btn sk-btn-danger')
        ->toContain('sk-btn sk-btn-ghost')
        ->toContain('sk-btn sk-btn-primary');

    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\.sk-workflow-action-bar\s*\{[^}]*position:\s*fixed;/s')
        ->toMatch('/\.sk-workflow-action-bar\s*\{[^}]*bottom:\s*0;/s')
        ->toContain('left: var(--app-sidebar-width, 0rem);');
});

it('right-aligns actions when no leading action is provided', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar>
            <button type="submit" class="sk-btn sk-btn-primary">Create</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('ml-auto flex min-w-0 flex-nowrap items-center justify-end gap-2')
        ->not->toContain('rounded-full');
});

it('allows the workflow surface width to match a wider workbench', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-workflow-action-bar max-width="max-w-7xl">
            <button type="submit" class="sk-btn sk-btn-primary">Save</button>
        </x-workflow-action-bar>
    BLADE);

    expect($html)
        ->toContain('max-w-7xl')
        ->not->toContain('max-w-5xl');
});
