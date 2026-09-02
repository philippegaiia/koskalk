<?php

declare(strict_types=1);

namespace App\Services\IngredientEnrichment;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

class SourcePublisherDomainResolver
{
    /**
     * SHA-256 of resources/data/public-suffix-list.dat. Update this value with every reviewed snapshot refresh.
     */
    private const EXPECTED_SNAPSHOT_SHA256 = 'dd381a12555c9ce25ae5f23e79b2443594365619595eddba36e92e4e1bba3617';

    /** @var list<string> */
    private const SNAPSHOT_MARKERS = [
        '// ===BEGIN ICANN DOMAINS===',
        '// ===END ICANN DOMAINS===',
        '// ===BEGIN PRIVATE DOMAINS===',
        '// ===END PRIVATE DOMAINS===',
    ];

    private ?Rules $rules = null;

    private bool $rulesLoaded = false;

    public function __construct(private readonly ?string $rulesPath = null) {}

    public function resolve(string $url): ?string
    {
        try {
            $parts = parse_url($url);
            if (! is_array($parts)) {
                return null;
            }

            $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            $host = $parts['host'] ?? null;
            if (! is_string($host) || trim($host) === '') {
                return null;
            }

            $rules = $this->rules();
            if (! $rules instanceof Rules) {
                return null;
            }

            $resolved = $rules->resolve(Domain::fromIDNA2008($host));
            if (! $resolved->suffix()->isKnown() || ! $resolved->suffix()->isPublicSuffix()) {
                return null;
            }

            $registrableDomain = $resolved->registrableDomain()->toAscii()->value();
            if (! is_string($registrableDomain) || trim($registrableDomain) === '') {
                return null;
            }

            return mb_strtolower(rtrim($registrableDomain, '.'));
        } catch (Throwable) {
            return null;
        }
    }

    private function rules(): ?Rules
    {
        if ($this->rulesLoaded) {
            return $this->rules;
        }

        $this->rulesLoaded = true;

        try {
            $snapshotPath = $this->rulesPath ?? resource_path('data/public-suffix-list.dat');
            $snapshot = @file_get_contents($snapshotPath);

            if (! is_string($snapshot) || ! $this->isTrustedSnapshot($snapshot)) {
                return null;
            }

            $this->rules = Rules::fromString($snapshot);
        } catch (Throwable) {
            $this->rules = null;
        }

        return $this->rules;
    }

    private function isTrustedSnapshot(string $snapshot): bool
    {
        if (! $this->hasCompleteSnapshotStructure($snapshot)) {
            return false;
        }

        return hash_equals(self::EXPECTED_SNAPSHOT_SHA256, hash('sha256', $snapshot));
    }

    private function hasCompleteSnapshotStructure(string $snapshot): bool
    {
        $lines = preg_split('/\R/', $snapshot);
        if (! is_array($lines)) {
            return false;
        }

        $section = null;
        $hasRules = [
            'ICANN' => false,
            'PRIVATE' => false,
        ];
        $markerIndex = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($markerIndex < count(self::SNAPSHOT_MARKERS) && $line === self::SNAPSHOT_MARKERS[$markerIndex]) {
                if ($markerIndex % 2 === 0) {
                    $section = $markerIndex === 0 ? 'ICANN' : 'PRIVATE';
                } else {
                    if ($section === null || ! $hasRules[$section]) {
                        return false;
                    }

                    $section = null;
                }

                $markerIndex++;

                continue;
            }

            if (in_array($line, self::SNAPSHOT_MARKERS, true)) {
                return false;
            }

            if ($section !== null && ! str_starts_with($line, '//')) {
                $hasRules[$section] = true;
            }
        }

        return $markerIndex === count(self::SNAPSHOT_MARKERS)
            && $section === null
            && $hasRules['ICANN']
            && $hasRules['PRIVATE'];
    }
}
