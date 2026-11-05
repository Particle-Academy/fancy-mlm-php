<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

use InvalidArgumentException;

/** A refused input. `$field` names what was wrong, for callers and the shared fixture. */
final class InvalidInput extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
