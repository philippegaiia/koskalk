<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Services\IngredientEnrichment\ApplyPlatformIngredientEnrichment;
use App\Services\IngredientEnrichment\IngredientEnrichmentJsonl;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

#[Signature('ingredients:enrichment:import {path : Research-result JSONL path relative to the project root, or an absolute path} {--apply : Apply valid plans; omission is a read-only dry run} {--replace=* : Explicit field or collection allowed to replace reviewed data}')]
#[Description('Preview or apply platform ingredient enrichment research results')]
class ImportPlatformIngredientEnrichmentCommand extends Command
{
    public function __construct(
        private readonly IngredientEnrichmentJsonl $jsonl,
        private readonly IngredientEnrichmentPlanner $planner,
        private readonly IngredientEnrichmentResultValidator $validator,
        private readonly ApplyPlatformIngredientEnrichment $applier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $replaceFields = $this->planner->normalizeReplaceFields(
                is_array($this->option('replace')) ? $this->option('replace') : [],
            );
        } catch (ValidationException $exception) {
            $this->error($this->firstError($exception));

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->argument('path'));
        $records = [];
        $seenCatalogKeys = [];
        $rows = [];
        $planned = 0;
        $unchanged = 0;
        $warned = 0;
        $failed = 0;
        $applied = 0;
        $skipped = 0;

        try {
            $records = $this->jsonl->read($path);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error((string) __('ingredient_enrichment.command.read_failed'));

            return self::FAILURE;
        }

        foreach ($records as $record) {
            $line = $record['line'];
            if ($record['error'] !== null) {
                $failed++;
                $skipped += $this->option('apply') ? 1 : 0;
                $rows[] = [$line, '—', 'error', '—', $record['error'], '—'];

                continue;
            }

            $result = $record['data'] ?? [];
            $catalogKey = is_string($result['catalog_key'] ?? null) ? trim($result['catalog_key']) : '—';
            if ($catalogKey !== '—' && isset($seenCatalogKeys[$catalogKey])) {
                $failed++;
                $skipped += $this->option('apply') ? 1 : 0;
                $rows[] = [$line, $catalogKey, 'error', '—', __('ingredient_enrichment.command.duplicate_catalog_key'), '—'];

                continue;
            }
            if ($catalogKey !== '—') {
                $seenCatalogKeys[$catalogKey] = true;
            }

            $ingredient = Ingredient::query()
                ->withoutGlobalScopes()
                ->where('catalog_key', $catalogKey)
                ->first();

            if (! $ingredient instanceof Ingredient) {
                $failed++;
                $skipped += $this->option('apply') ? 1 : 0;
                $rows[] = [$line, $catalogKey, 'error', '—', __('ingredient_enrichment.command.catalog_key_missing'), '—'];

                continue;
            }

            if ($ingredient->owner_type !== null || $ingredient->owner_id !== null) {
                $failed++;
                $skipped += $this->option('apply') ? 1 : 0;
                $rows[] = [$line, $catalogKey, 'error', '—', __('ingredient_enrichment.command.private_ingredient'), '—'];

                continue;
            }

            $report = $this->validator->validate($result, $ingredient);
            if (! $report['valid']) {
                $failed++;
                $skipped += $this->option('apply') ? 1 : 0;
                foreach ($report['errors'] as $field => $messages) {
                    $rows[] = [$line, $catalogKey, 'error', $field, $messages[0] ?? __('ingredient_enrichment.validation.invalid'), '—'];
                }

                continue;
            }

            $normalizedResult = is_array($report['normalized']) ? $report['normalized'] : $result;
            $plan = $this->planner->plan($ingredient, $normalizedResult, $replaceFields);
            $plan['warnings'] = collect([
                ...$plan['warnings'],
                ...$report['warnings'],
                ...($normalizedResult['warnings'] ?? []),
                ...collect($normalizedResult['unresolved_questions'] ?? [])
                    ->map(fn (string $question): string => (string) __('ingredient_enrichment.warnings.unresolved', [
                        'question' => $question,
                    ]))
                    ->all(),
            ])->filter(fn (mixed $warning): bool => is_string($warning) && $warning !== '')
                ->unique()
                ->values()
                ->all();
            $planDecision = $plan['changed'] ? 'planned' : 'unchanged';
            if ($this->option('apply')) {
                try {
                    $applyResult = $this->applier->apply($plan, $normalizedResult, $replaceFields);
                    if ($applyResult['status'] === 'applied') {
                        $applied++;
                    } else {
                        $unchanged++;
                    }
                } catch (ValidationException $exception) {
                    $failed++;
                    $skipped++;
                    $rows[] = [$line, $catalogKey, 'error', 'apply', '—', $this->firstError($exception)];

                    continue;
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $skipped++;
                    $rows[] = [$line, $catalogKey, 'error', 'apply', '—', __('ingredient_enrichment.command.apply_failed')];

                    continue;
                }
            } elseif ($plan['changed']) {
                $planned++;
            } else {
                $unchanged++;
            }
            if ($plan['warnings'] !== []) {
                $warned++;
            }
            foreach ($plan['decisions'] as $decision) {
                $rows[] = [
                    $line,
                    $catalogKey,
                    $decision['decision'],
                    $decision['field'],
                    $this->displayValue($decision['current']),
                    $this->displayValue($decision['proposed']),
                ];
            }
            if ($plan['warnings'] !== []) {
                foreach ($plan['warnings'] as $warning) {
                    $rows[] = [$line, $catalogKey, 'warning', '—', $warning, '—'];
                }
            }

            if ($planDecision === 'planned' && $plan['decisions'] === []) {
                $rows[] = [$line, $catalogKey, $planDecision, '—', '—', '—'];
            }
        }

        if ($rows !== []) {
            $this->table(
                [
                    __('ingredient_enrichment.command.headers.line'),
                    __('ingredient_enrichment.command.headers.catalog_key'),
                    __('ingredient_enrichment.command.headers.decision'),
                    __('ingredient_enrichment.command.headers.field'),
                    __('ingredient_enrichment.command.headers.current'),
                    __('ingredient_enrichment.command.headers.proposed'),
                ],
                $rows,
            );
        }

        $this->info((string) __('ingredient_enrichment.command.totals', [
            'applied' => $applied,
            'planned' => $planned,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'warned' => $warned,
            'failed' => $failed,
        ]));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function firstError(ValidationException $exception): string
    {
        return collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if ($value === null) {
            return '—';
        }

        return (string) $value;
    }
}
