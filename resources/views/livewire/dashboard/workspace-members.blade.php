<div class="space-y-6">
    @if (! $collaborationAvailable)
        <section class="sk-card space-y-3 p-5" aria-labelledby="workspace-team-needed-heading">
            <p class="sk-eyebrow">{{ __('workspaces.members.eyebrow') }}</p>
            <h2 id="workspace-team-needed-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.members.team_needed_heading') }}</h2>
            <p class="max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workspaces.members.team_needed_description') }}</p>
        </section>
    @else
        @if ($statusMessage)
            <p role="status" class="rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ $statusMessage }}</p>
        @endif

        @if ($errors->any())
            <p role="alert" class="rounded-lg bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $errors->first() }}</p>
        @endif

        <section class="sk-card space-y-4 p-5" aria-labelledby="workspace-team-heading">
            <div>
                <p class="sk-eyebrow">{{ __('workspaces.members.eyebrow') }}</p>
                <h2 id="workspace-team-heading" class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.members.heading') }}</h2>
                <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workspaces.members.description') }}</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sk-inset p-4">
                    <p class="sk-eyebrow">{{ __('workspaces.members.active_count') }}</p>
                    <p class="mt-2 text-lg font-semibold tabular-nums text-[var(--color-ink-strong)]">{{ $seatUsage['members'] }}</p>
                </div>
                <div class="sk-inset p-4">
                    <p class="sk-eyebrow">{{ __('workspaces.members.pending_count') }}</p>
                    <p class="mt-2 text-lg font-semibold tabular-nums text-[var(--color-ink-strong)]">{{ $seatUsage['pending'] }}</p>
                </div>
                <div class="sk-inset p-4">
                    <p class="sk-eyebrow">{{ __('workspaces.members.seats_used') }}</p>
                    <p class="mt-2 text-lg font-semibold tabular-nums text-[var(--color-ink-strong)]">
                        {{ $seatUsage['used'] }}{{ $seatUsage['limit'] === null ? '' : ' / '.$seatUsage['limit'] }}
                    </p>
                </div>
            </div>
        </section>

        <section class="sk-card space-y-4 p-5" aria-labelledby="workspace-invite-heading">
            <div>
                <h2 id="workspace-invite-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.invitation.heading') }}</h2>
                <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workspaces.invitation.description') }}</p>
            </div>

            @unless ($canInvite)
                <p class="rounded-lg bg-[var(--color-panel-strong)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">{{ __('workspaces.members.no_seats_available') }}</p>
            @endunless

            <form wire:submit="invite" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end">
                <label class="grid gap-2 text-sm font-medium text-[var(--color-ink-strong)]">
                    <span>{{ __('workspaces.invitation.email') }}</span>
                    <input
                        wire:model.blur="invitationEmail"
                        type="email"
                        autocomplete="email"
                        maxlength="255"
                        aria-invalid="@error('invitationEmail') true @else false @enderror"
                        @error('invitationEmail') aria-describedby="workspace-invitation-email-error" @enderror
                        class="w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    >
                    @error('invitationEmail')
                        <span id="workspace-invitation-email-error" role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2 text-sm font-medium text-[var(--color-ink-strong)]">
                    <span>{{ __('workspaces.invitation.role') }}</span>
                    <select
                        wire:model="invitationRole"
                        aria-invalid="@error('invitationRole') true @else false @enderror"
                        @error('invitationRole') aria-describedby="workspace-invitation-role-error" @enderror
                        class="w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    >
                        @foreach ($roleOptions as $role)
                            <option value="{{ $role->value }}">{{ __('workspaces.roles.'.$role->value) }}</option>
                        @endforeach
                    </select>
                    @error('invitationRole')
                        <span id="workspace-invitation-role-error" role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ $message }}</span>
                    @enderror
                </label>

                <button type="submit" wire:loading.attr="disabled" wire:target="invite" @disabled(! $canInvite) class="sk-btn sk-btn-primary sm:mb-px">
                    {{ __('workspaces.invitation.send') }}
                </button>
            </form>

            <div class="grid gap-2 border-t border-[var(--color-line)] pt-4 text-xs leading-5 text-[var(--color-ink-soft)] sm:grid-cols-3" aria-label="{{ __('workspaces.invitation.role_guidance') }}">
                <p><span class="font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.roles.admin') }}:</span> {{ __('workspaces.roles.admin_description') }}</p>
                <p><span class="font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.roles.editor') }}:</span> {{ __('workspaces.roles.editor_description') }}</p>
                <p><span class="font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.roles.viewer') }}:</span> {{ __('workspaces.roles.viewer_description') }}</p>
            </div>
        </section>

        <section class="overflow-hidden sk-card" aria-labelledby="workspace-members-heading">
            <div class="space-y-4 p-5">
                <div>
                    <h2 id="workspace-members-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.members.list_heading') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('workspaces.members.list_description') }}</p>
                </div>

                <div class="sk-inset flex flex-wrap items-center justify-between gap-3 p-4" aria-label="{{ __('workspaces.members.owner_label') }}">
                    <div class="min-w-0">
                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $owner->name }}</p>
                        <p class="break-all text-sm text-[var(--color-ink-soft)]">{{ $owner->email }}</p>
                    </div>
                    <span class="rounded-full bg-[var(--color-accent-soft)] px-3 py-1 text-xs font-semibold text-[var(--color-accent-strong)]">{{ __('workspaces.roles.owner') }}</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="sk-table min-w-full">
                    <caption class="sr-only">{{ __('workspaces.members.table_caption') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('workspaces.members.person') }}</th>
                            <th scope="col">{{ __('workspaces.invitation.role') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('workspaces.members.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($members as $member)
                            <tr wire:key="workspace-member-{{ $member->id }}">
                                <td>
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $member->user->name }}</p>
                                    <p class="break-all text-sm text-[var(--color-ink-soft)]">{{ $member->user->email }}</p>
                                </td>
                                <td>
                                    @if (in_array($member->id, $editableMemberIds, true))
                                        <label class="sr-only" for="workspace-member-role-{{ $member->id }}">{{ __('workspaces.members.change_role_for', ['name' => $member->user->name]) }}</label>
                                        <select
                                            id="workspace-member-role-{{ $member->id }}"
                                            wire:model="memberRoles.{{ $member->id }}"
                                            aria-invalid="@error('memberRoles.'.$member->id) true @else false @enderror"
                                            class="min-w-32 rounded-lg bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] focus:outline-2 focus:outline-[var(--color-accent)]"
                                        >
                                            @foreach ($roleOptions as $role)
                                                <option value="{{ $role->value }}">{{ __('workspaces.roles.'.$role->value) }}</option>
                                            @endforeach
                                        </select>
                                        @error('memberRoles.'.$member->id)
                                            <p role="alert" class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                                        @enderror
                                    @else
                                        <span class="rounded-full bg-[var(--color-panel-strong)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ __('workspaces.roles.'.$member->role->value) }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (in_array($member->id, $editableMemberIds, true))
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button
                                                type="button"
                                                wire:click="updateMemberRole({{ $member->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="updateMemberRole({{ $member->id }})"
                                                aria-label="{{ __('workspaces.members.save_role_for', ['name' => $member->user->name]) }}"
                                                class="sk-btn sk-btn-ghost"
                                            >{{ __('workspaces.members.save_role') }}</button>
                                            <button
                                                type="button"
                                                wire:click="removeMember({{ $member->id }})"
                                                wire:confirm="{{ __('workspaces.confirm.remove_member', ['name' => $member->user->name]) }}"
                                                wire:loading.attr="disabled"
                                                wire:target="removeMember({{ $member->id }})"
                                                aria-label="{{ __('workspaces.members.remove_member', ['name' => $member->user->name]) }}"
                                                class="sk-btn sk-btn-ghost text-[var(--color-danger-strong)]"
                                            >{{ __('workspaces.members.remove') }}</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('workspaces.members.no_members') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-table-pagination :paginator="$members" :per-page-label="__('workspaces.members.per_page')" per-page-model="membersPerPage" />
        </section>

        <section class="overflow-hidden sk-card" aria-labelledby="workspace-invitations-heading">
            <div class="border-b border-[var(--color-line)] p-5">
                <h2 id="workspace-invitations-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workspaces.invitations.heading') }}</h2>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('workspaces.invitations.description') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="sk-table min-w-full">
                    <caption class="sr-only">{{ __('workspaces.invitations.table_caption') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('workspaces.invitation.email') }}</th>
                            <th scope="col">{{ __('workspaces.invitation.role') }}</th>
                            <th scope="col">{{ __('workspaces.invitations.expires') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('workspaces.members.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invitations as $invitation)
                            <tr wire:key="workspace-invitation-{{ $invitation->id }}">
                                <td class="break-all">{{ $invitation->email }}</td>
                                <td><span class="rounded-full bg-[var(--color-panel-strong)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ __('workspaces.roles.'.$invitation->role->value) }}</span></td>
                                <td>{{ $invitation->expires_at->isPast() ? __('workspaces.invitations.expired') : $invitation->expires_at->format('M j, Y') }}</td>
                                <td class="text-right">
                                    @if (in_array($invitation->id, $editableInvitationIds, true))
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <button type="button" wire:click="resendInvitation({{ $invitation->id }})" wire:loading.attr="disabled" wire:target="resendInvitation({{ $invitation->id }})" aria-label="{{ __('workspaces.invitations.resend_for', ['email' => $invitation->email]) }}" class="sk-btn sk-btn-ghost">{{ __('workspaces.invitations.resend') }}</button>
                                            @if (! $invitation->expires_at->isPast())
                                                <button type="button" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="{{ __('workspaces.confirm.revoke_invitation', ['email' => $invitation->email]) }}" wire:loading.attr="disabled" wire:target="revokeInvitation({{ $invitation->id }})" aria-label="{{ __('workspaces.invitations.revoke_for', ['email' => $invitation->email]) }}" class="sk-btn sk-btn-ghost text-[var(--color-danger-strong)]">{{ __('workspaces.invitations.revoke') }}</button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('workspaces.invitations.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-table-pagination :paginator="$invitations" :per-page-label="__('workspaces.invitations.per_page')" per-page-model="invitationsPerPage" />
        </section>
    @endif
</div>
