<?php

use App\Services\Dossiers\Calculation\IngredientTracingVerifier;

/**
 * A small consistent ledger in round grams: oil 1000, alkali 100, water 400, juniper oil 5.
 *
 * @return array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: list<array<string, string>>, 3: list<array<string, string>>}
 */
function consistentLedger(): array
{
    $initial = [
        ['key' => 'oil', 'grams' => '1000'],
        ['key' => 'alkali', 'grams' => '100'],
        ['key' => 'water', 'grams' => '400'],
        ['key' => 'juniper', 'grams' => '5'],
    ];
    $contributions = [
        ['from' => 'oil', 'to' => 'salt', 'grams' => '800'],
        ['from' => 'oil', 'to' => 'glycerin', 'grams' => '150'],
        ['from' => 'oil', 'to' => 'unsaponified', 'grams' => '50'],
        ['from' => 'alkali', 'to' => 'salt', 'grams' => '100'],
        ['from' => 'water', 'to' => 'aqua', 'grams' => '200'],
        ['from' => 'juniper', 'to' => 'juniper', 'grams' => '5'],
    ];
    $losses = [['from' => 'water', 'kind' => 'evaporated', 'grams' => '200']];
    $final = collect(['salt' => '900', 'glycerin' => '150', 'unsaponified' => '50', 'aqua' => '200', 'juniper' => '5'])
        ->map(fn (string $grams, string $key): array => ['key' => $key, 'grams' => $grams, 'percent' => bcdiv(bcmul($grams, '100', 18), '1305', 18)])
        ->values()
        ->all();

    return [$initial, $contributions, $losses, $final];
}

it('accepts a ledger where every gram is accounted for', function (): void {
    $result = app(IngredientTracingVerifier::class)->verify(...consistentLedger());

    expect($result['passes'])->toBeTrue()
        ->and($result['gaps'])->toBe([]);
});

it('reports an initial ingredient that is not fully allocated', function (): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    $initial[3]['grams'] = '6';

    $result = app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final);

    expect($result['passes'])->toBeFalse()
        ->and(collect($result['gaps'])->pluck('type')->all())->toContain('unallocated_initial', 'mass_balance');
});

it('fails a composition shaped like the DIP.GA.007 workbook column J at any permitted tolerance', function (?string $tolerance): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    // The juniper row is rescaled as in column J (0.743 % instead of a consistent 0.4215 %) and the soap salt row absorbs
    // the difference, so the grams and the percentages still total exactly as before.
    $wrongJuniper = bcmul('5', bcdiv('0.743', '0.4215', 18), 18);
    $final = collect($final)->map(fn (array $row): array => match ($row['key']) {
        'juniper' => [...$row, 'grams' => $wrongJuniper, 'percent' => bcdiv(bcmul($wrongJuniper, '100', 18), '1305', 18)],
        'salt' => [...$row, 'grams' => bcsub('905', $wrongJuniper, 18), 'percent' => bcdiv(bcmul(bcsub('905', $wrongJuniper, 18), '100', 18), '1305', 18)],
        default => $row,
    })->all();

    $result = app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final, $tolerance);

    expect($result['passes'])->toBeFalse()
        ->and(collect($result['gaps'])->where('type', 'unexplained_final')->pluck('key')->sort()->values()->all())->toBe(['juniper', 'salt']);
})->with(['configured tolerance' => [null], 'the largest permitted tolerance' => ['0.01']]);

it('refuses a tolerance large enough to hide a displaced ingredient', function (string $tolerance): void {
    app(IngredientTracingVerifier::class)->verify(...[...consistentLedger(), $tolerance]);
})->with(['4 g' => ['4'], 'negative' => ['-0.001']])->throws(InvalidArgumentException::class);

it('reports percentages that do not match the grams, unknown keys and negative amounts', function (): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    $final[0]['percent'] = '70';
    $contributions[] = ['from' => 'ghost', 'to' => 'salt', 'grams' => '0'];
    $contributions[] = ['from' => 'oil', 'to' => 'nowhere', 'grams' => '-1'];

    $types = collect(app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final)['gaps'])->pluck('type');

    expect($types->all())->toContain('percent_mismatch', 'percent_total', 'unknown_initial', 'unknown_final', 'negative_amount');
});
