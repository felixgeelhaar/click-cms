<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

/** Process configuration controlled by the deployment, not content editors. */
final class DeploymentMode
{
    public static function managed(): bool
    {
        return getenv('CLICK_DEPLOYMENT_MANAGED') === '1';
    }
}
