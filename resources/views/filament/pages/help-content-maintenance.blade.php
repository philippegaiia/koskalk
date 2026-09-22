<x-filament-panels::page>
    <x-filament::section wire:poll.15s>
        <x-slot name="heading">Verified backups</x-slot>
        <x-slot name="description">Keep a portable copy of all drafts, publications, and translation history.</x-slot>

        <div class="flex flex-wrap items-center justify-between gap-4">
            @if ($lastSuccessfulExport)
                <div class="space-y-1">
                    <x-filament::badge color="success">Last verified backup</x-filament::badge>
                    <p class="text-sm font-medium">{{ $lastSuccessfulExport->completed_at->format('M j, Y · H:i') }} UTC</p>
                    <p class="text-sm text-gray-500">{{ number_format($lastSuccessfulExport->size_bytes / 1024, 1) }} KiB · {{ ucfirst($lastSuccessfulExport->reason->value) }}</p>
                    <details class="text-xs text-gray-500"><summary class="cursor-pointer">SHA-256 checksum</summary><code class="break-all">{{ $lastSuccessfulExport->checksum }}</code></details>
                </div>
                <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-down-tray" :href="route('help-content-exports.download', $lastSuccessfulExport)">
                    Download JSON
                </x-filament::button>
            @else
                <p class="text-sm text-gray-500">No verified backup yet.</p>
            @endif
            <x-filament::button wire:click="requestExport" wire:loading.attr="disabled" wire:target="requestExport" icon="heroicon-o-cloud-arrow-up">
                Request backup
            </x-filament::button>
        </div>

        @if ($recentExports->isNotEmpty())
            <div class="mt-6 divide-y divide-gray-200 dark:divide-gray-700" aria-label="Pending and failed backups">
                @foreach ($recentExports as $export)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="export-{{ $export->public_id }}">
                        <div class="space-y-1">
                            <div class="flex items-center gap-3">
                                <x-filament::badge :color="$export->status === \App\Enums\HelpContentExportStatus::Failed ? 'danger' : 'warning'">{{ ucfirst($export->status->value) }}</x-filament::badge>
                                <span class="text-sm">{{ $export->created_at->format('M j, H:i') }} UTC · {{ str_replace('_', ' ', ucfirst($export->reason->value)) }}</span>
                            </div>
                            @if ($export->error_message)
                                <p class="text-sm text-gray-500">{{ $export->error_message }}</p>
                            @endif
                        </div>
                        @if ($export->status === \App\Enums\HelpContentExportStatus::Failed)
                            <x-filament::button color="gray" size="sm" wire:click="retryExport('{{ $export->public_id }}')" wire:loading.attr="disabled" wire:target="retryExport">Retry backup</x-filament::button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <form wire:submit="previewImport" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" color="gray" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="previewImport,data.manifest">Preview package</x-filament::button>
    </form>

    @error('manifest')
        <p role="alert" class="text-sm text-danger-600">{{ $message }}</p>
    @enderror
    @error('selectedChanges')
        <p role="alert" class="text-sm text-danger-600">{{ $message }}</p>
    @enderror

    @if ($previewState)
        <x-filament::section>
            <x-slot name="heading">Review changes</x-slot>
            <x-slot name="description">
                @if ($previewState['mode'] === 'restore-empty')
                    Restore includes the complete package: history, publication pointers, archived topics, and translation audit records.
                @else
                    Select the drafts to import. Published content and archived status stay as they are.
                @endif
            </x-slot>

            <div class="space-y-6">
                @forelse ($previewState['changes'] as $index => $change)
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700" wire:key="preview-{{ $index }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-3 font-medium">
                                @if ($change['will_change'] && $previewState['mode'] !== 'restore-empty')
                                    <x-filament::input.checkbox wire:model="selectedChanges" :value="(string) $index" />
                                @endif
                                <span>{{ $change['key'] }}</span>
                                <x-filament::badge color="gray">{{ strtoupper($change['locale']) }}</x-filament::badge>
                            </label>
                            <span class="text-sm text-gray-500">{{ $change['will_change'] ? 'Incoming draft' : 'Existing content preserved' }}</span>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach (['current' => 'Current draft', 'incoming' => 'Incoming draft'] as $side => $label)
                                <div class="space-y-2">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                                    @if ($change[$side])
                                        <p class="font-medium">{{ $change[$side]['title'] }}</p>
                                        <p class="whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ $change[$side]['summary'] }}</p>
                                        @if ($change[$side]['body_markdown'])
                                            <details class="text-sm">
                                                <summary class="cursor-pointer text-gray-500">Full explanation</summary>
                                                <p class="mt-2 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{{ $change[$side]['body_markdown'] }}</p>
                                            </details>
                                        @endif
                                    @else
                                        <p class="text-sm text-gray-500">No saved draft.</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">This package has no draft changes.</p>
                @endforelse
                <x-filament::button wire:click="applyImport" wire:loading.attr="disabled" wire:target="applyImport" icon="heroicon-o-arrow-up-tray">
                    {{ $previewState['mode'] === 'restore-empty' ? 'Restore help content' : 'Import selected drafts' }}
                </x-filament::button>
                <p wire:loading wire:target="applyImport" role="status" class="text-sm text-gray-500">Verifying the package and backup, then applying the reviewed changes…</p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
