<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\User\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Centralised rate-limiter definitions for test_api.
 *
 * We use Laravel's named limiter API and refer to limiter names from
 * `routes/api.php` via `throttle:<name>`. Each limiter returns the bucket
 * key we want to count against -- choosing per-uuid for authenticated
 * routes (so we are throttling the *user*, not the BFF IP that fronts
 * everyone) and per-IP for unauthenticated routes.
 *
 * Failure response: when the bucket overflows, Laravel throws a
 * ThrottleRequestsException which our `ExceptionRenderer` catches and
 * converts into a `too_many_requests` envelope (with `Retry-After`).
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Authenticated /api/v1/* — bucket per user uuid. Default 60/min,
        // overridable via env so an operator can tighten or relax during
        // an incident without a redeploy.
        RateLimiter::for('api_user', function (Request $request): Limit {
            $perMinute = (int) config('rate_limit.api_user_per_minute', 60);

            $user = $request->attributes->get('auth.user');
            if ($user instanceof User) {
                return Limit::perMinute($perMinute)->by('uuid:'.$user->uuid);
            }

            // The auth.jwt middleware will reject anonymous traffic before
            // it reaches a `throttle:api_user` group, so we should never
            // actually hit this branch -- but if we do (route mis-wiring),
            // fall back to per-IP so we never accidentally lift the cap.
            return Limit::perMinute($perMinute)->by('ip:'.$request->ip());
        });

        // Public, unauthenticated /api/v1/test/health and /api/v1/test/ready -- per
        // IP, looser ceiling because monitoring agents poll these often.
        RateLimiter::for('public', function (Request $request): Limit {
            $perMinute = (int) config('rate_limit.public_per_minute', 120);

            return Limit::perMinute($perMinute)->by('ip:'.$request->ip());
        });
    }
}
