<div>
 <div class="mx-auto max-w-3xl space-y-6">
 <div>
 <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">Settings</h2>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Manage your profile and company preferences.</p>
 </div>

 <div class="flex gap-2 border-b border-[var(--color-line)] pb-px">
 <button
 type="button"
 wire:click="$set('activeTab', 'profile')"
 @class([
 'rounded-t-lg px-4 py-2.5 text-sm font-medium transition -mb-px border-b-2',
 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'profile',
 'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'profile',
 ])
 >Profile</button>
 <button
 type="button"
 wire:click="$set('activeTab', 'company')"
 @class([
 'rounded-t-lg px-4 py-2.5 text-sm font-medium transition -mb-px border-b-2',
 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'company',
 'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'company',
 ])
 >Company</button>
 </div>

 @if($activeTab === 'profile')
 <section class="sk-card p-5 space-y-5">
 <div>
 <p class="sk-eyebrow">Profile</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Your personal information and login credentials.</p>
 </div>

 @if($profileStatus && $profileMessage)
 <div @class([
 'rounded-lg px-4 py-3 text-sm',
 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $profileStatus === 'success',
 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $profileStatus === 'error',
 ])>
 {{ $profileMessage }}
 </div>
 @endif

 <div class="grid gap-3 md:grid-cols-2">
 <label class="sk-inset grid content-start p-4">
 <span class="sk-eyebrow">Name</span>
 <input
 wire:model="name"
 type="text"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 @error('name') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Email</span>
 <input
 wire:model="email"
 type="email"
 readonly
 disabled
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Login email changes are disabled during the invite-only MVP.</p>
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('public.language.label') }}</span>
 <select
 wire:model="locale"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 >
 @foreach($this->localeOptions as $localeCode => $localeName)
 <option value="{{ $localeCode }}">{{ $localeName }}</option>
 @endforeach
 </select>
 @error('locale') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="sk-inset p-4">
 <span class="sk-eyebrow">{{ __('number_formats.label') }}</span>
 <select
 wire:model="numberLocale"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 >
 @foreach($this->numberLocaleOptions as $locale => $label)
 <option value="{{ $locale }}">{{ $label }}</option>
 @endforeach
 </select>
 @error('numberLocale') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('number_formats.help') }}</p>
 </label>
 </div>

 <div class="flex justify-end">
 <button
 wire:click="saveProfile"
 wire:loading.attr="disabled"
 type="button"
 class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
 >Save profile</button>
 </div>
 </section>

 <section class="sk-card p-5 space-y-5">
 <div>
 <p class="sk-eyebrow">Password</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Update your password to keep your account secure.</p>
 </div>

 <div class="grid gap-3">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Current password</span>
 <input
 wire:model="currentPassword"
 type="password"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 @error('currentPassword') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <div class="grid gap-3 md:grid-cols-2">
 <label class="sk-inset grid content-start p-4">
 <span class="sk-eyebrow">New password</span>
 <input
 wire:model="newPassword"
 type="password"
 aria-invalid="@error('newPassword') true @else false @enderror"
 aria-describedby="settings-password-requirements @error('newPassword') settings-password-error @enderror"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 <p id="settings-password-requirements" class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('auth.password_requirements') }}</p>
 @if ($errors->has('newPassword'))
 <ul id="settings-password-error" role="alert" class="mt-2 grid list-disc gap-1 pl-5 text-xs leading-5 text-[var(--color-danger-strong)]">
 @foreach ($errors->get('newPassword') as $message)
 <li>{{ $message }}</li>
 @endforeach
 </ul>
 @endif
 </label>

 <label class="sk-inset grid content-start p-4">
 <span class="sk-eyebrow">Confirm new password</span>
 <input
 wire:model="newPasswordConfirmation"
 type="password"
 aria-invalid="@error('newPassword') true @else false @enderror"
 aria-describedby="settings-password-requirements @error('newPassword') settings-password-error @enderror"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 </label>
 </div>
 </div>

 <div class="flex justify-end">
 <button
 wire:click="updatePassword"
 wire:loading.attr="disabled"
 type="button"
 class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
 >Update password</button>
 </div>
 </section>
 @endif

 @if($activeTab === 'company')
 <section class="sk-card p-5 space-y-5">
 <div>
 <p class="sk-eyebrow">Company</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Your company owns all shared ingredients, packaging, and recipes. Only the owner can change these settings.</p>
 </div>

 @if($companyStatus && $companyMessage)
 <div @class([
 'rounded-lg px-4 py-3 text-sm',
 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $companyStatus === 'success',
 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $companyStatus === 'error',
 ])>
 {{ $companyMessage }}
 </div>
 @endif

 <div class="grid gap-3 md:grid-cols-2">
 <label class="sk-inset p-4">
 <span class="sk-eyebrow">Company name</span>
 <input
 wire:model="companyName"
 type="text"
 class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
 />
 @error('companyName') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <div class="sk-inset p-4">
 <span class="sk-eyebrow">Default currency</span>
 <x-search-combobox
 id="company-currency-search"
 label="Default currency"
 :options="collect(config('currencies', []))->map(fn (array $data, string $code): array => ['id' => $code, 'label' => $code.' — '.__('currencies.'.$code), 'searchText' => $code.' '.__('currencies.'.$code)])->values()->all()"
 :selected-id="$companyCurrency"
 placeholder="Search currencies"
 :allow-empty="false"
 class="mt-3"
 x-on:search-combobox-selected="$wire.set('companyCurrency', String($event.detail.id))"
 />
 @error('companyCurrency') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Used as the default currency for costing and pricing across all company recipes.</p>
 </div>
 </div>

 <div class="flex justify-end">
 <button
 wire:click="saveCompany"
 wire:loading.attr="disabled"
 type="button"
 class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
 >Save company settings</button>
 </div>
 </section>
 @endif
