<?php

namespace App\Livewire\Concerns;

use App\Actions\Inventory\AssignStockLotLocation;
use App\Models\StockLot;
use App\Models\StorageLocation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait InteractsWithStockLotLocations
{
    public function changeStorageLocationAction(): Action
    {
        return Action::make('changeStorageLocation')
            ->label(__('locations.change_storage_location'))
            ->modalHeading(__('locations.change_storage_location'))
            ->modalDescription(__('locations.storage_location_help'))
            ->modalSubmitActionLabel(__('locations.save'))
            ->modalCancelActionLabel(__('production_bench.common.cancel'))
            ->visible(fn (): bool => $this->workspace()->uses_storage_locations
                && $this->productionBenchAccess->canWrite($this->user(), $this->workspace()))
            ->fillForm(fn (array $arguments): array => [
                'storage_location_id' => $this->stockLotForStorageLocationAction($arguments['lot_id'] ?? null)->storage_location_id,
            ])
            ->schema(function (array $arguments): array {
                $lot = $this->stockLotForStorageLocationAction($arguments['lot_id'] ?? null);

                return [
                    $this->storageLocationSelect(
                        includeInactive: true,
                        currentLocationId: $lot->storage_location_id,
                    ),
                ];
            })
            ->action(function (array $data, array $arguments, AssignStockLotLocation $assign): void {
                $lot = $this->stockLotForStorageLocationAction($arguments['lot_id'] ?? null);
                $locationId = $data['storage_location_id'] ?? null;

                $assign->handle(
                    actor: $this->user(),
                    lot: $lot,
                    locationId: filled($locationId) ? (int) $locationId : null,
                );

                $this->showAppNotification(__('locations.storage_location_saved'));
            });
    }

    protected function storageLocationSelect(bool $includeInactive = false, ?int $currentLocationId = null): Select
    {
        $select = Select::make('storage_location_id')
            ->label(__('locations.storage_location'))
            ->options(fn (): array => $includeInactive
                ? $this->storageLocationChooserOptions($currentLocationId)
                : $this->storageLocationOptions())
            ->placeholder(__('locations.unassigned'))
            ->searchable()
            ->native(false);

        if ($includeInactive && $currentLocationId !== null && StorageLocation::query()
            ->where('workspace_id', $this->workspace()->id)
            ->whereKey($currentLocationId)
            ->where('is_active', false)
            ->exists()) {
            $select->disableOptionWhen(fn (mixed $value): bool => (int) $value === $currentLocationId);
        }

        return $select;
    }

    /**
     * @return array<string, string>
     */
    protected function storageLocationOptions(bool $includeInactive = false): array
    {
        return $this->workspaceStorageLocations($includeInactive)
            ->mapWithKeys(function (StorageLocation $location): array {
                $label = $location->name;

                if (! $location->is_active) {
                    $label .= ' · '.__('locations.inactive');
                }

                return [(string) $location->id => $label];
            })
            ->all();
    }

    /**
     * New assignments can use active locations only. An inactive current value
     * remains in the options so the action can show it and the user can clear or
     * replace it, but it cannot be selected again after it is changed.
     *
     * @return array<string, string>
     */
    protected function storageLocationChooserOptions(?int $currentLocationId = null): array
    {
        return StorageLocation::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where(function (Builder $query) use ($currentLocationId): void {
                $query->where('is_active', true);

                if ($currentLocationId !== null) {
                    $query->orWhereKey($currentLocationId);
                }
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (StorageLocation $location): array {
                $label = $location->name;

                if (! $location->is_active) {
                    $label .= ' · '.__('locations.inactive');
                }

                return [(string) $location->id => $label];
            })
            ->all();
    }

    /**
     * @return Collection<int, StorageLocation>
     */
    protected function workspaceStorageLocations(bool $includeInactive = false): Collection
    {
        return StorageLocation::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when(! $includeInactive, fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    protected function stockLotForStorageLocationAction(mixed $lotId): StockLot
    {
        if (! is_numeric($lotId) || (int) $lotId < 1) {
            abort(404);
        }

        return StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail((int) $lotId);
    }
}
