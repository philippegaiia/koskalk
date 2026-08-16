<?php

namespace App\Console\Commands;

use App\Services\IngredientEnrichment\ExportPlatformIngredientEnrichment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('ingredients:enrichment:export {--path= : Output path relative to the project root, or an absolute path} {--catalog-key=* : Export these platform catalog keys} {--include-complete : Include structurally complete platform ingredients}')]
#[Description('Export platform ingredients as deterministic research-ready enrichment JSONL')]
class ExportPlatformIngredientEnrichmentCommand extends Command
{
    public function __construct(private readonly ExportPlatformIngredientEnrichment $exporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->exporter->handle(
                $this->option('path'),
                $this->option('catalog-key'),
                (bool) $this->option('include-complete'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The enrichment export could not be completed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exported %d platform ingredient record(s) to [%s].',
            $result['records'],
            $result['path'],
        ));
        $this->line("SHA-256: {$result['sha256']}");

        return self::SUCCESS;
    }
}
