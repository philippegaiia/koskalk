<?php

namespace App\Console\Commands;

use App\Services\ProductTaxonomyTranslationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('taxonomy:backfill-translations {--dry-run : Preview missing names without writing any records}')]
#[Description('Fill missing localized names on existing active curated product areas, categories and types while preserving edited content')]
class BackfillProductTaxonomyTranslations extends Command
{
    public function handle(ProductTaxonomyTranslationService $translations): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $translations->backfill($dryRun);
        $this->info(sprintf('%s%d records updated; %d localized names added; %d curated records reviewed.',
            $dryRun ? 'Dry run: ' : '',
            $result['updated'],
            $result['names_added'],
            $result['reviewed'],
        ));

        return self::SUCCESS;
    }
}
