<?php

namespace App\Console\Commands;

use App\Services\Translations\ExportInterfaceTranslationCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('translations:catalogue:export {--path= : Catalogue path relative to the project root, or an absolute path}')]
#[Description('Export interface translations to the deterministic repository catalogue')]
class ExportInterfaceTranslationCatalogueCommand extends Command
{
    public function __construct(private readonly ExportInterfaceTranslationCatalogue $exporter)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $result = $this->exporter->handle($this->option('path'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exported %d interface translation rows for %d locales to [%s].',
            $result['rows'],
            $result['locales'],
            $result['path'],
        ));
        $this->line("SHA-256: {$result['sha256']}");

        return self::SUCCESS;
    }
}
