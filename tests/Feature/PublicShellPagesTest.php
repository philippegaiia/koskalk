<?php

it('renders the public home page', function () {
    $this->get(route('home'))
        ->assertSuccessful();
});

it('renders the public dashboard shell page', function () {
    $this->get(route('dashboard'))
        ->assertSuccessful();
});
