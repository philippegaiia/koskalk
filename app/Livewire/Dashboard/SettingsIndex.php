<?php

namespace App\Livewire\Dashboard;

use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\LocalePreferenceResolver;
use App\Support\NumberLocale;
use App\WorkspaceMemberRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SettingsIndex extends Component
{
    public string $activeTab = 'preferences';

    public string $numberLocale = 'en_US';

    public string $locale = 'en';

    #[Locked]
    public ?int $workspaceId = null;

    public string $workspaceName = '';

    public string $workspaceCurrency = 'EUR';

    public string $preferencesStatus = '';

    public string $preferencesMessage = '';

    public string $workspaceStatus = '';

    public string $workspaceMessage = '';

    private CurrencyCatalog $currencyCatalog;

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->numberLocale = NumberLocale::resolve($user->number_locale);
        $this->locale = $user->locale ?? app()->getLocale();

        $workspace = $user->company();

        if ($workspace instanceof Workspace) {
            $this->workspaceId = $workspace->id;
            $this->workspaceName = $workspace->name;
            $this->workspaceCurrency = $workspace->default_currency ?? 'EUR';
        }
    }

    public function savePreferences(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $localeOptions = $this->localeOptions;
        $rules = [
            'numberLocale' => ['required', 'string', Rule::in(NumberLocale::codes())],
        ];

        if ($localeOptions !== []) {
            $rules['locale'] = [
                'required',
                'string',
                Rule::exists((new SupportedLocale)->getTable(), 'code')->where('is_active', true),
            ];
        }

        $this->validate($rules);

        $user->number_locale = $this->numberLocale;

        if ($localeOptions !== []) {
            $user->locale = $this->locale;
        }

        $user->save();

        if ($localeOptions !== []) {
            session()->put(LocalePreferenceResolver::SessionKey, $this->locale);
            App::setLocale($this->locale);
            Cookie::queue(cookie()->forever(LocalePreferenceResolver::CookieName, $this->locale));
        }

        $this->preferencesStatus = 'success';
        $this->preferencesMessage = __('settings.status.preferences_saved');
    }

    /**
     * @return array<string, string>
     */
    public function getNumberLocaleOptionsProperty(): array
    {
        return NumberLocale::options();
    }

    /**
     * @return array<string, string>
     */
    public function getLocaleOptionsProperty(): array
    {
        return SupportedLocale::query()
            ->where('is_active', true)
            ->ordered()
            ->pluck('native_name', 'code')
            ->all();
    }

    public function saveWorkspace(): void
    {
        $this->validate([
            'workspaceName' => ['required', 'string', 'max:255'],
            'workspaceCurrency' => ['required', 'string', 'size:3', Rule::in($this->currencyCatalog->selectableCodes())],
        ]);

        /** @var User $user */
        $user = auth()->user();

        if ($this->workspaceId) {
            $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);

            if (! $workspace instanceof Workspace || $workspace->owner_user_id !== $user->id) {
                abort(403);
            }

            $workspace->fill([
                'name' => $this->workspaceName,
                'default_currency' => $this->workspaceCurrency,
            ]);
            $workspace->save();
        } else {
            $workspace = Workspace::withoutGlobalScopes()->create([
                'owner_user_id' => $user->id,
                'name' => $this->workspaceName,
                'slug' => Str::slug($this->workspaceName.'-'.Str::random(6)),
                'default_currency' => $this->workspaceCurrency,
            ]);

            $workspace->members()->create([
                'user_id' => $user->id,
                'role' => WorkspaceMemberRole::Owner->value,
            ]);

            $this->workspaceId = $workspace->id;
        }

        $this->workspaceStatus = 'success';
        $this->workspaceMessage = __('settings.status.workspace_saved');
    }

    public function render(): View
    {
        $currencyOptions = collect($this->currencyCatalog->options(
            app()->getLocale(),
            [$this->workspaceCurrency],
        ))->map(fn (string $name, string $code): array => [
            'id' => $code,
            'label' => "{$code} — {$name}",
            'searchText' => "{$code} {$name}",
        ])->values()->all();

        return view('livewire.dashboard.settings-index', compact('currencyOptions'));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'locale' => __('public.language.label'),
            'numberLocale' => __('number_formats.label'),
            'workspaceName' => __('settings.workspace.name'),
            'workspaceCurrency' => __('settings.workspace.default_currency'),
        ];
    }
}
