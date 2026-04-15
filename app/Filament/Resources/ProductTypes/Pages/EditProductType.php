<?php

namespace App\Filament\Resources\ProductTypes\Pages;

use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Models\ProductType;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductType extends EditRecord
{
    protected static string $resource = ProductTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (ProductType $record): bool => $record->recipes()->withoutGlobalScopes()->exists())
                ->tooltip(fn (ProductType $record): ?string => $record->recipes()->withoutGlobalScopes()->exists()
                    ? 'This product type is used by recipes.'
                    : null),
        ];
    }
}
