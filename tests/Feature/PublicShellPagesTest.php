<?php

it('renders the public home page', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Clearer than SoapCalc')
        ->assertSee('Preview The App Shell');
});

it('renders the public dashboard shell page', function () {
    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Public shell direction')
        ->assertSee('Formulation workspace map');
});
