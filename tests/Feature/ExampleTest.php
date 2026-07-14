<?php

test('the application root redirects guests to login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
