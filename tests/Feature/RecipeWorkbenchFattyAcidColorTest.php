<?php

it('colors each fatty acid detail bar with its grouped profile color', function () {
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));
    $fattyAcidProfile = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php'));

    expect($presentationSection)
        ->toContain('this.fattyAcidGroupColorFor(key)')
        ->and($presentationSection)->toContain("caprylic: 'vs'")
        ->and($presentationSection)->toContain("lauric: 'vs'")
        ->and($presentationSection)->toContain("palmitic: 'hs'")
        ->and($presentationSection)->toContain("oleic: 'mu'")
        ->and($presentationSection)->toContain("linoleic: 'pu'")
        ->and($presentationSection)->toContain("ricinoleic: 'sp'")
        ->and($fattyAcidProfile)->toContain('fattyAcidRowBarStyle(row.value, row.color)');
});
