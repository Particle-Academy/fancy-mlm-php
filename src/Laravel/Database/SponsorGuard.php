<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Database;

use Illuminate\Database\Connection;

/**
 * Installs and removes the database trigger that makes a set sponsor_id
 * permanent. The SQL per driver is public so a host on another engine can read
 * what to port.
 *
 * Messages begin `fancy-mlm: sponsor_id`, so the QueryException a refused
 * update raises names the rule that refused it.
 */
final class SponsorGuard
{
    public const PERMANENT = 'fancy-mlm: sponsor_id is permanent';

    public const SELF = 'fancy-mlm: sponsor_id cannot be the member itself';

    /** Drivers the trigger exists for. Anything else is skipped. */
    public const DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    public const APPEND_ONLY = 'fancy-mlm: sponsor corrections are append-only';

    /**
     * Install the guard. Without `$corrections` this is the 0.4.0 guard: a set
     * sponsor never changes. With it, one change is admitted: the one the
     * member's LATEST row in the corrections table names, from exactly the
     * current sponsor to exactly the new one.
     */
    public static function install(Connection $connection, string $table, ?string $corrections = null): bool
    {
        $prefix = $connection->getTablePrefix();
        $statements = self::installSql($connection->getDriverName(), $prefix.$table, $corrections === null ? null : $prefix.$corrections);
        foreach ($statements as $sql) {
            $connection->unprepared($sql);
        }

        return $statements !== [];
    }

    /** Make the corrections table refuse UPDATE and DELETE. */
    public static function installAppendOnly(Connection $connection, string $corrections): bool
    {
        $statements = self::appendOnlySql($connection->getDriverName(), $connection->getTablePrefix().$corrections);
        foreach ($statements as $sql) {
            $connection->unprepared($sql);
        }

        return $statements !== [];
    }

    public static function uninstallAppendOnly(Connection $connection, string $corrections): void
    {
        foreach (self::appendOnlyUninstallSql($connection->getDriverName(), $connection->getTablePrefix().$corrections) as $sql) {
            $connection->unprepared($sql);
        }
    }

    public static function uninstall(Connection $connection, string $table): void
    {
        foreach (self::uninstallSql($connection->getDriverName(), $connection->getTablePrefix().$table) as $sql) {
            $connection->unprepared($sql);
        }
    }

    /** @return list<string> */
    public static function installSql(string $driver, string $table, ?string $corrections = null): array
    {
        if ($corrections !== null) {
            return self::auditedInstallSql($driver, $table, $corrections);
        }

        [$t, $name] = [self::ident($table), self::ident(self::triggerName($table))];
        $permanent = self::PERMANENT;
        $self = self::SELF;

        return match ($driver) {
            'sqlite' => [<<<SQL
                CREATE TRIGGER "{$name}" BEFORE UPDATE OF sponsor_id ON "{$t}"
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, '{$permanent}')
                    WHERE OLD.sponsor_id IS NOT NULL
                      AND (NEW.sponsor_id IS NULL OR NEW.sponsor_id <> OLD.sponsor_id);
                    SELECT RAISE(ABORT, '{$self}')
                    WHERE OLD.sponsor_id IS NULL AND NEW.sponsor_id = NEW.id;
                END
                SQL],
            'mysql', 'mariadb' => [<<<SQL
                CREATE TRIGGER `{$name}` BEFORE UPDATE ON `{$t}`
                FOR EACH ROW
                BEGIN
                    IF OLD.sponsor_id IS NOT NULL AND NOT (NEW.sponsor_id <=> OLD.sponsor_id) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$permanent}';
                    END IF;
                    IF OLD.sponsor_id IS NULL AND NEW.sponsor_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$self}';
                    END IF;
                END
                SQL],
            'pgsql' => [
                <<<SQL
                CREATE OR REPLACE FUNCTION "{$name}"() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
                BEGIN
                    IF OLD.sponsor_id IS NOT NULL AND NEW.sponsor_id IS DISTINCT FROM OLD.sponsor_id THEN
                        RAISE EXCEPTION '{$permanent}' USING ERRCODE = 'check_violation';
                    END IF;
                    IF OLD.sponsor_id IS NULL AND NEW.sponsor_id = NEW.id THEN
                        RAISE EXCEPTION '{$self}' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END
                \$fn\$
                SQL,
                <<<SQL
                CREATE TRIGGER "{$name}" BEFORE UPDATE OF sponsor_id ON "{$t}"
                FOR EACH ROW EXECUTE FUNCTION "{$name}"()
                SQL,
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function uninstallSql(string $driver, string $table): array
    {
        [$t, $name] = [self::ident($table), self::ident(self::triggerName($table))];

        return match ($driver) {
            'sqlite' => ["DROP TRIGGER IF EXISTS \"{$name}\""],
            'mysql', 'mariadb' => ["DROP TRIGGER IF EXISTS `{$name}`"],
            'pgsql' => [
                "DROP TRIGGER IF EXISTS \"{$name}\" ON \"{$t}\"",
                "DROP FUNCTION IF EXISTS \"{$name}\"()",
            ],
            default => [],
        };
    }

    /**
     * The guard with one audited exception. A set sponsor_id may change only
     * when the member's latest corrections row (highest id) names exactly this
     * change: from OLD.sponsor_id to NEW.sponsor_id.
     *
     * Why "latest row" and not "any matching row": an applied row stays in the
     * table forever, so "any" would let an old row be replayed once the member
     * is corrected back. Every later change of the same member needs a newer
     * row, which then becomes the latest and retires the old one. Why not
     * "inserted in this transaction": SQLite has no transaction identity a
     * trigger can read, and the latest-row rule needs none.
     *
     * Self-sponsorship is refused on every change, audited or not.
     *
     * @return list<string>
     */
    private static function auditedInstallSql(string $driver, string $table, string $corrections): array
    {
        [$t, $c, $name] = [self::ident($table), self::ident($corrections), self::ident(self::triggerName($table))];
        $permanent = self::PERMANENT;
        $self = self::SELF;

        return match ($driver) {
            'sqlite' => [<<<SQL
                CREATE TRIGGER "{$name}" BEFORE UPDATE OF sponsor_id ON "{$t}"
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, '{$permanent}')
                    WHERE OLD.sponsor_id IS NOT NULL
                      AND (NEW.sponsor_id IS NULL OR NEW.sponsor_id <> OLD.sponsor_id)
                      AND NOT EXISTS (
                          SELECT 1 FROM "{$c}" c
                          WHERE c.id = (SELECT MAX(c2.id) FROM "{$c}" c2 WHERE c2.member_id = OLD.id)
                            AND c.from_sponsor_id = OLD.sponsor_id
                            AND c.to_sponsor_id = NEW.sponsor_id
                      );
                    SELECT RAISE(ABORT, '{$self}')
                    WHERE NEW.sponsor_id IS NOT OLD.sponsor_id AND NEW.sponsor_id = NEW.id;
                END
                SQL],
            'mysql', 'mariadb' => [<<<SQL
                CREATE TRIGGER `{$name}` BEFORE UPDATE ON `{$t}`
                FOR EACH ROW
                BEGIN
                    IF OLD.sponsor_id IS NOT NULL AND NOT (NEW.sponsor_id <=> OLD.sponsor_id)
                       AND NOT EXISTS (
                           SELECT 1 FROM `{$c}` c
                           WHERE c.id = (SELECT MAX(c2.id) FROM `{$c}` c2 WHERE c2.member_id = OLD.id)
                             AND c.from_sponsor_id = OLD.sponsor_id
                             AND c.to_sponsor_id = NEW.sponsor_id
                       ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$permanent}';
                    END IF;
                    IF NOT (NEW.sponsor_id <=> OLD.sponsor_id) AND NEW.sponsor_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$self}';
                    END IF;
                END
                SQL],
            'pgsql' => [
                <<<SQL
                CREATE OR REPLACE FUNCTION "{$name}"() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
                BEGIN
                    IF OLD.sponsor_id IS NOT NULL AND NEW.sponsor_id IS DISTINCT FROM OLD.sponsor_id
                       AND NOT EXISTS (
                           SELECT 1 FROM "{$c}" c
                           WHERE c.id = (SELECT MAX(c2.id) FROM "{$c}" c2 WHERE c2.member_id = OLD.id)
                             AND c.from_sponsor_id = OLD.sponsor_id
                             AND c.to_sponsor_id = NEW.sponsor_id
                       ) THEN
                        RAISE EXCEPTION '{$permanent}' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.sponsor_id IS DISTINCT FROM OLD.sponsor_id AND NEW.sponsor_id = NEW.id THEN
                        RAISE EXCEPTION '{$self}' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END
                \$fn\$
                SQL,
                <<<SQL
                CREATE TRIGGER "{$name}" BEFORE UPDATE OF sponsor_id ON "{$t}"
                FOR EACH ROW EXECUTE FUNCTION "{$name}"()
                SQL,
            ],
            default => [],
        };
    }

    /**
     * Triggers that refuse UPDATE and DELETE on the corrections table (and
     * TRUNCATE on PostgreSQL). MySQL's TRUNCATE and any DDL (DROP TABLE, DROP
     * TRIGGER) bypass row triggers on every engine: a user with DDL rights is
     * outside what a trigger can stop.
     *
     * @return list<string>
     */
    public static function appendOnlySql(string $driver, string $corrections): array
    {
        $c = self::ident($corrections);
        [$upd, $del] = [self::ident($corrections.'_append_only_update'), self::ident($corrections.'_append_only_delete')];
        $msg = self::APPEND_ONLY;

        return match ($driver) {
            'sqlite' => [
                "CREATE TRIGGER \"{$upd}\" BEFORE UPDATE ON \"{$c}\" FOR EACH ROW BEGIN SELECT RAISE(ABORT, '{$msg}'); END",
                "CREATE TRIGGER \"{$del}\" BEFORE DELETE ON \"{$c}\" FOR EACH ROW BEGIN SELECT RAISE(ABORT, '{$msg}'); END",
            ],
            'mysql', 'mariadb' => [
                "CREATE TRIGGER `{$upd}` BEFORE UPDATE ON `{$c}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'",
                "CREATE TRIGGER `{$del}` BEFORE DELETE ON `{$c}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'",
            ],
            'pgsql' => [
                <<<SQL
                CREATE OR REPLACE FUNCTION "{$upd}"() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
                BEGIN
                    RAISE EXCEPTION '{$msg}' USING ERRCODE = 'check_violation';
                END
                \$fn\$
                SQL,
                "CREATE TRIGGER \"{$upd}\" BEFORE UPDATE OR DELETE ON \"{$c}\" FOR EACH ROW EXECUTE FUNCTION \"{$upd}\"()",
                "CREATE TRIGGER \"{$del}\" BEFORE TRUNCATE ON \"{$c}\" FOR EACH STATEMENT EXECUTE FUNCTION \"{$upd}\"()",
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function appendOnlyUninstallSql(string $driver, string $corrections): array
    {
        $c = self::ident($corrections);
        [$upd, $del] = [self::ident($corrections.'_append_only_update'), self::ident($corrections.'_append_only_delete')];

        return match ($driver) {
            'sqlite' => ["DROP TRIGGER IF EXISTS \"{$upd}\"", "DROP TRIGGER IF EXISTS \"{$del}\""],
            'mysql', 'mariadb' => ["DROP TRIGGER IF EXISTS `{$upd}`", "DROP TRIGGER IF EXISTS `{$del}`"],
            'pgsql' => [
                "DROP TRIGGER IF EXISTS \"{$upd}\" ON \"{$c}\"",
                "DROP TRIGGER IF EXISTS \"{$del}\" ON \"{$c}\"",
                "DROP FUNCTION IF EXISTS \"{$upd}\"()",
            ],
            default => [],
        };
    }

    public static function triggerName(string $table): string
    {
        return $table.'_sponsor_permanent';
    }

    /**
     * Table names come from config, not users, but they are spliced into DDL,
     * so anything outside [A-Za-z0-9_] is refused rather than escaped.
     */
    private static function ident(string $name): string
    {
        if ($name === '' || strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_') !== strlen($name)) {
            throw new \InvalidArgumentException("fancy-mlm: refusing to build a trigger for table name '{$name}'.");
        }

        return $name;
    }
}
