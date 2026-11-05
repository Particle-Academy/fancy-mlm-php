<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use FancyMlm\Attribution\Exceptions\InvalidInput;

/**
 * Referral code generation and validation.
 *
 * Codes come from the CSPRNG (`random_bytes`) over a 32-letter alphabet: digits
 * plus lowercase letters without i, l, o and u, so a code read aloud or off a
 * printed card is not ambiguous. 32 divides 256, so `byte & 31` maps every byte
 * to a letter with no modulo bias. The default 16 characters is 80 bits: at a
 * million live codes, guessing one takes on the order of 10^18 tries.
 *
 * Validation uses no regular expression: a length check plus `strspn` over the
 * alphabet, so there is no pattern to backtrack on hostile input.
 */
final class ReferralCodes
{
    public const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public const DEFAULT_LENGTH = 16;

    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 64;

    public static function generate(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidInput('length', sprintf(
                'Referral codes are %d to %d characters; %d is outside that.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
                $length,
            ));
        }

        $bytes = random_bytes($length);
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $code;
    }

    /**
     * Trim and lowercase a code as typed or scanned. Returns null for anything
     * that is not a well-formed code, so a lookup never reaches the store.
     */
    public static function normalize(string $code): ?string
    {
        $code = strtolower(trim($code));
        $length = strlen($code);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return null;
        }

        return strspn($code, self::ALPHABET) === $length ? $code : null;
    }
}
