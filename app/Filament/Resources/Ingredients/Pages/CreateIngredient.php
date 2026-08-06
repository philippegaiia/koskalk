<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientDataEntry;
use App\Services\IngredientDataEntryService;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    use InteractsWithIngredientDataEntry;

    protected static string $resource = IngredientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractIngredientDataEntryState($data);
        $data['source_file'] = 'admin';
        $data['source_code_prefix'] = 'ADM';
        $data['source_key'] = app(IngredientDataEntryService::class)->generateSourceKey('ADM');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncIngredientDataEntryState($this->record);
    }
}
