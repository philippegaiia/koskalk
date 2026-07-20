<?php

namespace App\Console\Commands;

use App\Services\Translations\SyncInterfaceTranslations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('translations:sync {--prune : Delete database rows that are not application-owned English keys}')]
#[Description('Insert missing interface translation keys without overwriting existing translations')]
class SyncInterfaceTranslationsCommand extends Command
{
    public function __construct(private readonly SyncInterfaceTranslations $synchronizer)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $result = $this->synchronizer->handle(prune: (bool) $this->option('prune'));

        $this->info(sprintf(
            'Synchronized interface translation keys: %d created, %d already present, %d pruned.',
            $result['created'],
            $result['existing'],
            $result['pruned'],
        ));

        return self::SUCCESS;
    }
}
