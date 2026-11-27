<?php

namespace App\Console\Commands;

use App\Services\EuFragranceAllergenCatalogSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('allergens:sync-eu-catalog
    {--apply : Write the changes; without it the command only previews}')]
#[Description('Align the allergen catalog with the fragrance allergens labelled under Regulation (EU) 2023/1545')]
class SyncEuAllergenCatalog extends Command
{
    public function __construct(private readonly EuFragranceAllergenCatalogSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $report = $this->synchronizer->synchronize(write: $apply);
        $changes = collect($report['entries'])->reject(fn (array $row): bool => $row['status'] === 'unchanged' && $row['merges'] === []);

        $this->table(
            ['Annex', 'Label', 'Status', 'Previous name', 'Merges'],
            $changes
                ->map(fn (array $row): array => [
                    $row['annex_ref'],
                    $row['label'],
                    $row['status'],
                    $row['previous_name'] ?? '—',
                    implode(', ', $row['merges']) ?: '—',
                ])
                ->all(),
        );

        if ($report['removals'] !== []) {
            $this->table(
                ['Not in the regulation', 'Action', 'Used by ingredients'],
                collect($report['removals'])
                    ->map(fn (array $row): array => [$row['inci_name'], $row['status'], $row['ingredient_count']])
                    ->all(),
            );
        }

        $counts = collect($report['entries'])->countBy('status');
        $summary = sprintf(
            '%d entries: %d to create, %d to rename, %d to update, %d unchanged; %d merged; %d to delete, %d kept for review.',
            count($report['entries']),
            $counts->get('create', 0),
            $counts->get('rename', 0),
            $counts->get('update', 0),
            $counts->get('unchanged', 0),
            collect($report['entries'])->sum(fn (array $row): int => count($row['merges'])),
            collect($report['removals'])->where('status', 'delete')->count(),
            collect($report['removals'])->where('status', 'keep')->count(),
        );

        if (! $apply) {
            $this->comment("Dry run only: {$summary} Pass --apply to write the changes.");

            return self::SUCCESS;
        }

        $this->info("Allergen catalog aligned. {$summary}");

        return self::SUCCESS;
    }
}
