<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| users — local projection of the auth_service tenant
|--------------------------------------------------------------------------
|
| Notes for future migrations:
|
|   - `uuid` is the stable identifier supplied by auth_service. It is unique,
|     not nullable, and MUST NEVER be updated. All foreign keys from other
|     tables should reference `users.id` (the local bigint), but business
|     reconciliation with auth_service is keyed on `users.uuid`.
|   - `email` is unique here for hygiene, but it is treated as MUTABLE: when a
|     JWT arrives with a different email for the same uuid we overwrite the
|     local row.
|   - There is intentionally NO `password`, `remember_token`, or
|     `email_verified_at` column. test_api never owns credentials.
|   - `last_token_jti` lets us short-circuit replay/loop detection later
|     (Phase 6 may use it for /logout-everywhere style flows). Nullable.
|   - `claims_snapshot` (JSON) is an optional debugging breadcrumb of the
|     last JWT we accepted for this user. Useful in incident response; not a
|     source of truth.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // External identity (auth_service / Entra). Unique + immutable.
            $table->uuid('uuid')->unique();

            // Mutable profile fields, sourced from JWT claims on each request.
            $table->string('email', 254)->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();

            // Audit / observability.
            $table->string('last_token_jti', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('claims_snapshot')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
