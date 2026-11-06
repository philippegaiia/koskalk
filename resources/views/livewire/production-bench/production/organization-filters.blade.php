<label class="space-y-2">
    <span class="text-sm font-medium">{{ __('organization.brand') }}</span>
    <select wire:model.live="brandFilter" class="sk-input w-full">
        <option value="">{{ __('organization.all_brands') }}</option>
        @foreach ($brandOptions as $brand)
            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
        @endforeach
    </select>
</label>
<label class="space-y-2">
    <span class="text-sm font-medium">{{ __('organization.collection') }}</span>
    <select wire:model.live="collectionFilter" class="sk-input w-full">
        <option value="">{{ __('organization.all_collections') }}</option>
        @foreach ($collectionOptions as $collection)
            <option value="{{ $collection->id }}">{{ $brandFilter === '' && $collection->brand ? $collection->brand->name.' · '.$collection->name : $collection->name }}</option>
        @endforeach
    </select>
</label>
