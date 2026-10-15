<?php

use App\Services\SoapCalculationService;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FattyAcidSeeder::class);
});

/**
 * Runs the browser lye preview from calculation.js on a workbench state.
 *
 * @param  array<string, mixed>  $state
 * @return array<string, float>
 */
function browserLyeBreakdown(array $state): array
{
    $script = <<<'JS'
import { register } from 'node:module';
import { pathToFileURL } from 'node:url';

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, nextResolve) {
            if (specifier.startsWith('.') && !specifier.endsWith('.js')) {
                try {
                    return await nextResolve(specifier, context);
                } catch {
                    return nextResolve(specifier + '.js', context);
                }
            }

            return nextResolve(specifier, context);
        }
    `),
    pathToFileURL(`${process.cwd()}/`).href,
);

const { lyeBreakdown } = await import('./resources/js/recipe-workbench/calculation.js');
const breakdown = lyeBreakdown(JSON.parse(process.env.WORKBENCH_STATE));
delete breakdown.fatty_acids;
process.stdout.write(JSON.stringify(breakdown));
JS;

    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path(), [
        'WORKBENCH_STATE' => json_encode($state, JSON_THROW_ON_ERROR),
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

dataset('parity formulas', [
    'NaOH bar, 5% superfat, water as % of oils' => [[
        'lyeType' => 'naoh', 'superfat' => 5, 'dualKohPercentage' => 0, 'kohPurity' => 100,
        'waterMode' => 'percent_of_oils', 'waterValue' => 38,
    ]],
    'KOH 90% liquid, 3% superfat, water by lye ratio' => [[
        'lyeType' => 'koh', 'superfat' => 3, 'dualKohPercentage' => 0, 'kohPurity' => 90,
        'waterMode' => 'lye_ratio', 'waterValue' => 3,
    ]],
    'dual lye 30% KOH, 6% superfat, water by lye concentration' => [[
        'lyeType' => 'dual', 'superfat' => 6, 'dualKohPercentage' => 30, 'kohPurity' => 100,
        'waterMode' => 'lye_concentration', 'waterValue' => 30,
    ]],
    'NaOH bar, negative superfat' => [[
        'lyeType' => 'naoh', 'superfat' => -3, 'dualKohPercentage' => 0, 'kohPurity' => 100,
        'waterMode' => 'lye_concentration', 'waterValue' => 33,
    ]],
]);

/*
 * Covers formulas without saponifiable Additives. The browser preview ignores
 * post-reaction oils, so with a negative superfat and such oils its interim
 * glycerine is lower than the engine's until the server result replaces it.
 */
it('computes the same lye, water and glycerine in the browser preview and the soap engine', function (array $settings): void {
    $oilWeight = 800;
    $oilRows = [
        ['percentage' => 50, 'koh_sap_value' => 0.188],
        ['percentage' => 30, 'koh_sap_value' => 0.257],
        // Professional mg KOH/g notation is normalized identically on both sides.
        ['percentage' => 20, 'koh_sap_value' => 180],
    ];

    $engine = (new SoapCalculationService)->calculate(
        array_map(fn (array $row): array => [
            'weight' => $oilWeight * $row['percentage'] / 100,
            'koh_sap_value' => $row['koh_sap_value'],
        ], $oilRows),
        [
            'superfat' => $settings['superfat'],
            'lye_type' => $settings['lyeType'],
            'dual_lye_koh_percentage' => $settings['dualKohPercentage'],
            'koh_purity_percentage' => $settings['kohPurity'],
            'water_mode' => $settings['waterMode'],
            'water_value' => $settings['waterValue'],
        ],
    );

    $browser = browserLyeBreakdown([...$settings, 'oilWeight' => $oilWeight, 'oilRows' => $oilRows]);

    // The engine rounds to four decimals.
    $tolerance = 0.0001;

    expect($browser['naoh_theoretical'])->toEqualWithDelta($engine['lye']['naoh']['theoretical'], $tolerance)
        ->and($browser['koh_theoretical'])->toEqualWithDelta($engine['lye']['koh']['theoretical'], $tolerance)
        ->and($browser['selected_naoh_weight'])->toEqualWithDelta($engine['lye']['selected']['naoh_weight'], $tolerance)
        ->and($browser['selected_koh_weight'])->toEqualWithDelta($engine['lye']['selected']['koh_weight'], $tolerance)
        ->and($browser['koh_to_weigh'])->toEqualWithDelta($engine['lye']['selected']['koh_to_weigh'], $tolerance)
        ->and($browser['water_weight'])->toEqualWithDelta($engine['lye']['water']['weight'], $tolerance)
        ->and($browser['glycerine_weight'])->toEqualWithDelta($engine['lye']['selected']['glycerine_weight'], $tolerance);
})->with('parity formulas');
