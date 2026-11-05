<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use FancyMlm\Attribution\Exceptions\InvalidInput;

/**
 * Turns a session identifier into the only form the package stores: an
 * HMAC-SHA256 under a secret key, as lowercase hex.
 *
 * Keyed, so a leaked scans table cannot be reversed by hashing candidate ids,
 * and two apps hashing the same id produce unrelated values. There is no
 * unkeyed mode: an empty key is refused.
 */
final class SessionHasher
{
    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
        if ($key === '') {
            throw new InvalidInput('key', 'The session hash key cannot be empty: an unkeyed hash is reversible by dictionary.');
        }
    }

    public function hash(string $sessionId): string
    {
        if ($sessionId === '') {
            throw new InvalidInput('sessionId', 'A session id cannot be empty.');
        }

        return hash_hmac('sha256', $sessionId, $this->key);
    }

    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }
}
