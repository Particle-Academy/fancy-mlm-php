<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

/**
 * What happened to a scan. `$status` is one of: recorded, duplicate (same
 * session and code inside the dedupe window; `$scan` is the earlier one),
 * code_inactive (revoked or expired), unknown_code (malformed or not found).
 */
final class ScanResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?Scan $scan = null,
    ) {}

    public function recorded(): bool
    {
        return $this->status === 'recorded';
    }
}
