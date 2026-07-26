<?php

declare(strict_types=1);

namespace Click\Cms\Application\Preflight;

/**
 * How much a preflight finding matters.
 *
 * The distinction that makes the report worth reading is {@see Failed} against
 * {@see Warning}. A failure means the CMS will not run here, so installing
 * anyway wastes an afternoon; a warning means it will run with something
 * missing — smaller images, no unattended updates — which is a decision rather
 * than a blocker. Collapsing the two would leave an operator unable to tell
 * "this cannot work" from "this works, slightly worse".
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warn';
    case Failed = 'fail';
    case Info = 'info';
}
