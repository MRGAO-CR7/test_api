# test_api

Laravel 13 (PHP 8.3+) **stateless JSON REST API** that sits behind the
`test_frontend` BFF. test_api never signs tokens of its own — it locally
verifies JWTs minted by `auth_service` (Microsoft Entra External ID proxy)
using the issuer's JWKS, and treats `users` as a read-only projection of
the auth tenant keyed by `uuid`.

```
Browser  ──►  test_frontend (BFF, :3000)  ──►  auth_service (:8008)   sign / refresh
                                          ──►  test_api (:8009)       business; verifies JWT locally
```

## Stack

| Layer | Choice |
| --- | --- |
| Framework | Laravel 13 (slim app/, no Kernel.php) |
| PHP | 8.3+ |
| DB | MySQL 9 (local: `test`, root/rootpass) |
| Auth | External JWT (Entra External ID), verified via JWKS |
| Static analysis | `larastan/larastan` (level 8) |
| Code style | `laravel/pint` (Laravel preset, strict_types) |
| Tests | `pestphp/pest` v4 |
| Local serve | `php artisan serve --port=8009` (Phase 3 swaps in nginx + docker) |

## Phase status

- [x] **Phase 1 — Scaffold + tooling skeleton** (`/api/v1/test/health`, force-JSON, error envelope, pint/phpstan/pest)
- [x] **Phase 2 — DB connection + `users` migration + Eloquent model** (MySQL `test`, `App\Domain\User\Models\User`, `UserResource`, factory, soft-delete + uuid/email unique)
- [x] **Phase 3 — Docker compose + nginx, joined to `bbm`** (`test_api_webserver:8000` ↔ host `:8009`, php-fpm 8.4 alpine, MySQL via bbm DNS `docker-mysql-1`, healthcheck green)
- [x] **Phase 4 — JWT verification middleware** (Entra JWKS, JwksProvider interface + cache + rotation retry, AuthClaims DTO with claim mapping, `auth.jwt` middleware alias, temp `/api/v1/_debug/whoami` for verification)
- [x] **Phase 5 — JIT user provisioning + `GET/PATCH /api/v1/test/me`** (UserRepository contract + Eloquent impl, UserProvisioner with race-safe upsert, `auth.user` middleware aliasing `ResolveCurrentUser`, MeController + UpdateMeRequest, `_debug/whoami` removed, 43 tests / 133 assertions green)
- [x] **Phase 6 — Hardening** (global exception → envelope mapping; `X-Request-Id` round-trip; `SecurityHeaders` middleware; tight CORS; per-uuid + per-IP rate limits with priority pinning; `/api/v1/test/ready` DB+JWKS probe; structured `audit` log channel; `.env.production.example`; 59 tests / 197 assertions green)

## Layout (target — built up across phases)

```
app/
├─ Domain/
│  └─ User/
│     ├─ Models/User.php                                  # P2
│     ├─ Repositories/UserRepository.php                  # P5 (interface)
│     ├─ Repositories/EloquentUserRepository.php          # P5 (default impl)
│     ├─ Services/UserProvisioner.php                     # P5 (JIT)
│     └─ DTOs/AuthClaims.php                              # P4
├─ Http/
│  ├─ Controllers/Api/V1/HealthController.php             # P1
│  ├─ Controllers/Api/V1/MeController.php                 # P5
│  ├─ Controllers/Api/V1/ReadinessController.php          # P6
│  ├─ Middleware/ForceJsonResponse.php                    # P1
│  ├─ Middleware/VerifyEntraJwt.php                       # P4 -> auth.jwt
│  ├─ Middleware/ResolveCurrentUser.php                   # P5 -> auth.user
│  ├─ Middleware/AssignRequestId.php                      # P6
│  ├─ Middleware/SecurityHeaders.php                      # P6
│  ├─ Requests/Api/V1/UpdateMeRequest.php                 # P5
│  └─ Resources/UserResource.php                          # P2
├─ Support/
│  ├─ Audit/AuditLog.php                                  # P6
│  ├─ Http/ApiErrorEnvelope.php                           # P1
│  ├─ Http/ExceptionRenderer.php                          # P6
│  └─ Jwt/{JwksClient,EntraJwtVerifier}.php               # P4
└─ Providers/
   ├─ JwtServiceProvider.php                              # P4
   ├─ RateLimitServiceProvider.php                        # P6
   └─ UserServiceProvider.php                             # P5
config/
├─ auth_jwt.php                                           # P4
├─ cors.php                                               # P6
└─ rate_limit.php                                         # P6
routes/
├─ api.php          # /api/v1/*
└─ web.php          # intentionally empty (no HTML)
```

## Local setup

Prereqs: PHP 8.3+, Composer 2, and one of:
  - **Host MySQL 8** on `127.0.0.1:3306` with `test` db + `root`/`rootpass`, or
  - **Docker MySQL** at `docker-mysql-1` on the shared `bbm` network (this is
    what the existing `auth_service` compose project provisions).

### Option A — host PHP (`php artisan serve`)

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate

php artisan serve --port=8009
# other terminal:
curl -s http://localhost:8009/api/v1/test/health | jq
```

### Option B — `docker compose` (joins the shared `bbm` network)

```bash
# 0) one-time: ensure the shared bridge exists (auth_service compose creates it)
docker network inspect bbm >/dev/null 2>&1 || docker network create bbm

# 1) build + boot
docker compose up --build -d
docker compose logs -f test_api_php

# 2) verify all three reach paths
curl -s http://localhost:8009/api/v1/test/health                         # host
docker run --rm --network bbm curlimages/curl \
    -s http://test_api_webserver:8000/api/v1/test/health                 # bbm peer
docker compose exec test_api_php php artisan migrate:status         # MySQL via bbm DNS

# stop without losing the vendor/ named volume:
docker compose down
# wipe everything (slower next boot):
docker compose down -v
```

What compose does:

- bind-mounts the project so edits hit php-fpm on next request;
- keeps `vendor/` on a **named volume** so the macOS-built host vendor never
  shadows the linux-built one inside the container;
- joins the external `bbm` bridge so we can reach `docker-mysql-1` and be
  reached as `http://test_api_webserver:8000` by `test_frontend`'s BFF;
- the php container's compose `environment:` block overrides `DB_HOST` to
  `docker-mysql-1` (Laravel's dotenv is `Dotenv::createImmutable()`, so any
  variable already in `process.env` wins over the `.env` file — same trick
  test_frontend uses).

## Scripts

| Command | Purpose |
| --- | --- |
| `composer dev` | `php artisan serve --port=8009` |
| `composer test` | Pest suite (uses :memory: sqlite per `phpunit.xml`) |
| `composer lint` | `pint --test` (no writes) |
| `composer lint:fix` | `pint` (rewrites in place) |
| `composer analyse` | `phpstan analyse` (level 8) |
| `composer check` | lint + analyse + test (CI gate) |

## Auth (Phase 4)

This service authenticates each request by locally verifying an external JWT.
**No call to `auth_service` happens on the request path** — public keys are
fetched once via JWKS, cached, and rotated on demand.

Stable error codes (returned in `BffErrorBody.code`) — keep stable for the SPA:

| HTTP | `code`                | Meaning                                                   |
| ---- | --------------------- | --------------------------------------------------------- |
| 401  | `missing_bearer`      | No `Authorization: Bearer …` header                       |
| 401  | `token_expired`       | `exp` past (with leeway)                                  |
| 401  | `token_not_yet_valid` | `nbf` / `iat` in the future (with leeway)                 |
| 401  | `iss_mismatch`        | `iss` does not match `JWT_ISSUER`                         |
| 401  | `aud_mismatch`        | `JWT_AUDIENCE` not in token's `aud`                       |
| 401  | `signature_invalid`   | bad signature, even after a JWKS rotation retry           |
| 401  | `alg_not_allowed`     | `alg` header not in the configured allow-list             |
| 401  | `missing_claim`       | A required claim (uuid / email) missing or empty          |
| 401  | `malformed_jwt`       | Token did not parse as a JWT                              |
| 503  | `auth_not_configured` | `JWT_JWKS_URI` is not set — operator problem, not client  |

> The 503 split is deliberate: the BFF must not log a user out on
> `auth_not_configured`. `401` means the user's session is bad; `503` means
> the API is broken.

## Endpoints (Phase 5)

The middleware stack `auth.jwt` -> `auth.user` runs on every protected
route. `auth.jwt` verifies the token and produces an `AuthClaims` DTO;
`auth.user` JIT-creates / touches the matching `users` row and attaches the
Eloquent `User` to the request.

| Method | Path | Description |
| ------ | ---- | ----------- |
| GET | `/api/v1/test/health` | Liveness, no auth |
| GET | `/api/v1/test/me` | Current user. JIT-creates the row on first call. Idempotent (always 200). |
| PATCH | `/api/v1/test/me` | Partial update of `first_name`, `last_name`, `email`. Empty body = 200 no-op. |

Sample success body (both GET and PATCH):

```json
{
  "data": {
    "uuid": "11111111-2222-3333-4444-555555555555",
    "email": "alice@example.com",
    "first_name": "Alice",
    "last_name": "Example",
    "last_seen_at": "2026-04-28T22:30:00+00:00"
  }
}
```

Sample 422 (validation failure on PATCH):

```json
{
  "ok": false,
  "code": "validation_failed",
  "message": "The given data was invalid.",
  "status": 422,
  "details": {
    "errors": {
      "email": ["The email field must be a valid email address."]
    }
  }
}
```

What is intentionally NOT in `UserResource`:
- `id` (DB-internal bigint; clients reason about users via `uuid` only)
- `last_token_jti` (server-side audit only)
- `claims_snapshot` (full token payload kept for audit / debugging)

PATCH ignores any field not on the allow list (`first_name`, `last_name`,
`email`). Sending `uuid`, `last_token_jti`, `claims_snapshot`, or `id` is a
silent no-op for those columns -- they are never client-mutable.

Required env (see `.env.example`):

```dotenv
JWT_JWKS_URI=https://<tenant>.ciamlogin.com/<tenant-id>/discovery/v2.0/keys
JWT_ISSUER=https://<tenant>.ciamlogin.com/<tenant-id>/v2.0
# Comma-separated list — Entra's `aud` may be `api://<guid>` or bare `<guid>`
# depending on token version, so we accept both.
JWT_AUDIENCE=api://<client-id>,<client-id>

# Override per env if your IdP uses different claim names. For Entra we
# prefer `oid` (tenant GUID, fits CHAR(36)) over `sub` (per-app PPID, ~43
# chars, would overflow the schema).
JWT_UUID_CLAIM=oid
JWT_EMAIL_CLAIM=email
```

Until those three are filled in, every protected route 503s — by design.

## Design rules (read before contributing)

1. **Statelessness** — no sessions, no CSRF, no cookies. `Authorization: Bearer …` is the only identity input.
2. **No reverse calls to `auth_service`** — verifying JWTs is local (JWKS + cache). Adding a sync HTTP call to auth_service from a request path is a regression.
3. **`users.uuid` is the only stable identity** — never key business records on `email`. Email can change in Entra; `uuid` cannot.
4. **Errors are JSON, always** — every non-2xx response goes through `App\Support\Http\ApiErrorEnvelope` and matches `test_frontend`'s `BffErrorBody` shape:

   ```json
   { "ok": false, "code": "snake_case", "message": "human", "status": 4xx, "details": null }
   ```
5. **`/api/v1/...` is the only public surface** — the catch-all proxy in
   `test_frontend/src/app/api/test/[...slug]/route.ts` already strips/forwards
   only `Authorization`, `Content-Type`, `Accept`, `Accept-Language`. Don't
   rely on any other inbound header.

## Operations contract (Phase 6)

### Probes

| Method | Path | Purpose | Auth | Behaviour |
| ------ | ---- | ------- | ---- | --------- |
| GET | `/api/v1/test/health` | Liveness — process is up | none | Always 200 if PHP can answer. Cheap. |
| GET | `/api/v1/test/ready` | Readiness — dependencies are usable | none | 200 if `SELECT 1` AND JWKS reachable. **503 not_ready** otherwise. Use this for k8s `readinessProbe`. |

### Headers (every response)

| Header | Source | Notes |
| ------ | ------ | ----- |
| `X-Request-Id` | `AssignRequestId` middleware | Reuses inbound if it matches `[A-Za-z0-9_-]{8,128}`, otherwise mints a UUIDv4. Echoed even on 4xx/5xx so the BFF can correlate. |
| `X-Content-Type-Options: nosniff` | `SecurityHeaders` | |
| `X-Frame-Options: DENY` | `SecurityHeaders` | Strictest setting; this is a JSON API. |
| `Referrer-Policy: no-referrer` | `SecurityHeaders` | |
| `X-Permitted-Cross-Domain-Policies: none` | `SecurityHeaders` | |

`Strict-Transport-Security` is intentionally NOT set here — that belongs at the TLS-terminating proxy in front of the container, which has the right view of HTTP vs HTTPS.

### CORS

Disabled by default. Populate `CORS_ALLOWED_ORIGINS` (comma-separated) only for environments where a browser will hit `/api/*` directly. In normal BFF-fronted operation the allow-list is empty and browser cross-origin requests are rejected.

### Rate limits

| Limiter | Key | Default | Override |
| ------- | --- | ------- | -------- |
| `throttle:api_user` | per `users.uuid` (NOT per IP — every BFF request comes from one IP) | 60 / minute | `RATE_LIMIT_API_USER_PER_MINUTE` |
| `throttle:public` | per IP | 120 / minute | `RATE_LIMIT_PUBLIC_PER_MINUTE` |

When tripped: HTTP 429 in the standard envelope with a `Retry-After` header. The middleware priority is pinned so `auth.user` resolves first; otherwise the limiter would fall back to per-IP and lump every user behind the BFF into one bucket.

### Errors

Every uncaught exception flows through `App\Support\Http\ExceptionRenderer` and emerges as the standard envelope:

```json
{ "ok": false, "code": "snake_case", "message": "human", "status": 4xx, "details": null }
```

| HTTP | `code` | When |
| ---- | ------ | ---- |
| 401 | `missing_bearer`, `token_expired`, `iss_mismatch`, ... | see Phase 4 table |
| 403 | `forbidden` | future authorization checks |
| 404 | `route_not_found` | unknown URL |
| 404 | `resource_not_found` | implicit `ModelNotFoundException` |
| 405 | `method_not_allowed` | wrong verb on existing route |
| 422 | `validation_failed` | FormRequest / ValidationException |
| 429 | `too_many_requests` | rate limit exhausted |
| 500 | `server_error` | catch-all (no stack trace ever) |
| 503 | `auth_not_configured` | operator forgot `JWT_*` |
| 503 | `not_ready` | `/api/v1/test/ready` probe failed |

`APP_DEBUG=true` adds the exception class + message to `message`. `APP_DEBUG=false` (production) keeps `Something went wrong.` Stack traces never appear in the response body in any mode.

### Logging

| Channel | File | Carries |
| ------- | ---- | ------- |
| `single` (default app) | `storage/logs/laravel.log` | Application logs. Every line carries `request_id` from `Log::withContext`. |
| `audit` (separate) | `storage/logs/audit.log` | Structured one-line JSON per security event. Use a different retention / shipper policy from app logs. |

Audit events emitted by `App\Support\Audit\AuditLog`:

| `event` | Trigger | Key fields |
| ------- | ------- | ---------- |
| `auth.failed` | `auth.jwt` rejected the request | `code`, `request_id`, `ip`, `ua` |
| `me.updated` | PATCH /me changed at least one field | `uuid`, `changes: {field: {from, to}}` |
| `server.error` | reserved for catch-all 500 (wire it from `ExceptionRenderer` if needed) | `exception`, `message`, ... |

### Production env

`.env.production.example` carries the **Route A** values (token aimed at the SPA's own client ID, not Microsoft Graph) plus `CORS_ALLOWED_ORIGINS` / `RATE_LIMIT_*`. Copy it into a real `.env` once the front-end stops requesting Graph scopes; the local `.env` may keep its current "Graph token for testing" values until then.
