@props(['lot', 'action', 'canChange' => null])

<div class="inline-flex max-w-44 items-center gap-0">
    <span class="min-w-0 truncate" title="{{ $lot->storageLocation?->name ?? __('locations.unassigned') }}">
        {{ $lot->storageLocation?->name ?? __('locations.unassigned') }}
    </span>
    @if (($canChange ?? $action->isVisible()) && ($lot->ingredient_id !== null || $lot->packaging_item_id !== null))
        <x-table-row-action icon="pencil" :label="__('locations.change_storage_location')" :wire:click="$action->getLivewireClickHandler()" />
    @endif
</div>
