<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Console;

use FancyMlm\Attribution\Exceptions\InvalidInput;
use FancyMlm\Attribution\ReferralProgram;
use Illuminate\Console\Command;

/**
 * Deletes referral scans older than the retention the host set. Schedule it:
 *
 *     Schedule::command('mlm:prune-scans')->daily();
 *
 * Attributions are kept: a signup outlives the scan that caused it.
 */
class PruneScansCommand extends Command
{
    protected $signature = 'mlm:prune-scans {--days= : Override mlm.attribution.retention_days}';

    protected $description = 'Delete referral scans older than the configured retention';

    public function handle(ReferralProgram $program): int
    {
        $days = $this->option('days') ?? config('mlm.attribution.retention_days');
        if ($days === null || $days === '') {
            $this->info('mlm.attribution.retention_days is null: scans are kept. Nothing pruned.');

            return self::SUCCESS;
        }
        if (! is_numeric($days) || (int) $days < 1) {
            $this->error("Retention must be a whole number of days, at least 1; got '{$days}'.");

            return self::FAILURE;
        }

        try {
            $deleted = $program->pruneScans((int) $days * 86400);
        } catch (InvalidInput $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pruned {$deleted} referral scan(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
