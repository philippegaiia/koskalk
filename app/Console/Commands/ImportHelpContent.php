<?php

namespace App\Console\Commands;

use App\Enums\HelpContentImportMode;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use Illuminate\Console\Command;
use Throwable;

class ImportHelpContent extends Command
{
    protected $signature = 'help:import {path} {--mode=bootstrap} {--apply} {--force}';

    protected $description = 'Preview or apply a portable contextual help manifest';

    public function handle(HelpContentManifest $manifests, HelpContentImporter $importer): int
    {
        try {
            $mode = HelpContentImportMode::tryFrom((string) $this->option('mode'));
            if ($mode === null) {
                $this->error('Unknown import mode.');

                return self::FAILURE;
            }
            if ($this->option('apply') && app()->isProduction() && ! $this->option('force')) {
                $this->error('Production imports require --force.');

                return self::FAILURE;
            }
            $path = (string) $this->argument('path');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > HelpContentManifest::MAX_BYTES) {
                $this->error('Manifest must be a readable JSON file no larger than 10 MiB.');

                return self::FAILURE;
            }
            $manifest = $manifests->decode(file_get_contents($path));
            $preview = $importer->preview($manifest, $mode);
            $this->table(['Topic', 'Locale', 'Change'], collect($preview['changes'])->map(fn (array $change): array => [$change['key'], $change['locale'], $change['will_change'] ? 'Draft import' : 'Preserve existing'])->all());
            if (! $this->option('apply')) {
                $this->info('Preview only. Use --apply to import.');

                return self::SUCCESS;
            }
            $result = $importer->apply($manifest, $mode, $preview['selection'], $preview['manifest_hash']);
            $this->info("Imported: {$result['imported']}; skipped: {$result['skipped']}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
