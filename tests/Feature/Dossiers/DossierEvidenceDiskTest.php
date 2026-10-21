<?php

/**
 * Evaluates config/dossiers.php against the given environment, then restores every environment source.
 *
 * @param  array<string, string|null>  $environment
 */
function evidenceDiskWith(array $environment): string
{
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        unset($_ENV[$key], $_SERVER[$key]);
        $value === null ? putenv($key) : putenv("{$key}={$value}");
    }

    try {
        return (require config_path('dossiers.php'))['evidence_disk'];
    } finally {
        foreach ($previous as $key => [$process, $env, $server]) {
            $process === false ? putenv($key) : putenv("{$key}={$process}");

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }
}

it('stores DIP evidence on the media asset disk unless a dedicated disk is set', function (): void {
    expect(evidenceDiskWith(['DOSSIER_EVIDENCE_DISK' => null, 'MEDIA_ASSET_DISK' => 'r2_private', 'RECIPE_MEDIA_DISK' => null]))->toBe('r2_private')
        ->and(evidenceDiskWith(['DOSSIER_EVIDENCE_DISK' => 'evidence_vault', 'MEDIA_ASSET_DISK' => 'r2_private', 'RECIPE_MEDIA_DISK' => null]))->toBe('evidence_vault')
        ->and(evidenceDiskWith(['DOSSIER_EVIDENCE_DISK' => null, 'MEDIA_ASSET_DISK' => null, 'RECIPE_MEDIA_DISK' => 'r2_private']))->toBe('r2_private')
        ->and(evidenceDiskWith(['DOSSIER_EVIDENCE_DISK' => null, 'MEDIA_ASSET_DISK' => null, 'RECIPE_MEDIA_DISK' => null]))->toBe('local');
});
