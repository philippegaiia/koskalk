<?php

use App\Services\IngredientShareFingerprint;
use Tests\TestCase;

uses(TestCase::class);

it('canonicalizes technical decimals object keys and unordered relations', function (): void {
    $first = ['schema_version' => 1, 'display' => ['display_name' => 'A'], 'technical' => [
        'sap_profile' => ['koh_sap_value' => '0.188000'],
        'allergens' => [['reference' => ['identity' => 'B', 'id' => 9], 'concentration_percent' => '1.20000'], ['reference' => ['identity' => 'A', 'id' => 3], 'concentration_percent' => '2.00000']],
    ]];
    $second = ['technical' => ['allergens' => array_reverse($first['technical']['allergens']), 'sap_profile' => ['koh_sap_value' => 0.188]], 'display' => ['display_name' => 'B'], 'schema_version' => 1];
    $second['technical']['allergens'][0]['reference']['id'] = 900;

    expect(app(IngredientShareFingerprint::class)->forProjection($first))
        ->toBe(app(IngredientShareFingerprint::class)->forProjection($second));
});

it('distinguishes unknown concentration from known zero', function (): void {
    $first = ['schema_version' => 1, 'technical' => ['substances' => [['concentration_percent' => null]]]];
    $second = ['schema_version' => 1, 'technical' => ['substances' => [['concentration_percent' => '0']]]];

    expect(app(IngredientShareFingerprint::class)->forProjection($first))
        ->not->toBe(app(IngredientShareFingerprint::class)->forProjection($second));
});

it('hashes trusted original chemistry and effective ordered children', function (string $field): void {
    $first = ['schema_version' => 1, 'technical' => ['baseline' => ['koh_sap_value' => '0.188'], 'components' => [['child_fingerprint' => 'first', 'percentage_in_parent' => '100', 'sort_order' => 1]]]];
    $second = $first;
    data_set($second, $field, $field === 'technical.baseline.koh_sap_value' ? '0.189' : 'changed');

    expect(app(IngredientShareFingerprint::class)->forProjection($first))
        ->not->toBe(app(IngredientShareFingerprint::class)->forProjection($second));
})->with(['technical.baseline.koh_sap_value', 'technical.components.0.child_fingerprint']);

it('rejects unsupported versions and locale formatted or nonfinite decimals', function (int $version, mixed $value): void {
    expect(fn () => app(IngredientShareFingerprint::class)->forProjection(['schema_version' => $version, 'technical' => ['sap_profile' => ['koh_sap_value' => $value]]]))
        ->toThrow(InvalidArgumentException::class);
})->with([[2, '0.188'], [1, '0,188'], [1, '1e999'], [1, NAN]]);

it('treats catalogue reference keys and declaration fallback names as technical identity', function (array $first, array $second): void {
    expect(app(IngredientShareFingerprint::class)->forProjection(['schema_version' => 1, 'technical' => $first]))
        ->not->toBe(app(IngredientShareFingerprint::class)->forProjection(['schema_version' => 1, 'technical' => $second]));
})->with([
    [['fatty_acids' => [['reference' => ['id' => 1, 'identity' => ['key' => 'oleic']], 'percentage' => '80']]], ['fatty_acids' => [['reference' => ['id' => 1, 'identity' => ['key' => 'lauric']], 'percentage' => '80']]]],
    [['declaration_fallback' => ['display_name' => 'A']], ['declaration_fallback' => ['display_name' => 'B']]],
]);
