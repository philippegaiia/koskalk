<?php

use App\Livewire\Dashboard\SettingsIndex;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\LocalePreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function localeRequest(?string $session = null, ?string $cookie = null, ?string $languages = null, ?User $user = null): Request
{
    $request = Request::create('/', 'GET', [], array_filter([
        LocalePreferenceResolver::CookieName => $cookie,
    ]), [], array_filter([
        'HTTP_ACCEPT_LANGUAGE' => $languages,
    ]));
    $request->setLaravelSession(app('session')->driver());

    if ($session !== null) {
        $request->session()->put(LocalePreferenceResolver::SessionKey, $session);
    }

    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    return $request;
}

function supportedLocale(string $code, bool $active = true, bool $default = false): SupportedLocale
{
    return SupportedLocale::factory()->create([
        'code' => $code,
        'is_active' => $active,
        'is_default' => $default,
    ]);
}

test('users can store an interface locale independently from number formatting', function () {
    expect(Schema::hasColumn('users', 'locale'))->toBeTrue();
});

test('an active authenticated user preference has highest priority', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr');
    supportedLocale('de');
    supportedLocale('es');
    $user = User::factory()->create(['locale' => 'fr']);

    expect(app(LocalePreferenceResolver::class)->resolve(localeRequest('de', 'es', 'en-US', $user)))
        ->toBe('fr');
});

test('session then cookie then browser language determine a guest locale', function (string $expected, ?string $session, ?string $cookie, ?string $languages) {
    supportedLocale('en', default: true);
    supportedLocale('fr');
    supportedLocale('de');
    supportedLocale('es');

    expect(app(LocalePreferenceResolver::class)->resolve(localeRequest($session, $cookie, $languages)))
        ->toBe($expected);
})->with([
    'session before cookie and browser' => ['fr', 'fr', 'de', 'es-ES'],
    'cookie before browser' => ['de', null, 'de', 'es-ES'],
    'browser base language match' => ['fr', null, null, 'fr-FR,fr;q=0.9,en;q=0.8'],
]);

test('inactive preferences are ignored in favor of the active default', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr', active: false);

    expect(app(LocalePreferenceResolver::class)->resolve(localeRequest('fr', 'fr', 'fr-FR')))
        ->toBe('en');
});

test('a guest can switch to an active language from the current page', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr');

    $this->from(route('home'))
        ->post(route('language.update'), ['locale' => 'fr'])
        ->assertRedirect(route('home'))
        ->assertSessionHas(LocalePreferenceResolver::SessionKey, 'fr')
        ->assertCookie(LocalePreferenceResolver::CookieName, 'fr');
});

test('switching language persists the preference for an authenticated user', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr');
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('language.update'), ['locale' => 'fr'])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->locale)->toBe('fr');
});

test('an inactive language cannot be selected', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr', active: false);

    $this->from(route('home'))
        ->post(route('language.update'), ['locale' => 'fr'])
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors('locale');
});

test('a registered user can change interface language in settings without changing number format', function () {
    supportedLocale('en', default: true);
    supportedLocale('fr');
    $user = User::factory()->create(['locale' => 'en', 'number_locale' => 'en_GB']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('locale', 'fr')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->refresh())
        ->locale->toBe('fr')
        ->number_locale->toBe('en_GB');
});

test('the language selector is visible in every user-facing shell', function () {
    supportedLocale('en', default: true);

    $this->get(route('login'))->assertSuccessful()->assertSee('language-selector-public', false);

    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('language-selector-app', false);
});
