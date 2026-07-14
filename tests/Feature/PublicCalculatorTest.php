<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not expose the public calculator at launch', function () {
    $this->get('/calculator')->assertNotFound();
});

it('does not accept public calculator drafts at launch', function () {
    $this->post('/calculator/draft', [
        'product_family_slug' => 'soap',
        'draft' => str_repeat('x', 100_000),
    ])
        ->assertNotFound()
        ->assertSessionMissing('public_calculator.pending_formula');
});
