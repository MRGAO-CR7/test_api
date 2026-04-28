<?php

declare(strict_types=1);

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;
use App\Domain\User\Services\UserProvisioner;
use Illuminate\Database\QueryException;
use Tests\Support\User\FakeUserRepository;

/*
|--------------------------------------------------------------------------
| Unit tests for UserProvisioner
|--------------------------------------------------------------------------
|
| Drives the JIT logic against an in-memory fake repository so we can pin
| down the exact branch behaviour without spinning up a database.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function makeClaims(array $overrides = []): AuthClaims
{
    $defaults = [
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'email' => 'verified@example.com',
        'firstName' => 'Verified',
        'lastName' => 'User',
        'jti' => 'jti-abc',
        'expiresAt' => time() + 3600,
        'raw' => ['sub' => '11111111-2222-3333-4444-555555555555'],
    ];

    /** @var array{uuid: string, email: string, firstName: ?string, lastName: ?string, jti: ?string, expiresAt: int, raw: array<string, mixed>} $merged */
    $merged = array_merge($defaults, $overrides);

    return new AuthClaims(
        uuid: $merged['uuid'],
        email: $merged['email'],
        firstName: $merged['firstName'],
        lastName: $merged['lastName'],
        jti: $merged['jti'],
        expiresAt: $merged['expiresAt'],
        raw: $merged['raw'],
    );
}

it('inserts a brand-new user when the uuid is unknown', function (): void {
    $repo = new FakeUserRepository;
    $provisioner = new UserProvisioner($repo);

    $user = $provisioner->provision(makeClaims());

    expect($repo->created)->toHaveCount(1)
        ->and($repo->touched)->toHaveCount(0)
        ->and($user->uuid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($user->email)->toBe('verified@example.com')
        ->and($user->first_name)->toBe('Verified');
});

it('touches the existing row when the uuid is already known', function (): void {
    $repo = new FakeUserRepository;
    $existing = new User;
    $existing->forceFill([
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'email' => 'old@example.com',
        'first_name' => 'Old',
        'last_name' => 'Name',
    ]);
    $repo->seed($existing);

    $provisioner = new UserProvisioner($repo);

    $user = $provisioner->provision(makeClaims([
        'email' => 'new@example.com',
        'firstName' => 'Newer',
    ]));

    expect($repo->created)->toHaveCount(0)
        ->and($repo->touched)->toHaveCount(1)
        ->and($user->email)->toBe('new@example.com')
        ->and($user->first_name)->toBe('Newer');
});

it('treats a UNIQUE-constraint race as the returning-user path', function (): void {
    // Simulate two concurrent first-login requests:
    //  - both findByUuid -> null
    //  - one INSERT wins; the other gets a QueryException
    //  - the loser must re-read and treat it as a normal touch
    $racingRepo = new class extends FakeUserRepository
    {
        public bool $insertHasRaced = false;

        public function createFromClaims(AuthClaims $claims): User
        {
            // First call: pretend another request inserted the row right
            // before us, then make our own insert blow up.
            if (! $this->insertHasRaced) {
                $this->insertHasRaced = true;

                $other = new User;
                $other->forceFill([
                    'uuid' => $claims->uuid,
                    'email' => $claims->email,
                    'first_name' => $claims->firstName,
                    'last_name' => $claims->lastName,
                ]);
                $this->seed($other);

                throw new QueryException(
                    'mysql',
                    'INSERT INTO users (...)',
                    [],
                    new RuntimeException('Duplicate entry for key uuid'),
                );
            }

            return parent::createFromClaims($claims);
        }
    };

    $provisioner = new UserProvisioner($racingRepo);

    $user = $provisioner->provision(makeClaims());

    expect($racingRepo->touched)->toHaveCount(1)
        ->and($user->uuid)->toBe('11111111-2222-3333-4444-555555555555');
});

it('rethrows a non-race DB error so it surfaces as a real failure', function (): void {
    $brokenRepo = new class extends FakeUserRepository
    {
        public function createFromClaims(AuthClaims $claims): User
        {
            // The row never gets inserted, yet createFromClaims fails. The
            // re-read in the provisioner won't find anything, so we expect
            // the original exception to bubble.
            throw new QueryException(
                'mysql',
                'INSERT INTO users (...)',
                [],
                new RuntimeException('Server has gone away'),
            );
        }
    };

    $provisioner = new UserProvisioner($brokenRepo);

    expect(fn () => $provisioner->provision(makeClaims()))
        ->toThrow(QueryException::class);
});
