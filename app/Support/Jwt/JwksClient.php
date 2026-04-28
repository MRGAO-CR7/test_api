<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use App\Support\Jwt\Exceptions\InvalidJwtException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Fetches the issuer's JWKS document and caches it.
 *
 * Cache strategy
 * --------------
 * We cache the **raw JWKS JSON document** (a plain array of scalars + arrays)
 * — NOT the `Firebase\JWT\Key` objects. The reason is critical:
 *
 *   `Key` wraps an `OpenSSLAsymmetricKey` resource, which PHP REFUSES to
 *   serialize. Putting Key objects into Laravel's file/redis/database cache
 *   would silently fail on `serialize()` and re-fetch on every request,
 *   completely defeating the cache.
 *
 * So:
 *   - Cache miss  -> HTTP GET the JWKS, store the *array* in the cache.
 *   - Cache hit   -> read the array, run JWK::parseKeySet() to materialise
 *                    fresh `Key` objects in memory. Parsing is microseconds;
 *                    network is milliseconds. Net win is enormous.
 *
 * Failure modes that bubble up:
 *   - `auth_not_configured` if no JWKS URI is set in config (we deliberately
 *     hard-fail rather than silently allow unsigned tokens through).
 *   - Any HTTP / parsing error becomes a generic `signature_invalid` to the
 *     caller — we never leak issuer-side error details into client responses.
 */
final class JwksClient implements JwksProvider
{
    /**
     * @param  array{jwks_uri: ?string, algorithms: list<string>, cache_ttl: int, http_timeout: int}  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    /**
     * @return array<string, Key>
     */
    public function getKeys(): array
    {
        $uri = $this->config['jwks_uri'] ?? null;
        if ($uri === null || $uri === '') {
            throw InvalidJwtException::notConfigured();
        }

        $cacheKey = self::cacheKey($uri);

        /** @var array<string, mixed> $jwks */
        $jwks = $this->cache->remember(
            $cacheKey,
            $this->config['cache_ttl'],
            function () use ($uri): array {
                try {
                    $response = $this->http
                        ->timeout($this->config['http_timeout'])
                        ->acceptJson()
                        ->get($uri)
                        ->throw();
                } catch (Throwable $e) {
                    throw InvalidJwtException::badSignature($e);
                }

                /** @var array<string, mixed> $body */
                $body = $response->json();

                return $body;
            },
        );

        // Parse on every call — cheap, and decoupling from the cache layer
        // keeps the cache contents serialisable across redis / file / db.
        return JWK::parseKeySet($jwks, $this->config['algorithms'][0]);
    }

    public function flush(): void
    {
        $uri = $this->config['jwks_uri'] ?? null;
        if ($uri === null || $uri === '') {
            return;
        }
        $this->cache->forget(self::cacheKey($uri));
    }

    private static function cacheKey(string $uri): string
    {
        return 'auth_jwt:jwks:'.hash('sha256', $uri);
    }
}
