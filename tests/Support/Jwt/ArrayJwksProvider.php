<?php

declare(strict_types=1);

namespace Tests\Support\Jwt;

use App\Support\Jwt\JwksProviderInterface;
use Firebase\JWT\Key;

/**
 * Test double for JwksProviderInterface that returns a fixed in-memory key set.
 *
 * Tests can also call `setKeys()` to simulate key rotation between two
 * `getKeys()` calls — i.e. first call returns the "old" set, then after a
 * `flush()` the test bumps the keys to the "new" set. Used to assert that
 * the verifier transparently rotates.
 */
final class ArrayJwksProvider implements JwksProviderInterface
{
    private int $flushCount = 0;

    /**
     * @param  array<string, Key>  $keys
     */
    public function __construct(
        private array $keys,
    ) {}

    public function getKeys(): array
    {
        return $this->keys;
    }

    public function flush(): void
    {
        $this->flushCount++;
    }

    public function flushCount(): int
    {
        return $this->flushCount;
    }

    /**
     * @param  array<string, Key>  $keys
     */
    public function setKeys(array $keys): void
    {
        $this->keys = $keys;
    }
}
