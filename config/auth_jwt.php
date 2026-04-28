<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| External JWT verification configuration
|--------------------------------------------------------------------------
|
| test_api locally verifies JWTs minted by auth_service / Microsoft Entra
| External ID. We never call the issuer over HTTP from the request path —
| public keys are fetched once via JWKS, cached, and rotated on demand.
|
| Required env in production:
|
|   JWT_JWKS_URI       — full URL to the JWKS endpoint
|   JWT_ISSUER         — exact `iss` value the tokens carry
|   JWT_AUDIENCE       — your API's app id URI in Entra (e.g. api://<guid>).
|                        Accepts a comma-separated list to allow multiple
|                        formats during a rollout, e.g.
|                        "api://<guid>,<guid>".
|
| Optional knobs are documented inline below.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | JWKS endpoint
    |--------------------------------------------------------------------------
    | Where to fetch the issuer's public keys. The keys are cached for
    | `cache_ttl` seconds and refreshed automatically; on a signature
    | verification failure we flush once and retry, to absorb key rotation
    | without a service restart.
    */

    'jwks_uri' => env('JWT_JWKS_URI'),

    /*
    |--------------------------------------------------------------------------
    | Token claim assertions
    |--------------------------------------------------------------------------
    | The verifier hard-checks these on every request. A missing or
    | mismatched value is a 401 — never a 200, never a 5xx.
    */

    'issuer' => env('JWT_ISSUER'),

    // JWT_AUDIENCE accepts a comma-separated list. The verifier requires *any*
    // of these to appear in the token's `aud` claim. Useful while rolling
    // between v1/v2 token formats or app-id-uri vs bare-guid styles.
    'audience' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', (string) env('JWT_AUDIENCE', ''))
        ),
        static fn (string $value): bool => $value !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Algorithms allowed for verification
    |--------------------------------------------------------------------------
    | Whitelist. If the JWT's `alg` header is not in this list the token is
    | rejected before any signature check — defends against alg-confusion
    | attacks (e.g. swapping RS256 for HS256 with the public key as a
    | "shared secret").
    */

    'algorithms' => ['RS256'],

    /*
    |--------------------------------------------------------------------------
    | Clock skew tolerance (seconds)
    |--------------------------------------------------------------------------
    | Forwarded to firebase/php-jwt's `JWT::$leeway`. 30s is enough for the
    | usual NTP drift between issuer and verifier without weakening
    | exp/nbf in any meaningful way.
    */

    'leeway' => (int) env('JWT_LEEWAY', 30),

    /*
    |--------------------------------------------------------------------------
    | JWKS cache TTL (seconds)
    |--------------------------------------------------------------------------
    | Long-ish cache is fine because we always retry once on signature
    | failure (which flushes). Short cache just means more egress to the
    | issuer for no security benefit.
    */

    'cache_ttl' => (int) env('JWT_JWKS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP timeout when fetching JWKS (seconds)
    |--------------------------------------------------------------------------
    */

    'http_timeout' => (int) env('JWT_HTTP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Claim name mapping
    |--------------------------------------------------------------------------
    | Different IdPs put the user's stable identity in different claims.
    |
    | For Microsoft Entra (External ID / CIAM) we default to `oid`:
    |   - `oid` is a tenant-scoped GUID (CHAR(36)), stable across apps in
    |     the tenant, recommended by Microsoft as the user identifier.
    |   - `sub` is a per-app pairwise pseudonymous identifier (PPID): an
    |     opaque ~43-character base64url string. It is stable, but its
    |     length will overflow our `users.uuid` CHAR(36) column.
    |
    | Override per env when running against a non-Entra IdP that prefers
    | the OIDC standard `sub`.
    */

    'claims' => [
        'uuid' => env('JWT_UUID_CLAIM', 'oid'),
        'email' => env('JWT_EMAIL_CLAIM', 'email'),
        'first_name' => env('JWT_FIRST_NAME_CLAIM', 'given_name'),
        'last_name' => env('JWT_LAST_NAME_CLAIM', 'family_name'),
    ],
];
