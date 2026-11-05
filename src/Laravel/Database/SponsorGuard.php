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

    public static function install(Connection $connection, string $table): bool
    {
        $statements = self::installSql($connection->getDriverName(), $connection->getTablePrefix().$table);
        foreach ($statements as $sql) {
            $connection->unprepared($sql);
        }

        return $statements !== [];
    }

    public static function uninstall(Connection $connection, string $table): void
    {
        foreach (self::uninstallSql($connection->getDriverName(), $connection->getTablePrefix().$table) as $sql) {
            $connection->unprepared($sql);
        }
    }

    /** @return list<string> */
    public static function installSql(string $driver, string $table): array
    {
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
