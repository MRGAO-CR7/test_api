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
 * Fetches the issuer's JWKS document and caches the parsed key set.
 *
 * Why we cache aggressively (default 1h):
 *
 *   - JWKS rotation is rare and well-announced. The cost of a remote fetch
 *     on every request would be huge.
 *   - On a signature failure the verifier calls `flush()` and retries once,
 *     so an unannounced rotation only affects a single request.
 *
 * Failure modes that bubble up:
 *
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

        /** @var array<string, Key> $keys */
        $keys = $this->cache->remember(
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

                /** @var array<string, mixed> $jwks */
                $jwks = $response->json();

                // JWK::parseKeySet returns a map keyed by `kid` => Key.
                return JWK::parseKeySet($jwks, $this->config['algorithms'][0]);
            },
        );

        return $keys;
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
