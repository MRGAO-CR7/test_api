<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rate-limit ceilings for test_api
|--------------------------------------------------------------------------
|
| Tunable per-environment. Override via .env:
|   RATE_LIMIT_API_USER_PER_MINUTE
|   RATE_LIMIT_PUBLIC_PER_MINUTE
|
| Set very high (e.g. 1_000_000) to effectively disable a limiter without
| changing routes. Set 0 to refuse all traffic under that limiter (useful
| during a maintenance window).
|
*/

return [
    'api_user_per_minute' => (int) env('RATE_LIMIT_API_USER_PER_MINUTE', 60),
    'public_per_minute' => (int) env('RATE_LIMIT_PUBLIC_PER_MINUTE', 120),
];
