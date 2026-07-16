<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the application root redirects guests to login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
