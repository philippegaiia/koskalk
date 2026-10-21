<?php

use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function linaloolProfileData(): array
{
    return [
        'noael_mg_kg_bw_day' => '117',
        'noael_route' => 'oral',
        'noael_species' => 'rat',
        'dermal_absorption_percent' => '14.4',
        'adi_mg_kg_bw_day' => '0.5',
        'adi_reasoning' => 'JECFA group ADI applies to the substance as used.',
        'nesil_ug_cm2' => '15000',
        'references' => [
            ['number' => 1, 'citation' => 'OECD SIDS Linalool, 2002', 'url' => null],
            ['number' => 2, 'citation' => 'IFRA QRA2 restricted standards', 'url' => null],
            ['number' => 3, 'citation' => 'JECFA 84th meeting, 2017', 'url' => null],
        ],
        'value_references' => [
            'noael_mg_kg_bw_day' => '1',
            'dermal_absorption_percent' => '1',
            'adi_mg_kg_bw_day' => '3',
            'nesil_ug_cm2' => '2',
        ],
    ];
}

it('publishes successive versions and keeps only the latest current', function (): void {
    $substance = Substance::factory()->create(['name' => 'Linalool']);
    $admin = User::factory()->admin()->create();
    $publisher = app(ToxicologyProfilePublisher::class);

    $first = $publisher->publish($substance, linaloolProfileData(), $admin);
    $second = $publisher->publish($substance, [...linaloolProfileData(), 'nesil_ug_cm2' => '14000'], $admin);

    expect($first->refresh()->is_current)->toBeFalse()
        ->and($first->superseded_at)->not->toBeNull()
        ->and($second->version)->toBe(2)
        ->and($second->is_current)->toBeTrue()
        ->and($second->published_by_user_id)->toBe($admin->id)
        ->and($second->value_references['adi_mg_kg_bw_day'])->toBe([3])
        ->and($substance->currentToxicologyProfile()->first()?->is($second))->toBeTrue();
});

it('refuses a value without a source reference', function (): void {
    $data = linaloolProfileData();
    unset($data['value_references']['nesil_ug_cm2']);

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->throws(ValidationException::class);

it('refuses a value pointing at an unknown reference number', function (): void {
    $data = linaloolProfileData();
    $data['value_references']['nesil_ug_cm2'] = '9';

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->throws(ValidationException::class);

it('refuses an ADI or TTC without applicability reasoning', function (string $field, array $extra): void {
    $data = [...linaloolProfileData(), ...$extra];
    $data[$field] = null;

    app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), $data, User::factory()->admin()->create());
})->with([
    'adi' => ['adi_reasoning', []],
    'ttc' => ['ttc_reasoning', ['ttc_mg_kg_bw_day' => '0.0025', 'ttc_class' => 'Cramer III', 'value_references' => [...linaloolProfileData()['value_references'], 'ttc_mg_kg_bw_day' => '1']]],
])->throws(ValidationException::class);

it('never edits or deletes a published version', function (): void {
    $profile = app(ToxicologyProfilePublisher::class)
        ->publish(Substance::factory()->create(), linaloolProfileData(), User::factory()->admin()->create());

    expect(fn () => $profile->update(['nesil_ug_cm2' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $profile->delete())->toThrow(LogicException::class);

    expect(ToxicologyProfile::query()->sole()->nesil_ug_cm2)->toBe('15000.000000');
});

it('rejects out-of-range toxicological values', function (string $field, string $value): void {
    try {
        app(ToxicologyProfilePublisher::class)->publish(Substance::factory()->create(), [...linaloolProfileData(), $field => $value], User::factory()->admin()->create());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'dermal absorption above 100%' => ['dermal_absorption_percent', '101'],
    'zero NOAEL' => ['noael_mg_kg_bw_day', '0'],
    'negative NESIL' => ['nesil_ug_cm2', '-5'],
]);
