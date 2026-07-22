<?php

use Tests\Support\TestDatabaseSafety;

require_once dirname(__DIR__).'/vendor/autoload.php';

$cachedConfigurationPath = dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($cachedConfigurationPath)) {
    $cachedConfiguration = require $cachedConfigurationPath;

    if (! is_array($cachedConfiguration)) {
        throw new RuntimeException('Refusing to run tests because the Laravel configuration cache is invalid.');
    }

    TestDatabaseSafety::assertSafe($cachedConfiguration);
}
