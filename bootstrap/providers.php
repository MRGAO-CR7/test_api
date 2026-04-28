<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\JwtServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\UserServiceProvider;

return [
    AppServiceProvider::class,
    JwtServiceProvider::class,
    UserServiceProvider::class,
    RateLimitServiceProvider::class,
];
