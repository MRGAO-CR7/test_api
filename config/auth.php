<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication configuration (intentionally minimal)
|--------------------------------------------------------------------------
|
| test_api does NOT use Laravel's session/eloquent auth stack. Identity is
| asserted by an external JWT verified in `App\Http\Middleware\VerifyEntraJwt`
| (Phase 4) and the resolved user is attached to the request by
| `App\Http\Middleware\ResolveCurrentUser` (Phase 5).
|
| We keep this file present so that any framework code that calls
| `config('auth.defaults.guard')` doesn't blow up — but we deliberately do
| NOT register any user provider, guard, or password broker. If you ever
| see a reference to `Auth::user()` in this codebase, it is a bug.
|
*/

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'jwt'),
        'passwords' => 'users',
    ],

    'guards' => [
        // No usable guards. Phase 4 wires authentication via the
        // VerifyEntraJwt middleware, not via Laravel guards.
    ],

    'providers' => [
        // No Eloquent user provider — see file-level note above.
    ],

    'passwords' => [
        // We never reset passwords. test_api never stores them.
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
