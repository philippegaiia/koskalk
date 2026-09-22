<?php

// Read-only diagnostic: why are enrichment batch items stale?
// Usage: php artisan tinker --execute="require '/tmp/enrichment-stale-diff.php';" --  (or run directly)
//   php /tmp/enrichment-stale-diff.php <batch_id_or_public_id>

$root = $argv[2] ?? getcwd();
require $root.'/vendor/autoload.php';

$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;

$token = $argv[1] ?? null;
$batch = is_numeric($token)
    ? IngredientEnrichmentBatch::query()->find($token)
    : IngredientEnrichmentBatch::query()->where('public_id', $token)->first();
$batch ??= IngredientEnrichmentBatch::query()->orderByDesc('id')->first();

$snapshots = app(IngredientEnrichmentSnapshotBuilder::class);

$flatten = function (mixed $value, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ((array) $value as $key => $item) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($item)) {
            $out += $flatten($item, $path);
            continue;
        }
        $out[$path] = $item === null ? null : (string) $item;
    }
    return $out;
};

echo "batch {$batch->id} mode={$batch->mode?->value} status={$batch->status->value}\n";

foreach ($batch->items()->orderBy('id')->get() as $item) {
    $ingredient = $item->ingredient_id ? Ingredient::withoutGlobalScopes()->find($item->ingredient_id) : null;
    $current = $ingredient ? $snapshots->build($ingredient)['fingerprint'] : '(intake row)';
    $matches = $item->source_fingerprint === $current;

    printf(
        "\nitem %d  status=%s  ingredient=%s%s\n  request-time fp : %s\n  current fp      : %s  %s\n  approved_at=%s  ingredient.updated_at=%s\n  stored enrichment applied_at=%s\n",
        $item->id,
        $item->status->value,
        $ingredient?->id ?? '-',
        $ingredient ? ' ('.$ingredient->catalog_key.')' : '',
        $item->source_fingerprint,
        $current,
        $matches ? 'MATCH' : '<<< CHANGED',
        $item->approved_at?->toIso8601String() ?? '-',
        $ingredient?->updated_at?->toIso8601String() ?? '-',
        data_get($ingredient?->source_data, 'enrichment.core.applied_at') ?? '-',
    );

    if ($matches || ! $ingredient) {
        continue;
    }

    // Compare the snapshot captured when the request was created with today's state.
    $old = $flatten(((array) $item->snapshot)['current'] ?? []);
    $new = $flatten($snapshots->snapshot($ingredient));
    $shown = 0;
    foreach ($old as $path => $value) {
        if (! array_key_exists($path, $new)) {
            echo "    removed: {$path}\n";
            $shown++;
        } elseif ($new[$path] !== $value) {
            echo "    changed: {$path}\n        was: ".mb_substr((string) $value, 0, 90)."\n        now: ".mb_substr((string) $new[$path], 0, 90)."\n";
            $shown++;
        }
        if ($shown >= 12) {
            echo "    ... (truncated)\n";
            break;
        }
    }
    foreach ($new as $path => $value) {
        if (! array_key_exists($path, $old) && $shown < 12) {
            echo "    added:   {$path} = ".mb_substr((string) $value, 0, 90)."\n";
            $shown++;
        }
    }
}
