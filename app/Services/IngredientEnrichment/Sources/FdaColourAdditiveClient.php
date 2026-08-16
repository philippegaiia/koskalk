<?php

namespace App\Services\IngredientEnrichment\Sources;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use DOMDocument;
use DOMElement;
use DOMXPath;

class FdaColourAdditiveClient
{
    public function __construct(private readonly CachedIngredientSourceHttpClient $http) {}

    /**
     * @param  array{ci?: string|null, names?: list<string>}  facts
     */
    public function lookup(array $facts): IngredientSourceStageResult
    {
        $source = config('ingredient-enrichment.sources.fda_colours');
        $response = $this->http->text(
            source: 'fda_colours',
            url: (string) $source['url'],
            query: [],
            version: 'fda-colours-parser-v1',
            ttl: now()->addDays((int) $source['ttl_days']),
        );
        $matches = collect($this->parse($response->payload))
            ->filter(fn (array $colour): bool => $this->matchesKnownName($colour, $facts['names'] ?? []))
            ->values()
            ->all();

        if ($matches === []) {
            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::UsDeclaration,
                status: 'completed',
                data: ['matches' => []],
                unresolvedQuestions: [__('ingredient_enrichment.warnings.us_colour_declaration_unresolved')],
                sourceCalls: $response->sourceCalls,
            );
        }

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::UsDeclaration,
            status: 'completed',
            data: ['matches' => $matches],
            evidence: collect($matches)
                ->map(fn (array $match): array => [
                    'field' => 'proposal.market_labels.us.declaration_name',
                    'source_name' => 'FDA color additives permitted for use in cosmetics',
                    'source_url' => $match['cfr_url'],
                    'source_tier' => 'official',
                    'confidence' => 'verified',
                    'source_version' => 'FDA colour additives table',
                    'source_updated_at' => null,
                    'retrieved_at' => $response->retrievedAt->toIso8601String(),
                ])
                ->all(),
            sourceCalls: $response->sourceCalls,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(mixed $html): array
    {
        if (! is_string($html)) {
            return [];
        }

        $document = new DOMDocument;
        if (@$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING) === false) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $colours = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $headers = $this->headers($xpath, $table);
            foreach ($xpath->query('.//tr[td]', $table) ?: [] as $row) {
                if (! $row instanceof DOMElement) {
                    continue;
                }

                $cells = $row->getElementsByTagName('td');
                $values = collect(range(0, $cells->length - 1))
                    ->mapWithKeys(fn (int $index): array => [
                        $headers[$index] ?? (string) $index => $this->text($cells->item($index)?->textContent),
                    ]);
                $name = (string) $values->get('color additive', '');
                if ($name === '') {
                    continue;
                }

                $cfrCell = $this->cellByHeader($headers, $cells, 'cfr');
                $link = $cfrCell?->getElementsByTagName('a')->item(0);
                $heading = $xpath->evaluate('string(preceding::*[self::h1 or self::h2 or self::h3][1])', $table);
                $declarationName = trim(preg_replace('/\s*\([^)]*\)/u', '', $name) ?? $name);

                $colours[] = [
                    'declaration_name' => $declarationName,
                    'aliases' => $this->aliases($name),
                    'certification_required' => ! str_contains($this->normalize((string) $heading), 'certification exempt'),
                    'eye_area' => $this->yes($values->get('area of the eye', '')),
                    'generally' => $this->yes($values->get('generally', '')),
                    'external_use' => $this->yes($values->get('externally applied cosmetics', '')),
                    'limitations' => $values->get('limitations'),
                    'cfr_url' => $link instanceof DOMElement ? $link->getAttribute('href') : '',
                ];
            }
        }

        return $colours;
    }

    /**
     * @return list<string>
     */
    private function headers(DOMXPath $xpath, DOMElement $table): array
    {
        $headerRow = $xpath->query('.//tr[th][1]', $table)?->item(0);
        if (! $headerRow instanceof DOMElement) {
            return [];
        }

        return collect($headerRow->getElementsByTagName('th'))
            ->map(fn (DOMElement $header): string => $this->normalize($header->textContent))
            ->all();
    }

    private function cellByHeader(array $headers, \DOMNodeList $cells, string $header): ?DOMElement
    {
        $index = collect($headers)->search(
            fn (string $candidate): bool => $candidate === $header || str_contains($candidate, $header),
        );
        $cell = $index === false ? null : $cells->item($index);

        return $cell instanceof DOMElement ? $cell : null;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, mixed>  $colour
     */
    private function matchesKnownName(array $colour, array $names): bool
    {
        $knownNames = collect($names)
            ->map(fn (string $name): string => $this->normalize($name))
            ->filter()
            ->all();

        if ($knownNames === []) {
            return false;
        }

        return collect([$colour['declaration_name'], ...$colour['aliases']])
            ->map(fn (string $name): string => $this->normalize($name))
            ->contains(fn (string $name): bool => in_array($name, $knownNames, true));
    }

    /**
     * @return list<string>
     */
    private function aliases(string $name): array
    {
        preg_match_all('/\(([^)]*)\)/u', $name, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $alias): string => trim($alias))
            ->filter()
            ->values()
            ->all();
    }

    private function yes(mixed $value): bool
    {
        return $this->normalize((string) $value) === 'yes';
    }

    private function text(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower($this->text($value));
    }
}
