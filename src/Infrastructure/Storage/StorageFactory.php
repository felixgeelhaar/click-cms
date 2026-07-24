<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Application\Config\ConfigurationException;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\Storage\StorageInterface;

/**
 * Builds the configured storage backend.
 *
 * Separate from `Application` so that choosing a backend can be tested without
 * booting the whole CMS, and so the failure messages below have a single home.
 *
 * Nothing here ever falls back. If the configuration asks for SQLite and SQLite
 * cannot be provided, this refuses to build anything rather than quietly handing
 * back flat files — a site that silently ran on a different backend than it was
 * configured for would appear to have lost every document it had written, and
 * the administrator would have no clue why. `docs/core.md` calls that out as the
 * recurring bug in this codebase; refusing loudly at boot is the remedy.
 */
final class StorageFactory
{
    /** Every backend core itself provides. Others are what plugins are for. */
    private const BACKENDS = ['json', 'sqlite', 'mysql', 'postgres'];

    public static function create(CoreConfig $config, string $basePath): StorageInterface
    {
        $requested = $config->storageBackend();

        // Both backends are told the site's default language, because both have
        // to decide what a document carrying no language is written in — the
        // flat-file layout that predates languages, and rows written before the
        // locale column existed.
        return match (strtolower($requested)) {
            'json' => new JsonStorage($basePath . '/content', $config->defaultLocale()),
            'sqlite' => self::sqlite($config, $basePath),
            'mysql', 'mariadb' => self::mysql($config),
            'postgres', 'postgresql', 'pgsql' => self::postgres($config),
            default => throw new ConfigurationException(self::unknownBackendMessage($requested)),
        };
    }

    private static function postgres(CoreConfig $config): PostgresStorage
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new ConfigurationException(
                'Storage backend "postgres" is configured at core.storage.backend '
                . 'in config/core.json, but this PHP build has no pdo_pgsql '
                . 'extension, so the database cannot be reached. Either enable '
                . 'pdo_pgsql in php.ini and restart PHP, or set core.storage.backend '
                . 'to "json" to use flat files, which need no database and no '
                . 'extension.'
            );
        }

        return new PostgresStorage(
            $config->storagePostgresHost(),
            $config->storagePostgresPort(),
            $config->storagePostgresDatabase(),
            $config->storagePostgresUser(),
            $config->storagePostgresPassword(),
            $config->defaultLocale(),
        );
    }

    private static function mysql(CoreConfig $config): MysqlStorage
    {
        // Checked before construction rather than left to PDO, whose "could not
        // find driver" surfaces deep in a request and gives no help deciding what
        // to do about it.
        if (!extension_loaded('pdo_mysql')) {
            throw new ConfigurationException(
                'Storage backend "mysql" is configured at core.storage.backend in '
                . 'config/core.json, but this PHP build has no pdo_mysql extension, '
                . 'so the database cannot be reached. Either enable pdo_mysql in '
                . 'php.ini and restart PHP, or set core.storage.backend to "json" to '
                . 'use flat files, which need no database and no extension.'
            );
        }

        return new MysqlStorage(
            $config->storageMysqlHost(),
            $config->storageMysqlPort(),
            $config->storageMysqlDatabase(),
            $config->storageMysqlUser(),
            $config->storageMysqlPassword(),
            $config->defaultLocale(),
        );
    }

    private static function sqlite(CoreConfig $config, string $basePath): SqliteStorage
    {
        // Checked before construction rather than left to PDO, whose own failure
        // is a "could not find driver" exception from somewhere deep in a
        // request — true, but no help at all in deciding what to do about it.
        if (!extension_loaded('pdo_sqlite')) {
            throw new ConfigurationException(
                'Storage backend "sqlite" is configured at core.storage.backend in '
                . 'config/core.json, but this PHP build has no pdo_sqlite extension, '
                . 'so the database cannot be opened. Either enable pdo_sqlite in '
                . 'php.ini and restart PHP, or set core.storage.backend to "json" to '
                . 'use flat files, which need no database and no extension.'
            );
        }

        $configured = $config->storageSqlitePath();

        if ($configured === '') {
            throw new ConfigurationException(
                'Storage backend "sqlite" is configured at core.storage.backend in '
                . 'config/core.json, but core.storage.sqlite.path is empty, so there '
                . 'is nowhere to put the database. Set it to a writable path such as '
                . '"data/content.sqlite", or remove it to accept that default.'
            );
        }

        return new SqliteStorage(self::resolvePath($configured, $basePath), $config->defaultLocale());
    }

    /**
     * A relative path is resolved against the installation root, not the working
     * directory, which differs between the web server and the CLI and would
     * otherwise give one install two databases.
     */
    private static function resolvePath(string $path, string $basePath): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : $basePath . '/' . ltrim($path, '/');
    }

    private static function unknownBackendMessage(string $requested): string
    {
        $quoted = $requested === '' ? '(empty)' : '"' . $requested . '"';

        return sprintf(
            'Unknown storage backend %s configured at core.storage.backend in '
            . 'config/core.json. Core provides: %s. Set it to one of those, or '
            . 'remove the setting entirely to use the default ("json"), which '
            . 'stores content as flat files and requires no database.',
            $quoted,
            '"' . implode('", "', self::BACKENDS) . '"'
        );
    }
}
