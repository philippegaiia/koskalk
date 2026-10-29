<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\IngredientSapProfile;
use App\Models\IngredientTranslation;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\PublicCalculatorCatalog;
use Carbon\CarbonImmutable;
use Database\Seeders\SupportedLocaleSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function guestCalculatorOil(array $attributes = []): Ingredient
{
    $oil = Ingredient::factory()->create([
        'display_name' => 'Guest Olive Oil', 'inci_name' => 'Olea Europaea Fruit Oil',
        'soap_inci_naoh_name' => 'Sodium Olivate', 'soap_inci_koh_name' => 'Potassium Olivate',
        'category' => IngredientCategory::Lipids, 'is_soap_saponification_trusted' => true,
        'requires_admin_review' => false, 'is_active' => true,
        'owner_type' => null, 'owner_id' => null, 'workspace_id' => null, ...$attributes,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);

    return $oil;
}

function guestCalculatorDraft(Ingredient $oil, string $calculator = 'solid-soap'): array
{
    return [
        'name' => 'My guest trial', 'notes' => 'Stir gently.', 'oil_unit' => 'g', 'oil_weight' => 1000,
        'editing_mode' => 'percentage', 'lye_type' => $calculator === 'liquid-soap' ? 'koh' : 'naoh',
        'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40,
        'water_mode' => 'lye_concentration', 'water_value' => 28, 'superfat' => 5, 'cured_water_percentage' => 11,
        'phases' => [['key' => $calculator === 'cosmetics' ? 'phase_a' : 'saponified_oils', 'name' => 'Main phase']],
        'phase_items' => [
            $calculator === 'cosmetics' ? 'phase_a' : 'saponified_oils' => [[
                'id' => 'oil-1', 'ingredient_id' => $oil->id, 'name' => 'Untrusted client name',
                'percentage' => 100, 'weight' => 1000, 'note' => null,
            ]],
            ...($calculator === 'cosmetics' ? [] : ['additives' => [], 'fragrance' => [], 'neutralization' => []]),
        ],
        ...($calculator === 'liquid-soap' ? ['liquid_process' => [
            'first_dilution_percentage' => 35, 'final_dilution_percentage' => 25,
            'neutralizer_solution_percentage' => 20, 'measured_paste_weight' => null,
        ]] : []),
    ];
}

beforeEach(function (): void {
    ProductFamily::factory()->create(['slug' => 'soap', 'calculation_basis' => 'initial_oils', 'is_active' => true]);
    ProductFamily::factory()->create(['slug' => 'cosmetic', 'calculation_basis' => 'total_formula', 'is_active' => true]);
    ProductFamily::factory()->liquidSoapPaste()->create(['is_active' => true]);
});

it('calculates each guest family without saving records', function (string $calculator): void {
    $oil = guestCalculatorOil();
    $this->postJson("/calculators/{$calculator}/preview", ['draft' => guestCalculatorDraft($oil, $calculator)])
        ->assertOk()->assertJsonPath('valid', true)
        ->assertJsonPath('draft.phase_items.'.($calculator === 'cosmetics' ? 'phase_a' : 'saponified_oils').'.0.name', 'Guest Olive Oil')
        ->assertJsonPath('document.identity.name', 'My guest trial');
    expect(Recipe::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecipeVersion::withoutGlobalScopes()->count())->toBe(0)->and(Ingredient::count())->toBe(1);
})->with(['solid-soap', 'liquid-soap', 'cosmetics']);

it('counts temporary additions in liquid dilution and keeps their labeling gaps', function (): void {
    $oil = guestCalculatorOil();
    $draft = guestCalculatorDraft($oil, 'liquid-soap');
    $draft['phase_items']['additives'][] = ['id' => 'temp', 'ingredient_id' => null, 'name' => 'Mystery blend', 'percentage' => 5, 'weight' => 50];
    $response = $this->postJson('/calculators/liquid-soap/preview', ['draft' => $draft]);
    $response->assertOk()->assertJsonPath('labeling.incomplete', true);
    expect($response->json('labeling.text'))->toContain('[Custom ingredient: Mystery blend');
    $withTemporary = $response->json('document.liquid_process.steps.3.add');
    $draft['phase_items']['additives'] = [];
    $without = $this->postJson('/calculators/liquid-soap/preview', ['draft' => $draft])->assertOk();
    expect((float) $without->json('document.liquid_process.steps.3.add') - (float) $withTemporary)->toEqualWithDelta(50, 0.001);
});

it('rejects private identifiers even for their signed-in owner', function (): void {
    $user = User::factory()->create();
    $oil = guestCalculatorOil(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
    $this->actingAs($user)->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($oil)])
        ->assertUnprocessable()->assertJsonValidationErrors('draft.phase_items');
});

it('rejects temporary oils and untrusted soap chemistry', function (bool $temporary): void {
    $oil = guestCalculatorOil(['is_soap_saponification_trusted' => false]);
    $draft = guestCalculatorDraft($oil);
    if ($temporary) {
        $draft['phase_items']['saponified_oils'][0]['ingredient_id'] = null;
    }
    $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])
        ->assertUnprocessable()->assertJsonValidationErrors('draft.phase_items');
})->with([true, false]);

it('requires a complete calculation basis', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    $draft['phase_items']['saponified_oils'][0]['percentage'] = 90;
    $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])
        ->assertUnprocessable()->assertJsonValidationErrors('draft.phase_items');
});

it('warns when reference chemistry changed and recalculates it', function (): void {
    $oil = guestCalculatorOil();
    $draft = guestCalculatorDraft($oil);
    $first = $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertOk();
    $fingerprint = $first->json("references.{$oil->id}.fingerprint");
    $draft['phase_items']['saponified_oils'][0]['fingerprint'] = $fingerprint;
    $oil->sapProfile->update(['koh_sap_value' => 0.20]);
    $next = $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertOk();
    expect($next->json("references.{$oil->id}.fingerprint"))->not->toBe($fingerprint)
        ->and(implode(' ', $next->json('warnings')))->toContain('recalculated');
});

it('refuses retired references and unsupported client fields', function (string $reason): void {
    $oil = guestCalculatorOil();
    $draft = guestCalculatorDraft($oil);
    if ($reason === 'retired') {
        $oil->update(['is_active' => false]);
    } else {
        $draft['workspace_id'] = 123;
    }
    $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertUnprocessable();
})->with(['retired', 'unexpected']);

it('keeps inactive liquid soap behind its release gate', function (): void {
    ProductFamily::where('slug', 'liquid-soap')->update(['is_active' => false]);
    $this->get('/calculators/liquid-soap')->assertNotFound();
    $this->postJson('/calculators/liquid-soap/preview', ['draft' => guestCalculatorDraft(guestCalculatorOil(), 'liquid-soap')])->assertNotFound();
});

it('prints the complete guest trial as escaped text without saving', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    $draft['phase_items']['additives'][] = ['id' => 'temp', 'ingredient_id' => null, 'name' => '<script>bad()</script>', 'percentage' => 2, 'weight' => 20];
    $this->post('/calculators/solid-soap/print', ['draft' => json_encode($draft)])
        ->assertOk()->assertSeeText('Stir gently.')->assertSee('Allergen declarations not checked')
        ->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false);
    expect(Recipe::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps chemistry warnings and temporary liquid process names in the document', function (): void {
    $oil = guestCalculatorOil();
    $draft = guestCalculatorDraft($oil, 'liquid-soap');
    $draft['superfat'] = -3;
    $draft['phase_items']['additives'][] = ['id' => 'temp', 'ingredient_id' => null, 'name' => 'Unlisted extract', 'percentage' => 2, 'weight' => 20];
    $response = $this->postJson('/calculators/liquid-soap/preview', ['draft' => $draft])->assertOk();
    expect(implode(' ', $response->json('warnings')))->toContain(__('workbench.qualities.negative_superfat_help'))
        ->and($response->json('document.liquid_process.additions.0.name'))->toContain('Unlisted extract');
});

it('keeps every zero amount row and full liquid settings on its printable document', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil(), 'liquid-soap');
    $draft['phase_items']['additives'][] = ['id' => 'zero', 'ingredient_id' => null, 'name' => 'Trial alternative', 'percentage' => 0, 'weight' => 0];
    $response = $this->postJson('/calculators/liquid-soap/preview', ['draft' => $draft])->assertOk();
    $rows = collect($response->json('document.sections'))->pluck('rows')->flatten(1);
    expect($rows->pluck('name')->implode(' '))->toContain('Trial alternative')
        ->and(collect($response->json('document.settings'))->pluck('label')->all())->toContain(__('calculators.first_dilution'), __('calculators.final_dilution'), __('calculators.neutralizer_solution'));
});

it('rejects malformed or excessive guest payloads before calculation', function (string $case): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    if ($case === 'large') {
        $draft['notes'] = str_repeat('x', 140000);
    } elseif ($case === 'many') {
        $draft['phase_items']['saponified_oils'] = array_fill(0, 101, $draft['phase_items']['saponified_oils'][0]);
    } else {
        $draft['cured_water_percentage'] = 40;
    }
    $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertUnprocessable();
})->with(['large', 'many', 'cured']);

it('does not expose a platform blend with private component dependencies', function (): void {
    $private = guestCalculatorOil(['owner_type' => OwnerType::User, 'owner_id' => User::factory()->create()->id]);
    $blend = guestCalculatorOil();
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $private->id, 'percentage_in_parent' => 100]);
    $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($blend)])
        ->assertUnprocessable()->assertJsonValidationErrors('draft.phase_items');
});

it('refuses a platform blend whose component was deleted without disturbing other ingredients', function (): void {
    $blend = guestCalculatorOil(['display_name' => 'Guest Orphaned Blend']);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => null, 'percentage_in_parent' => 100]);
    $oil = guestCalculatorOil();

    $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($blend)])
        ->assertUnprocessable()->assertJsonValidationErrors('draft.phase_items');
    $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($oil)])->assertOk();
    $this->get('/calculators/solid-soap')->assertOk()->assertSee('Guest Olive Oil')->assertDontSee('Guest Orphaned Blend');
});

it('renders three calculator entry points and their focused guest pages', function (): void {
    guestCalculatorOil();
    $this->get('/calculators')->assertOk()->assertSeeText('Solid soap calculator')->assertSeeText('Liquid soap calculator')->assertSeeText('Cosmetic formula calculator');
    foreach (['solid-soap', 'liquid-soap', 'cosmetics'] as $calculator) {
        $this->get("/calculators/{$calculator}")->assertOk()->assertSee('recipeWorkbench(', false)->assertDontSeeText('Save this formula');
    }
});

it('orders temporary additions against retained water in the solid ingredient-list draft', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    $draft['phase_items']['additives'][] = ['id' => 'temp', 'ingredient_id' => null, 'name' => 'Visible gap', 'percentage' => 20, 'weight' => 200];
    $response = $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertOk();
    $text = mb_strtoupper($response->json('labeling.text'));
    expect(strpos($text, '[TEMPORARY: VISIBLE GAP'))->toBeLessThan(strpos($text, 'AQUA'));
});

it('withholds soap quality estimates when a selected oil has no fatty-acid profile', function (): void {
    $response = $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft(guestCalculatorOil())])->assertOk();
    $response->assertJsonPath('document.soap_analysis', null);
    expect(implode(' ', $response->json('warnings')))->toContain(__('calculators.missing_fatty_acids'));
});

it('prints a trial without an optional formula name', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    unset($draft['name']);
    $this->post('/calculators/solid-soap/print', ['draft' => json_encode($draft)])->assertOk();
});

it('keeps preview and print budgets separate and does not cache the printout', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    for ($attempt = 0; $attempt < 65; $attempt++) {
        $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertOk();
    }
    $response = $this->post('/calculators/solid-soap/print', ['draft' => json_encode($draft)])->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks missing declaration names without erasing known soap names', function (bool $soapMissing): void {
    $oil = guestCalculatorOil($soapMissing ? ['soap_inci_naoh_name' => null] : ['inci_name' => null]);
    $response = $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($oil)])->assertOk();
    $response->assertJsonPath('labeling.incomplete', true);
    expect($response->json('labeling.text'))->toContain('[INCI missing: Guest Olive Oil]');
    if (! $soapMissing) {
        expect($response->json('labeling.text'))->toContain('SODIUM OLIVATE');
    }
})->with([true, false]);

it('marks a merged fallback oil label as missing soap INCI', function (): void {
    $oil = guestCalculatorOil(['soap_inci_naoh_name' => null, 'soap_inci_koh_name' => null]);
    $response = $this->postJson('/calculators/solid-soap/preview', ['draft' => guestCalculatorDraft($oil)])->assertOk();
    expect($response->json('labeling.text'))->toContain('[INCI missing: Guest Olive Oil]');
});

it('loads catalogue translations together instead of once per ingredient', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    foreach (range(1, 6) as $index) {
        $oil = guestCalculatorOil(['display_name' => 'Oil '.$index]);
        IngredientTranslation::factory()->create(['ingredient_id' => $oil->id, 'locale' => 'fr', 'display_name' => 'Huile '.$index]);
    }
    app()->setLocale('fr');
    DB::enableQueryLog();
    app(PublicCalculatorCatalog::class)->configuration('solid-soap');
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'ingredient_translations'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
});

it('rejects a batch basis that rounds to zero at the decimal boundary', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil());
    $draft['editing_mode'] = 'weight';
    $draft['oil_weight'] = 1e-20;
    $this->postJson('/calculators/solid-soap/preview', ['draft' => $draft])->assertUnprocessable();
});

it('prints quantities using the interface locale decimal convention', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr', 'number_locale' => 'fr_FR', 'is_active' => true, 'is_default' => true]);
    $draft = guestCalculatorDraft(guestCalculatorOil());
    $this->withSession(['locale' => 'fr'])->post('/calculators/solid-soap/print', ['draft' => json_encode($draft)])
        ->assertOk()->assertSee('100,00%', false)->assertSee('1000,00', false);
});

it('uses the existing refined workbench for every guest calculator', function (string $calculator): void {
    guestCalculatorOil();
    $this->get("/calculators/{$calculator}")->assertOk()
        ->assertSee('recipeWorkbench(', false)
        ->assertSee('data-ingredient-browser-disclosure', false)
        ->assertSee('id="panel-formula"', false)
        ->assertSee('id="panel-output"', false)
        ->assertDontSee('id="tab-costing"', false)
        ->assertDontSee('id="tab-packaging"', false)
        ->assertDontSee('data-calculator-fields', false);
})->with(['solid-soap', 'liquid-soap', 'cosmetics']);

it('keeps private ingredients out of the guest workbench for signed-in visitors', function (): void {
    $user = User::factory()->create();
    guestCalculatorOil(['display_name' => 'PRIVATE WORKSPACE OIL', 'owner_type' => OwnerType::User, 'owner_id' => $user->id]);
    guestCalculatorOil(['display_name' => 'PUBLIC CATALOGUE OIL']);
    $this->actingAs($user)->get('/calculators/solid-soap')->assertOk()
        ->assertDontSee('PRIVATE WORKSPACE OIL')->assertSee('PUBLIC CATALOGUE OIL');
});

it('sizes liquid additions with the same final-batch percentage as the shared workbench', function (): void {
    $oil = guestCalculatorOil();
    $draft = guestCalculatorDraft($oil, 'liquid-soap');
    $draft['phase_items']['additives'] = [[
        'id' => 'temporary-addition', 'ingredient_id' => null, 'name' => 'My blend',
        'percentage' => 0, 'weight' => 0, 'final_percentage' => 2, 'note' => 'Keep this row',
    ]];
    $response = $this->postJson('/calculators/liquid-soap/preview', ['draft' => $draft])
        ->assertOk()->assertJsonPath('calculation.liquid_process.additions.0.id', 'temporary-addition')
        ->assertJsonPath('calculation.liquid_process.additions.0.phase_key', 'additives')
        ->assertJsonPath('draft.phase_items.additives.0.final_percentage', 2);
    $expectedWeight = (float) $response->json('calculation.liquid_process.target_final_weight') * .02;
    expect((float) $response->json('calculation.liquid_process.additions.0.weight'))->toEqualWithDelta($expectedWeight, .0001)
        ->and($response->json('draft.phase_items.additives.0.weight'))->toEqualWithDelta($expectedWeight, .0001);
    $this->post('/calculators/liquid-soap/print', ['draft' => $draft])->assertOk()->assertSeeText('My blend')->assertSeeText('Keep this row');
});

it('hides account and compliance controls in the guest workbench', function (): void {
    guestCalculatorOil();
    $this->get('/calculators/solid-soap')->assertOk()
        ->assertDontSee('data-formula-compliance-settings', false)
        ->assertDontSee('data-formula-output-type', false)
        ->assertDontSee('publish()', false)
        ->assertSee('printGuestFormula()', false);
});

it('invites guests to register at the configured sign-up page or sign in', function (string $registrationUrl): void {
    guestCalculatorOil();
    config(['app.registration_url' => $registrationUrl]);

    $html = $this->get('/calculators/solid-soap')->assertOk()
        ->assertDontSee('formulaWorkbenchLabel', false)
        ->getContent();
    $note = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-guest-registration-note]');
    $links = collect($note->querySelectorAll('a'))->map(fn ($link): array => [$link->getAttribute('href'), trim($link->textContent)])->all();

    expect(trim($note->querySelector('p')->textContent))->toBe(__('calculators.register_prompt'))
        ->and($links)->toBe([
            [$registrationUrl, __('calculators.register_action')],
            [route('login'), __('calculators.sign_in')],
        ]);
})->with(['/register', 'https://soapkraft.com/register']);

it('loads the same stylesheet as the registered bench without a competing public bundle', function (): void {
    guestCalculatorOil();
    $response = $this->get('/calculators/solid-soap')->assertOk();
    $response->assertSee(app(Vite::class)->asset('resources/css/app.css'), false)
        ->assertDontSee(app(Vite::class)->asset('resources/css/public.css'), false);
});

it('keeps custom ingredient entry separate from the catalogue list', function (): void {
    guestCalculatorOil();
    $response = $this->get('/calculators/solid-soap')->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $catalogue = $document->querySelector('#formula-ingredient-browser [role="region"]');
    $custom = $document->querySelector('details[data-guest-custom-ingredient]');

    expect($catalogue)->not->toBeNull();
    expect($custom)->not->toBeNull();
    expect($catalogue->contains($custom))->toBeFalse();
    expect($custom->querySelector('summary')->textContent)->toBe('Custom ingredient');
    expect($custom->querySelector('form'))->not->toBeNull();
});

it('prints only settings guests actually choose', function (): void {
    $draft = guestCalculatorDraft(guestCalculatorOil(), 'cosmetics');
    $this->post('/calculators/cosmetics/print', ['draft' => $draft])->assertOk()
        ->assertDontSeeText('Exposure:')->assertDontSeeText('Rinse-off')->assertDontSeeText('Regulatory regime:');
});

it('prints a single readable calculation time with local formatting metadata', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 21:05:06 UTC'));
    $response = $this->post('/calculators/solid-soap/print', ['draft' => guestCalculatorDraft(guestCalculatorOil())])->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $times = $document->querySelectorAll('[data-local-calculation-time]');
    expect($times->length)->toBe(1);
    expect($times->item(0)->textContent)->not->toContain('T21:05:06');
});

it('loads only selected ingredients and their public component dependencies for previews', function (): void {
    $oil = guestCalculatorOil();
    $unrelated = guestCalculatorOil(['display_name' => 'Unrelated catalogue oil']);
    $blend = Ingredient::factory()->create(['display_name' => 'Public blend', 'owner_type' => null, 'owner_id' => null, 'workspace_id' => null, 'is_active' => true]);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $oil->id, 'percentage_in_parent' => 100]);

    $ingredients = app(PublicCalculatorCatalog::class)->ingredients([$blend->id]);
    expect($ingredients->keys()->sort()->values()->all())->toBe(collect([$blend->id, $oil->id])->sort()->values()->all());
    expect($ingredients->has($unrelated->id))->toBeFalse();
    expect(app(PublicCalculatorCatalog::class)->fingerprint($ingredients->get($blend->id), $ingredients))->not->toBeNull();
});

it('embeds one ingredient catalogue in the shared guest editor', function (): void {
    guestCalculatorOil();
    $response = $this->get('/calculators/solid-soap')->assertOk();
    $workbench = $response->viewData('workbench');
    expect($workbench['ingredients'])->toHaveCount(1);
    expect($workbench['guestCalculator'])->not->toHaveKey('catalogue');
});
