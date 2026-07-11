<?php

namespace App\Console\Commands;

use App\Services\Translations\SyncInterfaceTranslations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('translations:sync')]
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
        $result = $this->synchronizer->handle();

        $this->info(sprintf(
            'Synchronized interface translation keys: %d created, %d already present.',
            $result['created'],
            $result['existing'],
        ));

        return self::SUCCESS;
    }
}
