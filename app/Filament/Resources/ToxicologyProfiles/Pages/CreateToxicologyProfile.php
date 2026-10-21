<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateToxicologyProfile extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = ToxicologyProfileResource::class;

    /**
     * @return array<string, mixed>
     */
    protected function versionFormData(Model $source): array
    {
        /** @var ToxicologyProfile $source */
        return [
            ...$source->only(['substance_id', ...ToxicologyProfile::VALUE_COLUMNS]),
            'noael_route' => $source->noael_route?->value,
            'references' => $source->references,
            'value_references' => collect($source->value_references)
                ->map(fn (array $numbers): string => implode(', ', $numbers))
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormValidationErrors(fn (): Model => app(ToxicologyProfilePublisher::class)->publish(
            Substance::query()->findOrFail($data['substance_id']),
            $data,
            auth()->user(),
        ));
    }
}
