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
     * @param  array<string, string>  $headers
     */
    public function text(
        string $source,
        string $url,
        array $query,
        string $version,
        DateTimeInterface $ttl,
        array $headers = [],
        bool $cacheResponse = true,
    ): IngredientSourceResponse {
        return $this->get($source, $url, $query, $version, $ttl, false, $headers, $cacheResponse);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     */
    private function get(
        string $source,
        string $url,
        array $query,
        string $version,
        DateTimeInterface $ttl,
        bool $expectsJson,
        array $headers = [],
        bool $cacheResponse = true,
    ): IngredientSourceResponse {
        $this->assertConfiguredHost($source, $url);

        $normalizedQuery = $this->normalizeQuery($query);
        $requestedUrl = $this->requestedUrl($url, $normalizedQuery);
        $cacheKey = $this->cacheKey($source, $requestedUrl, $version, $expectsJson);
        $cached = $cacheResponse ? cache()->get($cacheKey) : null;

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

            $request = $expectsJson
                ? $request->acceptJson()
                : $request->accept('text/html,application/xhtml+xml');
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }
            $response = $normalizedQuery === []
                ? $request->get($url)
                : $request->get($url, $normalizedQuery);
        } catch (Throwable) {
            throw new IngredientSourceException($source);
        }

        // A 202 is an anti-bot challenge or deferred job, never the requested record.
        if ($response->failed() || $response->status() === 202) {
            throw new IngredientSourceException($source, $response->status());
        }

        $payload = $expectsJson ? $response->json() : $response->body();
        if (($expectsJson && ! is_array($payload))
            || (! $expectsJson && (! is_string($payload) || trim($payload) === ''))) {
            throw new IngredientSourceException($source, $response->status());
        }

        $retrievedAt = now()->toImmutable();
        if ($cacheResponse) {
            cache()->put($cacheKey, [
                'payload' => $payload,
                'status' => $response->status(),
                'url' => $requestedUrl,
                'retrieved_at' => $retrievedAt->toIso8601String(),
            ], $ttl);
        }

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
        $requestedHost = parse_url($url, PHP_URL_HOST);
        $configuredHosts = collect(['fetch_url', 'base_url', 'url'])
            ->map(fn (string $key): mixed => config("ingredient-enrichment.sources.{$source}.{$key}"))
            ->filter(fn (mixed $configuredUrl): bool => is_string($configuredUrl))
            ->map(fn (string $configuredUrl): mixed => parse_url($configuredUrl, PHP_URL_HOST))
            ->filter(fn (mixed $host): bool => is_string($host));

        if (! is_string($requestedHost)
            || ! $configuredHosts->contains(fn (string $host): bool => hash_equals(mb_strtolower($host), mb_strtolower($requestedHost)))) {
            throw new IngredientSourceException($source);
        }
    }
}
