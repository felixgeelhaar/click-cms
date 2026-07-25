<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

use RuntimeException;

/**
 * Something in the documentation itself is wrong, and the build stops.
 *
 * Not a bug in the generator and not a broken environment — a defect in a
 * Markdown file that would become a defect on the published site: an image with
 * no alt text, a screenshot that is not in the repository. Both are the kind of
 * fault that survives review because the page still *builds*; the reader is the
 * one who finds out, and by then it is published.
 *
 * The renderer throws this without knowing which document it is reading, and
 * `SiteBuilder` catches it and puts the file name in front of the message,
 * because "the image images/dashboard.png has no alt text" is only actionable
 * once you know which of nine pages it is in.
 */
final class DocumentDefect extends RuntimeException
{
}
