<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use Firebase\JWT\Key;

/**
 * Source of the public keys used to verify incoming JWTs.
 *
 * The production implementation (`JwksClient`) fetches and caches a JWKS
 * document from a remote IdP. Tests bind an in-memory implementation that
 * returns a locally-generated keypair, so the verifier can be exercised
 * without any HTTP traffic.
 */
interface JwksProviderInterface
{
    /**
     * @return array<string, Key> Keyed by `kid`.
     */
    public function getKeys(): array;

    /**
     * Drop the cached key set. The next call to `getKeys()` will refetch.
     */
    public function flush(): void;
}
