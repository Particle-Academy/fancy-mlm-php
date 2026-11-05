<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

use RuntimeException;

/** Base for every refused sponsor assignment. Catch this to handle all of them. */
abstract class AttributionException extends RuntimeException
{
}
