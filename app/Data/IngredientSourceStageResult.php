<?php

namespace App\Data;

use App\Enums\IngredientEnrichmentResearchStage;

final readonly class IngredientSourceStageResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<string>  $warnings
     * @param  list<string>  $unresolvedQuestions
     */
    public function __construct(
        public IngredientEnrichmentResearchStage $stage,
        public string $status,
        public array $data,
        public array $evidence = [],
        public array $warnings = [],
        public array $unresolvedQuestions = [],
        public int $sourceCalls = 0,
    ) {}

    /**
     * @return array{
     *     stage: string,
     *     status: string,
     *     data: array<string, mixed>,
     *     evidence: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     unresolved_questions: list<string>,
     *     source_calls: int
     * }
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'status' => $this->status,
            'data' => $this->data,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'unresolved_questions' => $this->unresolvedQuestions,
            'source_calls' => $this->sourceCalls,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            stage: IngredientEnrichmentResearchStage::from((string) $attributes['stage']),
            status: (string) $attributes['status'],
            data: is_array($attributes['data'] ?? null) ? $attributes['data'] : [],
            evidence: is_array($attributes['evidence'] ?? null) ? $attributes['evidence'] : [],
            warnings: is_array($attributes['warnings'] ?? null) ? $attributes['warnings'] : [],
            unresolvedQuestions: is_array($attributes['unresolved_questions'] ?? null) ? $attributes['unresolved_questions'] : [],
            sourceCalls: (int) ($attributes['source_calls'] ?? 0),
        );
    }
}
