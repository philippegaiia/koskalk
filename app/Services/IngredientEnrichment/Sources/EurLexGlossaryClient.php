<?php

namespace App\Services\IngredientEnrichment\Sources;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Services\IngredientEnrichment\IngredientSourceException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;

class EurLexGlossaryClient
{
    public function __construct(private readonly CachedIngredientSourceHttpClient $http) {}

    /**
     * @param  array{inci_name?: string|null}  facts
     */
    public function verify(array $facts): IngredientSourceStageResult
    {
        $requestedNames = collect([
            'inci_name' => $facts['inci_name'] ?? null,
            'soap_inci_naoh_name' => $facts['soap_inci_naoh_name'] ?? null,
            'soap_inci_koh_name' => $facts['soap_inci_koh_name'] ?? null,
        ])->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '');
        if ($requestedNames->isEmpty()) {
            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::EuOfficial,
                status: 'completed',
                data: ['matched' => false, 'common_ingredient_name' => null],
            );
        }

        $source = config('ingredient-enrichment.sources.eur_lex_glossary');
        $response = $this->http->text(
            source: 'eur_lex_glossary',
            url: (string) $source['url'],
            query: [],
            version: (string) $source['celex'],
            ttl: now()->addDays((int) $source['ttl_days']),
        );
        $names = cache()->remember(
            'ingredient-enrichment:eur-lex-glossary:'.hash('sha256', (string) $source['celex'].':parser-v1'),
            now()->addDays((int) $source['ttl_days']),
            fn (): array => $this->parseNameMap($response->payload),
        );
        $matchedNames = $requestedNames->map(fn (string $name): ?string => $names[$this->normalize($name)] ?? null);
        $commonName = $matchedNames->get('inci_name');

        if (! is_string($commonName)) {
            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::EuOfficial,
                status: 'completed',
                data: [
                    'matched' => false,
                    'common_ingredient_name' => null,
                    ...($requestedNames->has('soap_inci_naoh_name') ? ['soap_inci_naoh_name' => $matchedNames->get('soap_inci_naoh_name')] : []),
                    ...($requestedNames->has('soap_inci_koh_name') ? ['soap_inci_koh_name' => $matchedNames->get('soap_inci_koh_name')] : []),
                ],
                evidence: $this->evidenceForMatchedNames($matchedNames, $source, $response->retrievedAt->toIso8601String()),
                sourceCalls: $response->sourceCalls,
            );
        }

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::EuOfficial,
            status: 'completed',
            data: [
                'matched' => true,
                'common_ingredient_name' => $commonName,
                ...($requestedNames->has('soap_inci_naoh_name') ? ['soap_inci_naoh_name' => $matchedNames->get('soap_inci_naoh_name')] : []),
                ...($requestedNames->has('soap_inci_koh_name') ? ['soap_inci_koh_name' => $matchedNames->get('soap_inci_koh_name')] : []),
            ],
            evidence: $this->evidenceForMatchedNames($matchedNames, $source, $response->retrievedAt->toIso8601String()),
            sourceCalls: $response->sourceCalls,
        );
    }

    /**
     * @param  Collection<string, string|null>  $matchedNames
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function evidenceForMatchedNames(Collection $matchedNames, array $source, string $retrievedAt): array
    {
        return $matchedNames->filter()->keys()->map(fn (string $field): array => [
            'field' => $field === 'inci_name' ? 'proposal.inci_name' : "proposal.{$field}",
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => (string) $source['url'],
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => (string) $source['celex'],
            'source_updated_at' => null,
            'retrieved_at' => $retrievedAt,
        ])->values()->all();
    }

    /**
     * @return array<string, string>
     */
    private function parseNameMap(mixed $html): array
    {
        if (! is_string($html)) {
            throw new IngredientSourceException('eur_lex_glossary');
        }

        $document = new DOMDocument;
        $loaded = @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($loaded === false) {
            throw new IngredientSourceException('eur_lex_glossary');
        }

        $names = [];
        $rows = (new DOMXPath($document))->query('//table//tr[td]');
        if ($rows === false) {
            return $names;
        }

        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = $row->getElementsByTagName('td');
            $lastCell = $cells->item($cells->length - 1);
            $name = $lastCell instanceof DOMElement ? $this->displayText($lastCell->textContent) : '';

            if ($name !== '') {
                $names[$this->normalize($name)] = $name;
            }
        }

        return $names;
    }

    private function displayText(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function normalize(string $value): string
    {
        return mb_strtolower($this->displayText($value));
    }
}
