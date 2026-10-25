<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\StorageFactory;
use Click\Cms\Infrastructure\Storage\VersioningStorage;

/** Read-only export view including working copies, without request audit side effects. */
final class BackupSource
{
    public static function create(CoreConfig $config, string $siteRoot, ?string $installationRoot = null): PublishingStorage
    {
        $schema = $siteRoot . '/config/collections';
        if (!is_dir($schema)) {
            $schema = ($installationRoot ?? $siteRoot) . '/config/collections';
        }
        Publishable::register(array_map(static fn ($type): string => $type->id,
            (new JsonCollectionTypeRepository($schema))->all()));

        return new VersioningStorage(
            StorageFactory::create($config, $siteRoot),
            new JsonVersionStore($siteRoot . '/data/versions'),
        );
    }
}
