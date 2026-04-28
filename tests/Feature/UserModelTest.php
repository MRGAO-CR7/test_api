<?php

declare(strict_types=1);

use App\Domain\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and retrieves a user keyed on uuid', function (): void {
    $user = User::factory()->create([
        'email' => 'alice@example.com',
        'uuid' => '00000000-0000-4000-8000-000000000001',
    ]);

    expect($user->id)->toBeInt()
        ->and($user->uuid)->toBe('00000000-0000-4000-8000-000000000001')
        ->and(User::query()->where('uuid', $user->uuid)->value('email'))
        ->toBe('alice@example.com');
});

it('rejects duplicate uuids at the database layer', function (): void {
    User::factory()->create(['uuid' => '00000000-0000-4000-8000-00000000dead']);

    expect(fn () => User::factory()->create([
        'uuid' => '00000000-0000-4000-8000-00000000dead',
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate emails at the database layer', function (): void {
    User::factory()->create(['email' => 'dup@example.com']);

    expect(fn () => User::factory()->create(['email' => 'dup@example.com']))
        ->toThrow(QueryException::class);
});

it('soft-deletes rather than hard-deleting', function (): void {
    $user = User::factory()->create();

    $user->delete();

    $trashed = User::withTrashed()->where('id', $user->id)->first();

    expect(User::query()->find($user->id))->toBeNull()
        ->and($trashed)->toBeInstanceOf(User::class)
        ->and($trashed?->deleted_at)->not->toBeNull();
});

it('round-trips claims_snapshot as a typed array (json cast)', function (): void {
    $user = User::factory()->create([
        'claims_snapshot' => ['iss' => 'https://example.com', 'iat' => 12345],
    ]);

    expect($user->fresh()?->claims_snapshot)
        ->toBe(['iss' => 'https://example.com', 'iat' => 12345]);
});
