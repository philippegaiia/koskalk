<?php

namespace App\Filament\Concerns;

use App\Services\Dossiers\AppendOnlyVersionPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Url;

/**
 * For CreateRecord pages of append-only versioned resources: creating publishes a new version,
 * and `?from=<id>` pre-fills the form from an existing version.
 */
trait PublishesAppendOnlyVersions
{
    #[Url]
    public ?int $from = null;

    protected function afterFill(): void
    {
        if ($this->from === null) {
            return;
        }

        $modelClass = static::getResource()::getModel();
        $this->form->fill($this->versionFormData($modelClass::query()->findOrFail($this->from)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function versionFormData(Model $source): array
    {
        return Arr::except($source->attributesToArray(), [
            'id', 'version', 'is_current', 'superseded_at', 'published_by_user_id', 'change_note', 'created_at', 'updated_at',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(AppendOnlyVersionPublisher::class)->publish(static::getResource()::getModel(), $data, auth()->user());
    }
}
