<?php

namespace Database\Seeders;

use App\Services\EuFragranceAllergenCatalogSynchronizer;
use Illuminate\Database\Seeder;

class AllergenCatalogSeeder extends Seeder
{
    /**
     * Seeds the fragrance allergens labelled under Regulation (EU) 2023/1545
     * from the committed reference file. On an existing database, run
     * `allergens:sync-eu-catalog` instead so the changes can be previewed.
     */
    public function run(EuFragranceAllergenCatalogSynchronizer $synchronizer): void
    {
        $synchronizer->synchronize(write: true);
    }
}
