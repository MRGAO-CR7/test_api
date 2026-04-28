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
- [ ] Phase 3 — Docker compose, nginx, joined to `bbm` network as `test_api_webserver`
- [ ] Phase 4 — JWT verification middleware (Entra JWKS, no DB)
- [ ] Phase 5 — JIT user provisioning + `GET/PATCH /api/v1/me`
- [ ] Phase 6 — Hardening (error mapping, request id, CORS, throttle, ready probe)

## Layout (target — built up across phases)

```
app/
├─ Domain/
│  └─ User/
│     ├─ Models/User.php                    # P2
│     ├─ Repositories/UserRepository.php    # P5
│     ├─ Services/UserProvisioningService.php  # P5
│     └─ DTOs/AuthClaims.php                # P4
├─ Http/
│  ├─ Controllers/Api/V1/HealthController.php  # P1
│  ├─ Controllers/Api/V1/MeController.php      # P5
│  ├─ Middleware/ForceJsonResponse.php         # P1
│  ├─ Middleware/VerifyEntraJwt.php            # P4
│  ├─ Middleware/ResolveCurrentUser.php        # P5
│  ├─ Middleware/AssignRequestId.php           # P6
│  ├─ Requests/Api/V1/UpdateMeRequest.php      # P5
│  └─ Resources/UserResource.php               # P2
├─ Support/
│  ├─ Http/ApiErrorEnvelope.php             # P1
│  └─ Jwt/{JwksClient,EntraJwtVerifier}.php # P4
└─ Providers/
   ├─ DomainServiceProvider.php             # P5
   └─ JwtServiceProvider.php                # P4
routes/
├─ api.php          # /api/v1/*
└─ web.php          # intentionally empty (no HTML)
```

## Local setup

Prereqs: PHP 8.3+, Composer 2, MySQL 9 listening on 127.0.0.1:3306 with the
`test` database created and `root`/`rootpass`.

```bash
cp .env.example .env
composer install
php artisan key:generate

# Phase 1 only: this works without MySQL because /api/v1/health does no I/O.
php artisan serve --port=8009

# Other terminal:
curl -s http://localhost:8009/api/v1/health | jq
```

## Scripts

| Command | Purpose |
| --- | --- |
| `composer dev` | `php artisan serve --port=8009` |
| `composer test` | Pest suite (uses :memory: sqlite per `phpunit.xml`) |
| `composer lint` | `pint --test` (no writes) |
| `composer lint:fix` | `pint` (rewrites in place) |
| `composer analyse` | `phpstan analyse` (level 8) |
| `composer check` | lint + analyse + test (CI gate) |

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
