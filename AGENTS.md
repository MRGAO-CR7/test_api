# AGENTS.md — test_api

Operating notes for AI coding agents working in this repo.

## What this service is

Stateless Laravel 13 JSON API. Sits behind `test_frontend`'s BFF. **Never**
signs tokens, **never** stores passwords, **never** calls `auth_service` over
HTTP from a request path.

## Hard rules

- **Strict types**: every PHP file starts with `declare(strict_types=1);`.
  Pint enforces it.
- **No HTML**: this service does not serve views. Don't add a Blade template,
  don't add a session middleware, don't add CSRF.
- **No `Authenticatable` model**: we don't use Laravel's built-in auth provider.
  Auth is a custom middleware stack (P4 + P5).
- **Stable identity is `users.uuid`**, not `users.email`. Look up by uuid, only
  *update* email/first_name/last_name from JWT claims.
- **Error shape is fixed**: route through `App\Support\Http\ApiErrorEnvelope`.
  Never return a bare `{message: ...}` or HTML 500 page.
- **API path is `/api/v1/...`**. Future versions live in `routes/api.php` under
  a sibling `Route::prefix('v2')` group. Don't break v1 in place.

## Phase discipline

Work one phase at a time (see README phase status). A phase is shippable when:

1. Its acceptance command(s) pass.
2. `composer check` (lint + analyse + test) is green.
3. README phase checkbox is moved to `[x]`.

Don't start phase N+1 work in a phase N change.

## Don't

- Don't edit `test_frontend` from this repo. They are deliberately decoupled.
- Don't add `laravel/sanctum` or `laravel/passport`. We don't sign tokens.
- Don't introduce a queue / Redis / Pulse dependency without a phase plan.
- Don't add a route outside `routes/api.php` (the BFF only proxies to /api).
