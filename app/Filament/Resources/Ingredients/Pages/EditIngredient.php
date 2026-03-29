<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientDataEntry;
use App\Services\IngredientDataEntryService;
use Filament\Resources\Pages\EditRecord;

class EditIngredient extends EditRecord
{
    use InteractsWithIngredientDataEntry;

    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge(
            $data,
            app(IngredientDataEntryService::class)->formData($this->record),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractIngredientDataEntryState($data);
    }

    protected function afterSave(): void
    {
        $this->syncIngredientDataEntryState($this->record);
    }
}
