<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Publishing;

/**
 * What a due schedule asks the sweeper to do.
 *
 * Two cases, and deliberately no third for "nothing": a schedule with nothing
 * due answers `null` instead, so a caller cannot forget to handle the idle case
 * by matching on an enum that quietly has a value for it.
 */
enum ScheduledAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
}
