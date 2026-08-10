<?php

namespace App\Console\Commands;

use App\Services\IngredientCatalogAuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ingredients:audit-catalog-metadata')]
#[Description('Audit platform ingredient taxonomy, capabilities, CosIng metadata, and consolidation readiness')]
class AuditIngredientCatalogMetadata extends Command
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'invalid_taxonomy' => 'Invalid taxonomy',
        'missing_platform_subtype' => 'Missing platform subtype',
        'soap_trust_without_koh_sap' => 'Soap trust without KOH SAP',
        'aromatic_without_compliance' => 'Aromatic subtype without compliance capability',
        'cosing_exact_matches' => 'CosIng exact matches',
        'cosing_no_match' => 'CosIng no-match review list',
        'cosing_ambiguous_match' => 'CosIng ambiguous-INCI review list',
        'cosing_invalid' => 'Invalid CosIng assignments',
        'manual_only_platform_functions' => 'Manual-only platform functions',
        'unresolved_consolidation' => 'Unresolved consolidation decisions',
        'conflicting_duplicate_prices' => 'Conflicting duplicate workspace prices',
    ];

    public function __construct(private readonly IngredientCatalogAuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->audit->audit();

        foreach (self::LABELS as $group => $label) {
            $records = $result[$group];
            $this->line("{$label}: ".count($records));

            foreach ($records as $record) {
                $this->line("  - {$record}");
            }
        }

        return $this->audit->hasBlockingIssues($result)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
