<?php

namespace App\Livewire\Dashboard;

use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LocalePreferenceResolver;
use App\Support\NumberLocale;
use App\WorkspaceMemberRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class SettingsIndex extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $userId = null;

    public string $activeTab = 'profile';

    // Profile fields
    public string $name = '';

    public string $email = '';

    public string $numberLocale = 'en_US';

    public string $locale = 'en';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public $avatar;

    // Company fields
    #[Locked]
    public ?int $companyId = null;

    public string $companyName = '';

    public string $companyCurrency = 'EUR';

    public string $profileStatus = '';

    public string $profileMessage = '';

    public string $companyStatus = '';

    public string $companyMessage = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->userId = $user->id;
        $this->name = $user->name ?? '';
        $this->email = $user->email ?? '';
        $this->numberLocale = NumberLocale::resolve($user->number_locale);
        $this->locale = $user->locale ?? app()->getLocale();

        $company = $user->company();

        if ($company instanceof Workspace) {
            $this->companyId = $company->id;
            $this->companyName = $company->name;
            $this->companyCurrency = $company->default_currency ?? 'EUR';
        }
    }

    public function saveProfile(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $localeOptions = $this->localeOptions;
        $rules = [
            'name' => ['required', 'string', 'max:255'],
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

        $user->fill([
            'name' => $this->name,
            'number_locale' => $this->numberLocale,
        ]);

        if ($localeOptions !== []) {
            $user->locale = $this->locale;
        }

        if ($this->avatar) {
            $this->validate(['avatar' => ['image', 'max:2048']]);
            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        if ($localeOptions !== []) {
            session()->put(LocalePreferenceResolver::SessionKey, $this->locale);
            App::setLocale($this->locale);
            Cookie::queue(cookie()->forever(LocalePreferenceResolver::CookieName, $this->locale));
        }

        $this->profileStatus = 'success';
        $this->profileMessage = 'Profile updated.';
        $this->avatar = null;
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

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'confirmed:newPasswordConfirmation', Password::defaults()],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $rateLimitKey = 'settings-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'Too many password attempts. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.',
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);

        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'The current password is incorrect.',
            ]);
        }

        RateLimiter::clear($rateLimitKey);
        $user->update(['password' => Hash::make($this->newPassword)]);

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';

        $this->profileStatus = 'success';
        $this->profileMessage = 'Password updated.';
    }

    public function saveCompany(): void
    {
        $this->validate([
            'companyName' => ['required', 'string', 'max:255'],
            'companyCurrency' => ['required', 'string', 'size:3', Rule::in(array_keys(config('currencies', [])))],
        ]);

        /** @var User $user */
        $user = auth()->user();

        if ($this->companyId) {
            $company = Workspace::withoutGlobalScopes()->find($this->companyId);

            if (! $company instanceof Workspace || $company->owner_user_id !== $user->id) {
                abort(403);
            }

            $company->fill([
                'name' => $this->companyName,
                'default_currency' => $this->companyCurrency,
            ]);
            $company->save();
        } else {
            $company = Workspace::withoutGlobalScopes()->create([
                'owner_user_id' => $user->id,
                'name' => $this->companyName,
                'slug' => Str::slug($this->companyName.'-'.Str::random(6)),
                'default_currency' => $this->companyCurrency,
            ]);

            $company->members()->create([
                'user_id' => $user->id,
                'role' => WorkspaceMemberRole::Owner->value,
            ]);

            $this->companyId = $company->id;
        }

        $this->companyStatus = 'success';
        $this->companyMessage = 'Company settings saved.';
    }

    public function render()
    {
        return view('livewire.dashboard.settings-index');
    }
}
