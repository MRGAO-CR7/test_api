<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| In our deployment, browsers never talk to test_api directly -- they go
| through `test_frontend`'s BFF, which runs server-side and proxies the
| call. So in production CORS would technically be unused. We still
| configure it tightly for two reasons:
|
|   1. Defense in depth. If someone accidentally exposes test_api to the
|      public internet, we don't want browsers from arbitrary origins to
|      be able to use it as a CORS-permitted target.
|   2. Local dev. Sometimes it's useful to hit the API directly from a
|      browser (eg. a Postman-like extension), so we explicitly allow
|      that for the dev origin only.
|
| The list of allowed origins comes from CORS_ALLOWED_ORIGINS as a
| comma-separated env var. Empty / unset = no origins allowed = browser
| cross-origin requests are rejected.
|
*/

$origins = array_values(array_filter(array_map(
    static fn (string $o): string => trim($o),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

return [

    /*
    | Apply CORS to /api/* and the framework's /up healthcheck. We
    | deliberately do NOT include the dev `_ignition` debug routes etc.
    */
    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    /*
    | Tight allow list of inbound headers. Anything not in this list is
    | rejected by the browser preflight. Matches the BFF proxy's
    | forwarded-header set in `test_frontend`'s catch-all route.
    */
    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'X-Request-Id',
    ],

    /*
    | Headers the browser is allowed to read off our responses. Only
    | X-Request-Id is interesting cross-origin; everything else is hidden
    | by the browser's default CORS rules.
    */
    'exposed_headers' => ['X-Request-Id'],

    /*
    | We keep this at 0 -- credentials (cookies, HTTP auth) are NOT used
    | by this API. Identity comes exclusively from the `Authorization:
    | Bearer ...` header, which is not subject to the credentials flag.
    */
    'max_age' => 600,

    'supports_credentials' => false,

];
