<?php

declare(strict_types=1);

namespace App\Services\IngredientEnrichment;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

class SourcePublisherDomainResolver
{
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
            $this->rules = Rules::fromPath(
                $this->rulesPath ?? resource_path('data/public-suffix-list.dat'),
            );
        } catch (Throwable) {
            $this->rules = null;
        }

        return $this->rules;
    }
}
