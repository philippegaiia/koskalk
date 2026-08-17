<?php

namespace App\Console\Commands;

use App\Services\IngredientCatalogConsolidationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('ingredients:sync-catalog-taxonomy {--apply : Apply exact taxonomy metadata to existing platform ingredients}')]
#[Description('Synchronize exact category and capability metadata without creating, deleting, or merging ingredients')]
class SyncIngredientCatalogTaxonomy extends Command
{
    public function __construct(private readonly IngredientCatalogConsolidationService $consolidation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('apply')) {
            $this->table(
                ['Catalog key', 'Ingredient', 'Current', 'Canonical', 'Subcategory'],
                $this->consolidation->preview()
                    ->map(fn (array $row): array => [
                        $row['catalog_key'],
                        $row['display_name'],
                        $row['from'] ?? '—',
                        $row['to'] ?? 'MISSING',
                        $row['subcategory'] ?? '—',
                    ])
                    ->all(),
            );
            $this->comment('Dry run only. Pass --apply to synchronize exact platform taxonomy metadata.');

            return self::SUCCESS;
        }

        try {
            $result = $this->consolidation->applyMetadata();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exact platform taxonomy synchronized: %d updated, %d unchanged, %d reviewed.',
            $result['updated'],
            $result['unchanged'],
            $result['reviewed'],
        ));

        return self::SUCCESS;
    }
}
