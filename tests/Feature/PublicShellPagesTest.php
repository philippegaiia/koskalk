<?php

it('renders the public home page', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Fast drafting, clean chemistry, and a compliance-ready handoff.')
        ->assertSee('Preview workspace');
});

it('renders the public dashboard shell page', function () {
    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Create formulas, keep one working draft, and reuse saved versions without losing clarity.')
        ->assertSee('Saved recipes');
});
