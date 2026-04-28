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

- [x] **Phase 1 — Scaffold + tooling skeleton** (`/api/v1/health`, force-JSON, error envelope, pint/phpstan/pest)
- [x] **Phase 2 — DB connection + `users` migration + Eloquent model** (MySQL `test`, `App\Domain\User\Models\User`, `UserResource`, factory, soft-delete + uuid/email unique)
- [x] **Phase 3 — Docker compose + nginx, joined to `bbm`** (`test_api_webserver:8000` ↔ host `:8009`, php-fpm 8.4 alpine, MySQL via bbm DNS `docker-mysql-1`, healthcheck green)
- [x] **Phase 4 — JWT verification middleware** (Entra JWKS, JwksProvider interface + cache + rotation retry, AuthClaims DTO with claim mapping, `auth.jwt` middleware alias, temp `/api/v1/_debug/whoami` for verification)
- [x] **Phase 5 — JIT user provisioning + `GET/PATCH /api/v1/me`** (UserRepository contract + Eloquent impl, UserProvisioner with race-safe upsert, `auth.user` middleware aliasing `ResolveCurrentUser`, MeController + UpdateMeRequest, `_debug/whoami` removed, 43 tests / 133 assertions green)
- [ ] Phase 6 — Hardening (error mapping, request id, CORS, throttle, ready probe)

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
│  ├─ Middleware/ForceJsonResponse.php                    # P1
│  ├─ Middleware/VerifyEntraJwt.php                       # P4 -> auth.jwt
│  ├─ Middleware/ResolveCurrentUser.php                   # P5 -> auth.user
│  ├─ Middleware/AssignRequestId.php                      # P6
│  ├─ Requests/Api/V1/UpdateMeRequest.php                 # P5
│  └─ Resources/UserResource.php                          # P2
├─ Support/
│  ├─ Http/ApiErrorEnvelope.php                           # P1
│  └─ Jwt/{JwksClient,EntraJwtVerifier}.php               # P4
└─ Providers/
   ├─ JwtServiceProvider.php                              # P4
   └─ UserServiceProvider.php                             # P5
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
curl -s http://localhost:8009/api/v1/health | jq
```

### Option B — `docker compose` (joins the shared `bbm` network)

```bash
# 0) one-time: ensure the shared bridge exists (auth_service compose creates it)
docker network inspect bbm >/dev/null 2>&1 || docker network create bbm

# 1) build + boot
docker compose up --build -d
docker compose logs -f test_api_php

# 2) verify all three reach paths
curl -s http://localhost:8009/api/v1/health                         # host
docker run --rm --network bbm curlimages/curl \
    -s http://test_api_webserver:8000/api/v1/health                 # bbm peer
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
| GET | `/api/v1/health` | Liveness, no auth |
| GET | `/api/v1/me` | Current user. JIT-creates the row on first call. Idempotent (always 200). |
| PATCH | `/api/v1/me` | Partial update of `first_name`, `last_name`, `email`. Empty body = 200 no-op. |

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
JWT_AUDIENCE=api://test_api

# Override per env if your IdP uses different claim names.
JWT_UUID_CLAIM=sub
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
