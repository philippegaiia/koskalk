<?php

namespace App\Services\IngredientEnrichment;

use JsonException;
use RuntimeException;
use SplFileObject;

class IngredientEnrichmentJsonl
{
    /**
     * @return array<int, array{line: int, data: array<string, mixed>|null, error: string|null}>
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Enrichment JSONL file [{$path}] does not exist.");
        }

        $file = new SplFileObject($path, 'rb');
        $records = [];

        while (! $file->eof()) {
            $lineNumber = $file->key() + 1;
            $line = $file->fgets();

            if ($line === false || trim($line) === '') {
                continue;
            }

            $records[] = $this->decodeLine($lineNumber, $line);
        }

        return $records;
    }

    /**
     * Alias kept explicit for callers that prefer parser terminology.
     *
     * @return array<int, array{line: int, data: array<string, mixed>|null, error: string|null}>
     */
    public function parse(string $path): array
    {
        return $this->read($path);
    }

    /**
     * @return array{line: int, data: array<string, mixed>|null, error: string|null}
     */
    private function decodeLine(int $lineNumber, string $line): array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $value = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'line' => $lineNumber,
                'data' => null,
                'error' => "Line {$lineNumber} is not valid JSON: {$exception->getMessage()}",
            ];
        }

        if (! is_object($value) || ! is_array($decoded)) {
            return [
                'line' => $lineNumber,
                'data' => null,
                'error' => "Line {$lineNumber} must contain one JSON object.",
            ];
        }

        /** @var array<string, mixed> $decoded */
        return [
            'line' => $lineNumber,
            'data' => $decoded,
            'error' => null,
        ];
    }
}
