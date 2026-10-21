<?php

return [
    'settings' => [
        'tab' => 'Product dossiers (DIP)',
        'heading' => 'Product information file settings',
        'description' => 'Used on every DIP this workspace produces. The Responsible Person is the company placing the products on the EU market.',
        'responsible_person' => 'Responsible Person',
        'responsible_person_name' => 'Legal name',
        'responsible_person_address' => 'Address',
        'responsible_person_siret' => 'SIRET',
        'manufacturer' => 'Manufacturing site',
        'packer' => 'Packing site',
        'site_name' => 'Name',
        'site_address' => 'Address',
        'site_help' => 'Leave empty when it is the Responsible Person.',
        'numbering_prefix' => 'DIP numbering prefix',
        'numbering_help' => 'Dossiers are numbered DIP.<prefix>.<product reference>, for example DIP.GA.007.',
        'assessor' => 'Safety assessor',
        'assessor_name' => 'Name',
        'assessor_address' => 'Address',
        'assessor_qualification' => 'Qualification',
        'save' => 'Save dossier settings',
        'saved' => 'Dossier settings saved.',
    ],
    'validation' => [
        'siret' => 'The SIRET must be 14 digits.',
        'numbering_prefix' => 'Use 1 to 8 capital letters or digits.',
    ],
];
