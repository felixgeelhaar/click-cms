<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Webhook;

use Click\Cms\Domain\Webhook\HttpTransport;
use RuntimeException;

/**
 * Picks whichever way of making an HTTP request this installation actually has.
 *
 * Neither option can be relied on. `ext-curl` is not bundled with PHP and plenty
 * of shared hosts omit it; `allow_url_fopen` is on by default but is one of the
 * first things a hardened `php.ini` turns off. A CMS that requires either would
 * be a CMS that fails to install somewhere, and adding a Composer HTTP client
 * would break the no-runtime-dependencies rule that is the point of the project.
 *
 * So: try curl, fall back to streams, and **fail loudly when neither is there**.
 * The falling back is between two implementations of the same contract, which is
 * different from the silent degradation `core.md` forbids — the site gets what
 * it asked for either way. Having neither is not a lesser service, it is the
 * feature not working, and a webhook plugin that quietly delivered nothing would
 * be the worst outcome available: the queue would fill, the admin would show
 * pending deliveries, and nothing would ever say why none of them moved.
 */
final class TransportFactory
{
    private function __construct() {}

    public static function create(): HttpTransport
    {
        if (function_exists('curl_init')) {
            return new CurlTransport();
        }

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return new StreamTransport();
        }

        throw new RuntimeException(
            'Webhooks need a way to make outbound HTTP requests, and this installation has none: '
            . 'the curl extension is not loaded and allow_url_fopen is off. '
            . 'Enable either one, or deactivate the webhooks plugin.'
        );
    }

    /**
     * Whether anything here can send at all.
     *
     * Asked by the plugin at boot so the admin can say the feature is
     * unavailable, rather than accepting endpoints whose deliveries will sit in
     * the queue for ever.
     */
    public static function isAvailable(): bool
    {
        return function_exists('curl_init')
            || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
    }
}
