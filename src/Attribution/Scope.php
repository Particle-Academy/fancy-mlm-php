<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use FancyMlm\Attribution\Exceptions\InvalidInput;

/**
 * A host-defined scope for a referral code. The package attaches no meaning to
 * it: pass an opaque string, or a tuple of strings that is encoded into one.
 *
 * A tuple's parts are RFC 3986-encoded (`rawurlencode`) and joined with `:`, so
 * a part containing a colon cannot collide with two parts. Keys are at most 191
 * bytes, the width of the indexed column.
 *
 * Mixing styles is the host's call: the string `"a:b"` and the tuple
 * `["a", "b"]` produce the same key.
 */
final class Scope
{
    public const MAX_BYTES = 191;

    /** @param string|list<string> $scope */
    public static function key(string|array $scope): string
    {
        if (is_array($scope)) {
            if ($scope === []) {
                throw new InvalidInput('scope', 'A scope tuple needs at least one part.');
            }
            $parts = [];
            foreach ($scope as $part) {
                if (! is_string($part) || $part === '') {
                    throw new InvalidInput('scope', 'Every part of a scope tuple must be a non-empty string.');
                }
                $parts[] = rawurlencode($part);
            }
            $scope = implode(':', $parts);
        }

        if ($scope === '') {
            throw new InvalidInput('scope', 'A scope cannot be empty.');
        }
        if (strlen($scope) > self::MAX_BYTES) {
            throw new InvalidInput('scope', 'A scope key is at most '.self::MAX_BYTES.' bytes.');
        }

        return $scope;
    }
}
