<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientClassificationPrompt;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientDataEntry;
use App\Services\IngredientDataEntryService;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    use InteractsWithIngredientClassificationPrompt;
    use InteractsWithIngredientDataEntry;

    protected static string $resource = IngredientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractIngredientDataEntryState($data);
        $data['catalog_key'] = app(IngredientDataEntryService::class)->generateCatalogKey('ADM');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncIngredientDataEntryState($this->record);
    }
}
