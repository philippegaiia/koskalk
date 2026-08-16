<?php

namespace App\Services\IngredientEnrichment\Sources;

use App\Data\IngredientSourceResponse;
use App\Services\IngredientEnrichment\IngredientSourceException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CachedIngredientSourceHttpClient
{
    /**
     * @param  array<string, scalar|null>  $query
     */
    public function json(
        string $source,
        string $url,
        array $query,
        string $version,
        DateTimeInterface $ttl,
    ): IngredientSourceResponse {
        return $this->get($source, $url, $query, $version, $ttl, true);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function text(
        string $source,
        string $url,
        array $query,
        string $version,
        DateTimeInterface $ttl,
    ): IngredientSourceResponse {
        return $this->get($source, $url, $query, $version, $ttl, false);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(
        string $source,
        string $url,
        array $query,
        string $version,
        DateTimeInterface $ttl,
        bool $expectsJson,
    ): IngredientSourceResponse {
        $this->assertConfiguredHost($source, $url);

        $normalizedQuery = $this->normalizeQuery($query);
        $requestedUrl = $this->requestedUrl($url, $normalizedQuery);
        $cacheKey = $this->cacheKey($source, $requestedUrl, $version, $expectsJson);
        $cached = cache()->get($cacheKey);

        if (is_array($cached) && array_key_exists('payload', $cached)) {
            return new IngredientSourceResponse(
                payload: $cached['payload'],
                status: (int) $cached['status'],
                url: (string) $cached['url'],
                retrievedAt: CarbonImmutable::parse((string) $cached['retrieved_at']),
                cacheHit: true,
                sourceCalls: 0,
            );
        }

        try {
            $request = Http::connectTimeout((int) config('ingredient-enrichment.source_transport.connect_timeout_seconds'))
                ->timeout((int) config('ingredient-enrichment.source_transport.timeout_seconds'))
                ->retry([100, 500], when: function (Throwable $exception, PendingRequest $request): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->serverError()));
                }, throw: false);

            $response = $expectsJson
                ? $request->acceptJson()->get($url, $normalizedQuery)
                : $request->accept('text/html,application/xhtml+xml')->get($url, $normalizedQuery);
        } catch (Throwable) {
            throw new IngredientSourceException($source);
        }

        if ($response->failed()) {
            throw new IngredientSourceException($source, $response->status());
        }

        $payload = $expectsJson ? $response->json() : $response->body();
        if (($expectsJson && ! is_array($payload)) || (! $expectsJson && ! is_string($payload))) {
            throw new IngredientSourceException($source, $response->status());
        }

        $retrievedAt = now()->toImmutable();
        cache()->put($cacheKey, [
            'payload' => $payload,
            'status' => $response->status(),
            'url' => $requestedUrl,
            'retrieved_at' => $retrievedAt->toIso8601String(),
        ], $ttl);

        return new IngredientSourceResponse(
            payload: $payload,
            status: $response->status(),
            url: $requestedUrl,
            retrievedAt: $retrievedAt,
            cacheHit: false,
            sourceCalls: 1,
        );
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, scalar|null>
     */
    private function normalizeQuery(array $query): array
    {
        ksort($query);

        return $query;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function requestedUrl(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function cacheKey(string $source, string $url, string $version, bool $expectsJson): string
    {
        return 'ingredient-enrichment:source:'.hash('sha256', json_encode([
            'source' => $source,
            'url' => $url,
            'version' => $version,
            'response_shape_version' => config('ingredient-enrichment.source_transport.response_shape_version'),
            'expects_json' => $expectsJson,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertConfiguredHost(string $source, string $url): void
    {
        $configuredUrl = config("ingredient-enrichment.sources.{$source}.base_url")
            ?? config("ingredient-enrichment.sources.{$source}.url");
        $configuredHost = is_string($configuredUrl) ? parse_url($configuredUrl, PHP_URL_HOST) : null;
        $requestedHost = parse_url($url, PHP_URL_HOST);

        if (! is_string($configuredHost) || ! is_string($requestedHost)
            || ! hash_equals(mb_strtolower($configuredHost), mb_strtolower($requestedHost))) {
            throw new IngredientSourceException($source);
        }
    }
}
