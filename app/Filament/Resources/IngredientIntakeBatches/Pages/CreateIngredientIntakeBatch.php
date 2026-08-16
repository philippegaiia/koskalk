<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Pages;

use App\Actions\IngredientIntake\CreateIngredientIntakeBatch as CreateIngredientIntakeBatchAction;
use App\Filament\Resources\IngredientIntakeBatches\IngredientIntakeBatchResource;
use App\Models\User;
use App\Services\IngredientIntake\IngredientIntakeParser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

class CreateIngredientIntakeBatch extends CreateRecord
{
    protected static string $resource = IngredientIntakeBatchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $parser = app(IngredientIntakeParser::class);
        $upload = $this->uploadedFile($data['upload'] ?? null);
        $rows = ($data['input_method'] ?? null) === 'csv'
            ? $parser->parseCsvFile($upload?->getRealPath() ?: '')
            : $parser->parsePasted((string) ($data['pasted_input'] ?? ''));

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateIngredientIntakeBatchAction::class)->handle($actor, [
            'name' => $data['name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'input_method' => $data['input_method'] ?? null,
            'family_hint' => $data['family_hint'] ?? null,
            'allow_gap_research' => $data['allow_gap_research'] ?? false,
            'upload' => $upload,
        ], $rows);
    }

    protected function getRedirectUrl(): string
    {
        return IngredientIntakeBatchResource::getUrl('view', ['record' => $this->record]);
    }

    private function uploadedFile(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_array($value)) {
            $first = array_values($value)[0] ?? null;

            return $first instanceof UploadedFile ? $first : null;
        }

        return null;
    }
}
