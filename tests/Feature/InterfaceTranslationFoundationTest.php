<?php

use App\Filament\Resources\InterfaceTranslations\Pages\EditInterfaceTranslation;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\Translations\EnglishTranslationSource;
use App\Services\Translations\SyncInterfaceTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Spatie\TranslationLoader\LanguageLine;

uses(RefreshDatabase::class);

it('owns the application models and services around the translation loader', function () {
    expect(class_exists(SupportedLocale::class))->toBeTrue()
        ->and(class_exists(InterfaceTranslation::class))->toBeTrue()
        ->and(is_subclass_of(InterfaceTranslation::class, LanguageLine::class))->toBeTrue()
        ->and(class_exists(EnglishTranslationSource::class))->toBeTrue()
        ->and(class_exists(SyncInterfaceTranslations::class))->toBeTrue();
});

it('stores unique interface keys and dynamically managed locales', function () {
    expect(Schema::hasTable('language_lines'))->toBeTrue()
        ->and(Schema::hasColumns('language_lines', ['group', 'key', 'text']))->toBeTrue()
        ->and(Schema::hasTable('supported_locales'))->toBeTrue()
        ->and(Schema::hasColumns('supported_locales', [
            'code',
            'name',
            'native_name',
            'number_locale',
            'text_direction',
            'is_active',
            'is_default',
            'sort_order',
        ]))->toBeTrue();
});

it('seeds the initial locales without preventing future languages', function () {
    $seeder = 'Database\\Seeders\\SupportedLocaleSeeder';

    expect(class_exists($seeder))->toBeTrue();

    $this->seed($seeder);

    expect(SupportedLocale::query()->orderBy('sort_order')->pluck('code')->all())
        ->toBe(['en', 'fr', 'es', 'de', 'it', 'nl'])
        ->and(SupportedLocale::query()->where('is_default', true)->value('code'))
        ->toBe('en')
        ->and(SupportedLocale::query()->where('code', 'nl')->firstOrFail()->only([
            'native_name',
            'number_locale',
            'text_direction',
            'is_active',
            'sort_order',
        ]))->toBe([
            'native_name' => 'Nederlands',
            'number_locale' => 'nl_NL',
            'text_direction' => 'ltr',
            'is_active' => false,
            'sort_order' => 60,
        ])
        ->and(InterfaceTranslation::query()->exists())->toBeFalse();
});

it('reads only application-owned English source strings from Laravel language files', function () {
    expect(method_exists(EnglishTranslationSource::class, 'get'))->toBeTrue()
        ->and(method_exists(EnglishTranslationSource::class, 'all'))->toBeTrue();

    $source = app(EnglishTranslationSource::class);

    expect($source->get('auth', 'login.heading'))->toBe('Sign in to your workspace')
        ->and($source->get('auth', 'failed'))->toBeNull()
        ->and($source->get('validation', 'required'))->toBeNull()
        ->and($source->get('homepage', 'hero.title'))->toBeNull()
        ->and($source->get('currencies', 'EUR'))->toBeNull()
        ->and($source->all())->toHaveKey('public.language.label')
        ->toHaveKey('formula_documents.title')
        ->toHaveKey('formula_documents.sections.lye_water')
        ->toHaveKey('formula_documents.actions.print');
});

it('keeps non-English application translations exclusively in the database', function () {
    foreach (['fr', 'es', 'de', 'it', 'nl'] as $locale) {
        expect(lang_path("{$locale}/workbench.php"))->not->toBeFile();
    }

    expect(base_path('database/seeders/InterfaceTranslationSeeder.php'))->not->toBeFile()
        ->and(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))
        ->not->toContain('InterfaceTranslationSeeder');
});

it('synchronizes missing interface keys without overwriting translations', function () {
    expect(method_exists(SyncInterfaceTranslations::class, 'handle'))->toBeTrue();

    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Connexion à votre espace'],
    ]);

    $result = app(SyncInterfaceTranslations::class)->handle();

    expect($result['created'])->toBeGreaterThan(0)
        ->and(InterfaceTranslation::query()->where('group', 'auth')->where('key', 'login.heading')->value('text'))
        ->toBe(['fr' => 'Connexion à votre espace'])
        ->and(InterfaceTranslation::query()->where('group', 'public')->where('key', 'navigation.product')->value('text'))
        ->toBe([])
        ->and(InterfaceTranslation::query()->whereIn('group', ['currencies', 'homepage', 'validation'])->exists())
        ->toBeFalse();
});

it('uses database translations while retaining the English file fallback', function () {
    InterfaceTranslation::query()->create([
        'group' => 'public',
        'key' => 'navigation.product',
        'text' => ['fr' => 'Produit'],
    ]);

    App::setLocale('fr');

    expect(__('public.navigation.product'))->toBe('Produit');

    App::setLocale('de');

    expect(__('public.navigation.product'))->toBe('Product');
});

it('prunes only rows outside the application-owned source when explicitly requested', function () {
    InterfaceTranslation::query()->create([
        'group' => 'auth',
        'key' => 'login.heading',
        'text' => ['fr' => 'Connexion'],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'validation',
        'key' => 'required',
        'text' => [],
    ]);
    InterfaceTranslation::query()->create([
        'group' => 'homepage',
        'key' => 'hero.title',
        'text' => [],
    ]);

    $result = app(SyncInterfaceTranslations::class)->handle(prune: true);

    expect($result['pruned'])->toBe(2)
        ->and(InterfaceTranslation::query()->where('group', 'auth')->where('key', 'login.heading')->value('text'))
        ->toBe(['fr' => 'Connexion'])
        ->and(InterfaceTranslation::query()->whereIn('group', ['validation', 'homepage'])->exists())
        ->toBeFalse();
});

it('rejects translations that change named placeholders', function () {
    $ruleClass = 'App\\Rules\\PreservesTranslationPlaceholders';

    expect(class_exists($ruleClass))->toBeTrue();

    $valid = Validator::make([
        'translation' => 'Bonjour :name, vous avez :count éléments.',
    ], [
        'translation' => [new $ruleClass('Hello :name, you have :count items.')],
    ]);

    $invalid = Validator::make([
        'translation' => 'Bonjour :name.',
    ], [
        'translation' => [new $ruleClass('Hello :name, you have :count items.')],
    ]);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue();
});

it('exposes a deployment-safe interface translation sync command', function () {
    expect(Artisan::all())->toHaveKey('translations:sync');

    InterfaceTranslation::query()->create([
        'group' => 'validation',
        'key' => 'required',
        'text' => [],
    ]);

    $exitCode = Artisan::call('translations:sync', ['--prune' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('interface translation keys', 'pruned')
        ->and(InterfaceTranslation::query()->where('group', 'validation')->exists())->toBeFalse()
        ->and(InterfaceTranslation::query()->count())->toBeGreaterThan(0);
});

it('provides English-only admin resources for locales and interface translations', function () {
    $localeResource = 'App\\Filament\\Resources\\SupportedLocales\\SupportedLocaleResource';
    $translationResource = 'App\\Filament\\Resources\\InterfaceTranslations\\InterfaceTranslationResource';

    expect(class_exists($localeResource))->toBeTrue()
        ->and(class_exists($translationResource))->toBeTrue()
        ->and(array_keys($translationResource::getPages()))->toBe(['index', 'edit']);
});

it('renders the locale registry and translation editor in English', function () {
    $localeResource = 'App\\Filament\\Resources\\SupportedLocales\\SupportedLocaleResource';
    $translationResource = 'App\\Filament\\Resources\\InterfaceTranslations\\InterfaceTranslationResource';

    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');
    app(SyncInterfaceTranslations::class)->handle();

    $admin = User::factory()->admin()->create();
    $translation = InterfaceTranslation::query()
        ->where('group', 'auth')
        ->where('key', 'login.heading')
        ->firstOrFail();

    App::setLocale('fr');
    $this->actingAs($admin);

    $this->get($localeResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('English')
        ->assertSee('French')
        ->assertSee('Number locale');

    $this->get($translationResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('account')
        ->assertSee('page.title')
        ->assertSee('English source');

    $this->get($translationResource::getUrl('edit', ['record' => $translation], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('English source')
        ->assertSee('Sign in to your workspace')
        ->assertSee('French')
        ->assertSee('Spanish');

    expect(App::currentLocale())->toBe('en');
});

it('lets an administrator save interface translations without changing the key', function () {
    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');
    app(SyncInterfaceTranslations::class)->handle();

    $admin = User::factory()->admin()->create();
    $translation = InterfaceTranslation::query()
        ->where('group', 'auth')
        ->where('key', 'login.heading')
        ->firstOrFail();

    $this->actingAs($admin);

    Livewire::test(EditInterfaceTranslation::class, ['record' => $translation->getKey()])
        ->fillForm([
            'text' => [
                'fr' => 'Connexion à votre espace',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($translation->refresh())
        ->group->toBe('auth')
        ->key->toBe('login.heading')
        ->text->toBe(['fr' => 'Connexion à votre espace']);
});
