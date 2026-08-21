<?php

test('the Vite development host matches the secured Herd certificate', function () {
    $configuration = file_get_contents(dirname(__DIR__, 2).'/vite.config.js');

    expect($configuration)
        ->toContain("const devServerHost = 'koskalk.test';")
        ->not->toContain("const devServerHost = 'localhost';");
});
