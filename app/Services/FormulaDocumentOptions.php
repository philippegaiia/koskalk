<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FormulaDocumentOptions
{
    /**
     * @var list<string>
     */
    private const SECTION_IDS = [
        'formula',
        'procedure',
        'label',
        'composition',
        'costing',
        'analysis',
        'notes',
    ];

    /**
     * @param  array<string, bool>  $contentAvailability
     * @return list<string>
     */
    public function availableSections(array $contentAvailability): array
    {
        return collect(self::SECTION_IDS)
            ->filter(fn (string $section): bool => in_array($section, ['formula', 'notes'], true)
                || (bool) ($contentAvailability[$section] ?? false))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $availableSections
     * @return list<string>
     */
    public function defaultSections(array $availableSections, string $context = 'formula'): array
    {
        $defaults = match ($context) {
            'instructions' => ['formula', 'procedure'],
            default => ['formula', 'procedure', 'label', 'notes'],
        };

        return collect(self::SECTION_IDS)
            ->filter(fn (string $section): bool => in_array($section, $defaults, true)
                && in_array($section, $availableSections, true))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $availableSections
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function resolveSelection(mixed $requestedSections, array $availableSections): array
    {
        if (! is_array($requestedSections)) {
            throw ValidationException::withMessages([
                'sections' => __('formula_documents.validation.sections_array'),
            ]);
        }

        $requestedSections = collect($requestedSections)
            ->filter(fn (mixed $section): bool => $section !== null);

        if ($requestedSections->contains(fn (mixed $section): bool => ! is_string($section))) {
            throw ValidationException::withMessages([
                'sections' => __('formula_documents.validation.sections_array'),
            ]);
        }

        $requestedSections = $requestedSections
            ->map(fn (string $section): string => trim($section))
            ->filter(fn (string $section): bool => $section !== '')
            ->unique()
            ->values();

        if ($requestedSections->isEmpty()) {
            throw ValidationException::withMessages([
                'sections' => __('formula_documents.validation.sections_required'),
            ]);
        }

        if ($requestedSections->contains(fn (string $section): bool => ! in_array($section, self::SECTION_IDS, true))) {
            throw ValidationException::withMessages([
                'sections' => __('formula_documents.validation.sections_unknown'),
            ]);
        }

        if ($requestedSections->contains(fn (string $section): bool => ! in_array($section, $availableSections, true))) {
            throw ValidationException::withMessages([
                'sections' => __('formula_documents.validation.sections_unavailable'),
            ]);
        }

        return collect(self::SECTION_IDS)
            ->filter(fn (string $section): bool => $requestedSections->contains($section))
            ->values()
            ->all();
    }
}
