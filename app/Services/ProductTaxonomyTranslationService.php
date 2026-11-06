<?php

namespace App\Services;

use App\Models\ProductArea;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Support\ProductTaxonomyTranslations;
use Illuminate\Support\Facades\DB;

class ProductTaxonomyTranslationService
{
    public function __construct(private readonly ProductTaxonomyTranslations $translations) {}

    /** @return array{reviewed: int, updated: int, names_added: int} */
    public function backfill(bool $dryRun = false): array
    {
        return DB::transaction(function () use ($dryRun): array {
            $result = ['reviewed' => 0, 'updated' => 0, 'names_added' => 0];
            foreach ([ProductArea::class, ProductCategory::class, ProductType::class] as $model) {
                $records = $model::query()->where('is_active', true)->orderBy('id');
                if (! $dryRun) {
                    $records->lockForUpdate();
                }
                foreach ($records->get() as $record) {
                    if ($this->translations->namesFor($record->slug, $record->getTable()) === []) {
                        continue;
                    }
                    $result['reviewed']++;
                    $before = $record->translations ?? [];
                    $after = $this->translations->mergeMissing($before, $record->slug, $record->getTable());
                    if ($after === $before) {
                        continue;
                    }
                    $result['updated']++;
                    foreach ($after as $locale => $content) {
                        if (data_get($content, 'name') !== data_get($before, $locale.'.name')) {
                            $result['names_added']++;
                        }
                    }
                    if (! $dryRun) {
                        $record->update(['translations' => $after]);
                    }
                }
            }

            return $result;
        }, attempts: 5);
    }
}
