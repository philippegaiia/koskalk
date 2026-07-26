<?php

namespace App\Console\Commands;

use App\Enums\InterfaceTranslationImportMode;
use App\Services\Translations\ImportInterfaceTranslationCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('translations:catalogue:import
    {--path= : Catalogue path relative to the project root, or an absolute path}
    {--mode= : Required conflict mode: authoritative or preserve-existing}
    {--force : Allow catalogue-authoritative replacement in production}')]
#[Description('Import the reviewed interface translation catalogue without deleting database rows')]
class ImportInterfaceTranslationCatalogueCommand extends Command
{
    public function __construct(private readonly ImportInterfaceTranslationCatalogue $importer)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modeValue = $this->option('mode');
        $mode = is_string($modeValue) ? InterfaceTranslationImportMode::tryFrom($modeValue) : null;

        if ($mode === null) {
            $this->error('Choose an explicit import mode: authoritative or preserve-existing.');

            return self::FAILURE;
        }

        if ($mode === InterfaceTranslationImportMode::Authoritative && app()->isProduction() && ! $this->option('force')) {
            $this->error('Catalogue-authoritative production imports require --force.');

            return self::FAILURE;
        }

        try {
            $result = $this->importer->handle($mode, $this->option('path'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d catalogue rows in %s mode: %d created, %d updated, %d unchanged, %d production values preserved.',
            $result['rows'],
            $mode->value,
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['preserved'],
        ));

        return self::SUCCESS;
    }
}
