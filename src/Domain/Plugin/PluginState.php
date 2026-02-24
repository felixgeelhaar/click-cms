<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Plugin;

enum PluginState: string
{
    case DISCOVERED = 'discovered';
    case INSTALLED = 'installed';
    case ACTIVATED = 'activated';
    case DEACTIVATED = 'deactivated';
}
