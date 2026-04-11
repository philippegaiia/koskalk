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
 @if($companyId)
 <button
 type="button"
 wire:click="$set('activeTab', 'members')"
 @class([
 'rounded-t-lg px-4 py-2.5 text-sm font-medium transition -mb-px border-b-2',
 'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'members',
 'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'members',
 ])
 >Members</button>
 @endif
 </div>

 @if($activeTab === 'profile')
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 space-y-5">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Profile</p>
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
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Name</span>
 <input
 wire:model="name"
 type="text"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('name') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Email</span>
 <input
 wire:model="email"
 type="email"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('email') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
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

 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 space-y-5">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Password</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Update your password to keep your account secure.</p>
 </div>

 <div class="grid gap-3">
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Current password</span>
 <input
 wire:model="currentPassword"
 type="password"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('currentPassword') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <div class="grid gap-3 md:grid-cols-2">
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">New password</span>
 <input
 wire:model="newPassword"
 type="password"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('newPassword') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Confirm new password</span>
 <input
 wire:model="newPasswordConfirmation"
 type="password"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
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
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 space-y-5">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Company</p>
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
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Company name</span>
 <input
 wire:model="companyName"
 type="text"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('companyName') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Default currency</span>
 <select
 wire:model="companyCurrency"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline-none"
 >
 @foreach(config('currencies', []) as $code => $data)
 <option value="{{ $code }}">{{ $code }} — {{ __('currencies.' . $code) }}</option>
 @endforeach
 </select>
 @error('companyCurrency') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Used as the default currency for costing and pricing across all company recipes.</p>
 </label>
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

 @if($activeTab === 'members' && $companyId)
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 space-y-5">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Invite member</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Invite someone to join your company by email. They will be able to access shared ingredients and recipes based on their role.</p>
 </div>

 @if($memberStatus && $memberMessage)
 <div @class([
 'rounded-lg px-4 py-3 text-sm',
 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $memberStatus === 'success',
 'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $memberStatus === 'error',
 ])>
 {{ $memberMessage }}
 </div>
 @endif

 <div class="grid gap-3 md:grid-cols-3">
 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4 md:col-span-2">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Email address</span>
 <input
 wire:model="inviteEmail"
 type="email"
 placeholder="colleague@example.com"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline-none"
 />
 @error('inviteEmail') <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p> @enderror
 </label>

 <label class="rounded-lg bg-[var(--color-panel-strong)] p-4">
 <span class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Role</span>
 <select
 wire:model="inviteRole"
 class="mt-3 w-full rounded-lg bg-[var(--color-panel-strong)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline-none"
 >
 <option value="admin">Admin</option>
 <option value="editor">Editor</option>
 <option value="viewer">Viewer</option>
 </select>
 </label>
 </div>

 <div class="flex justify-end">
 <button
 wire:click="inviteMember"
 wire:loading.attr="disabled"
 type="button"
 class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
 >Send invitation</button>
 </div>
 </section>

 <section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Members</p>
 </div>

 <div class="divide-y divide-[var(--color-line)]">
 @foreach($this->members as $member)
 <div class="flex items-center justify-between px-5 py-3 text-sm">
 <div>
 <p class="font-medium text-[var(--color-ink-strong)]">{{ $member->user?->name ?? 'Unknown' }}</p>
 <p class="text-[var(--color-ink-soft)]">{{ $member->user?->email }}</p>
 </div>
 <div class="flex items-center gap-3">
 <span class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ ucfirst($member->role->value ?? $member->role) }}</span>
 @if($member->user_id !== $userId)
 <button
 wire:click="removeMember({{ $member->id }})"
 wire:confirm="Remove {{ $member->user?->name }} from the company?"
 type="button"
 class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)] transition hover:text-[var(--color-danger-strong)] bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)] hover:border-[var(--color-danger-soft)]"
 >Remove</button>
 @endif
 </div>
 </div>
 @endforeach

 @foreach($this->pendingInvitations as $invitation)
 <div class="flex items-center justify-between px-5 py-3 text-sm bg-[var(--color-panel)]">
 <div>
 <p class="font-medium text-[var(--color-ink-soft)]">{{ $invitation->email }}</p>
 <p class="text-xs text-[var(--color-ink-soft)]">Pending invitation</p>
 </div>
 <span class="rounded-full border border-dashed border-[var(--color-line)] bg-white px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ ucfirst($invitation->role) }}</span>
 </div>
 @endforeach
 </div>
 </section>
 @endif
