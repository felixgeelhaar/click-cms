<?php

declare(strict_types=1);

namespace Click\Cms\Application\Preflight;

use Click\Cms\Domain\Media\UploadPolicy;

/**
 * Whether a host can run click-cms, decided before anything is installed.
 *
 * This project's claim is that it runs on ordinary shared hosting, and the way
 * that claim fails is always the same: an install that looks fine until the
 * first upload is refused, or the first image comes back unresized, or the
 * update feed cannot be reached. Every one of those is knowable in advance, and
 * knowable only on the host — a developer's machine says nothing about which
 * PHP a webspace runs.
 *
 * The report takes facts and returns findings. It reads no configuration, opens
 * no file and calls no `ini_get` of its own, so the entry script that gathers
 * the facts stays dumb and every verdict here can be pinned by a test without a
 * server that has the fault.
 */
final class HostReport
{
    /** The oldest PHP this project supports, as `composer.json` and the update feed both say. */
    public const MIN_PHP_ID = 80_100;
    public const MIN_PHP = '8.1';

    /**
     * @param array<string, mixed> $facts Whatever the caller could establish.
     *        A fact that is absent is reported as unknown rather than as a
     *        fault — the report is also run where not everything is knowable.
     * @return list<HostCheck>
     */
    public static function for(array $facts): array
    {
        $extensions = array_map('strval', (array) ($facts['extensions'] ?? []));
        $has = static fn (string $name): bool => in_array($name, $extensions, true);

        $checks = [];

        /* ------------------------------------------------------------ PHP -- */

        $versionId = (int) ($facts['phpVersionId'] ?? 0);
        $version = (string) ($facts['phpVersion'] ?? 'unknown');
        $checks[] = new HostCheck(
            'PHP version',
            $versionId >= self::MIN_PHP_ID ? CheckStatus::Ok : CheckStatus::Failed,
            $versionId >= self::MIN_PHP_ID
                ? $version
                : $version . ' — too old, click-cms needs ' . self::MIN_PHP . ' or newer',
        );

        $checks[] = new HostCheck('SAPI', CheckStatus::Info, (string) ($facts['sapi'] ?? 'unknown'));

        // Used unconditionally wherever text is measured or cut, so its absence
        // is not a degraded install but a broken one.
        $checks[] = new HostCheck(
            'mbstring',
            $has('mbstring') ? CheckStatus::Ok : CheckStatus::Failed,
            $has('mbstring') ? 'present' : 'missing — required for text handling',
        );

        // Optional by design: uploads still work, they are simply stored at the
        // size they arrived, with no responsive variants generated.
        $checks[] = new HostCheck(
            'gd',
            $has('gd') ? CheckStatus::Ok : CheckStatus::Warning,
            $has('gd')
                ? 'present — responsive image variants will be generated'
                : 'missing — uploads are stored unresized, with no variants for a srcset',
        );

        // Guarded in the media service, so its absence costs sharper content-type
        // detection on upload and nothing else.
        $checks[] = new HostCheck(
            'fileinfo',
            $has('fileinfo') ? CheckStatus::Ok : CheckStatus::Warning,
            $has('fileinfo') ? 'present' : 'missing — uploads fall back to weaker type detection',
        );

        /* -------------------------------------------------------- uploads -- */

        // The ceiling the CMS itself will accept. Anything lower on the host is
        // the real limit, and it is discovered halfway through moving a site's
        // media — which is the worst moment to discover it.
        $wanted = UploadPolicy::MAX_VIDEO_BYTES;
        $uploadMax = (int) ($facts['uploadMaxBytes'] ?? 0);
        $postMax = (int) ($facts['postMaxBytes'] ?? 0);
        $effective = min($uploadMax, $postMax);

        $checks[] = new HostCheck(
            'upload size',
            $effective >= $wanted ? CheckStatus::Ok : CheckStatus::Failed,
            $effective >= $wanted
                ? self::mb($effective) . ' accepted'
                : self::mb($effective) . ' — smaller than the ' . self::mb($wanted)
                    . ' the CMS accepts (upload_max_filesize ' . self::mb($uploadMax)
                    . ', post_max_size ' . self::mb($postMax) . ')',
        );

        $checks[] = new HostCheck('memory_limit', CheckStatus::Info, (string) ($facts['memoryLimit'] ?? 'unknown'));
        $checks[] = new HostCheck(
            'max_execution_time',
            CheckStatus::Info,
            (string) ($facts['maxExecutionTime'] ?? 'unknown') . 's',
        );

        /* -------------------------------------------------------- updates -- */

        $canFetch = $has('curl') || (bool) ($facts['allowUrlFopen'] ?? false);
        $checks[] = new HostCheck(
            'outbound HTTPS',
            $canFetch ? CheckStatus::Ok : CheckStatus::Warning,
            $canFetch
                ? ($has('curl') ? 'curl present' : 'allow_url_fopen on')
                : 'no curl and allow_url_fopen off — the update feed cannot be fetched',
        );

        $checks[] = new HostCheck(
            'openssl',
            $has('openssl') ? CheckStatus::Ok : CheckStatus::Warning,
            $has('openssl')
                ? 'present — update signatures can be verified'
                : 'missing — update signatures cannot be verified, so updates will not install',
        );

        /* --------------------------------------------------------- layout -- */

        $checks[] = new HostCheck('document root', CheckStatus::Info, (string) ($facts['documentRoot'] ?? 'unknown'));
        $checks[] = new HostCheck('public directory', CheckStatus::Info, (string) ($facts['publicDir'] ?? 'unknown'));

        $publicWritable = (bool) ($facts['publicDirWritable'] ?? false);
        $checks[] = new HostCheck(
            'public directory writable',
            $publicWritable ? CheckStatus::Ok : CheckStatus::Failed,
            $publicWritable ? 'yes' : 'no — the files cannot be installed here',
        );

        // The finding that decides the install layout. With somewhere writable
        // outside the served tree, content/, data/ and config/ live there and are
        // unreachable over HTTP whatever a rewrite rule does. Without it they sit
        // inside the tree and are denied by rules instead — which works, and
        // which the operator should know they are now relying on.
        $outside = (string) ($facts['outsideRoot'] ?? 'unknown');
        $outsideWritable = (bool) ($facts['outsideRootWritable'] ?? false);
        $checks[] = new HostCheck(
            'space outside the document root',
            $outsideWritable ? CheckStatus::Ok : CheckStatus::Warning,
            $outsideWritable
                ? $outside . ' is writable — put the app root there and set CLICK_CMS_ROOT'
                : 'none writable — content/, data/ and config/ must sit inside the served tree '
                    . 'and be denied by .htaccess, which then has to stay in place',
        );

        return $checks;
    }

    /** @param list<HostCheck> $checks */
    public static function failures(array $checks): int
    {
        return count(array_filter($checks, static fn (HostCheck $c): bool => $c->status === CheckStatus::Failed));
    }

    /** @param list<HostCheck> $checks */
    public static function warnings(array $checks): int
    {
        return count(array_filter($checks, static fn (HostCheck $c): bool => $c->status === CheckStatus::Warning));
    }

    private static function mb(int $bytes): string
    {
        return $bytes <= 0 ? 'unknown' : round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
