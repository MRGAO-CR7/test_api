<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Jwt\EntraJwtVerifier;
use App\Support\Jwt\JwksClient;
use App\Support\Jwt\JwksProviderInterface;
use App\Support\Jwt\JwtVerifierInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the JWT verification stack.
 *
 *   JwtVerifierInterface
 *       └── EntraJwtVerifier (default)
 *               └── JwksProviderInterface
 *                       └── JwksClient (default; HTTP + cache)
 *
 * Tests bind a stub `JwksProviderInterface` (or, for end-to-end use cases,
 * a stub `JwtVerifierInterface` directly) and the rest of the pipeline
 * stays unchanged.
 */
final class JwtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind (not singleton) so tests can swap the JwksProviderInterface with a stub
        // and the next resolution of EntraJwtVerifier picks it up. The
        // expensive bit (HTTP + parse) is memoised by the Laravel Cache
        // layer inside JwksClient, not by container reuse.
        $this->app->bind(JwksProviderInterface::class, function ($app): JwksClient {
            /** @var array{jwks_uri: ?string, algorithms: list<string>, cache_ttl: int, http_timeout: int} $cfg */
            $cfg = [
                'jwks_uri' => config('auth_jwt.jwks_uri'),
                'algorithms' => (array) config('auth_jwt.algorithms', ['RS256']),
                'cache_ttl' => (int) config('auth_jwt.cache_ttl', 3600),
                'http_timeout' => (int) config('auth_jwt.http_timeout', 5),
            ];

            return new JwksClient(
                $app->make(CacheRepository::class),
                $app->make(HttpFactory::class),
                $cfg,
            );
        });

        // Bind the verifier behind its interface. Middleware and controllers
        // should depend on JwtVerifierInterface, never on EntraJwtVerifier
        // directly, so swapping issuers (or stubbing in tests) is a
        // one-line change.
        $this->app->bind(JwtVerifierInterface::class, function ($app): EntraJwtVerifier {
            /** @var array{issuer: ?string, audience: string|list<string>|null, algorithms: list<string>, leeway: int, claims: array{uuid: string, email: string, first_name: string, last_name: string}} $cfg */
            $cfg = [
                'issuer' => config('auth_jwt.issuer'),
                'audience' => config('auth_jwt.audience'),
                'algorithms' => (array) config('auth_jwt.algorithms', ['RS256']),
                'leeway' => (int) config('auth_jwt.leeway', 30),
                'claims' => (array) config('auth_jwt.claims', [
                    'uuid' => 'sub',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                ]),
            ];

            return new EntraJwtVerifier($app->make(JwksProviderInterface::class), $cfg);
        });
    }
}
