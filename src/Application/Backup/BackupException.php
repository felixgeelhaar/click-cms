<?php

declare(strict_types=1);

namespace Click\Cms\Application\Backup;

use RuntimeException;

/**
 * A backup could not be made, or an archive could not be trusted.
 *
 * An exception rather than a returned error because every place this is thrown
 * is a place where continuing would produce the failure the feature exists to
 * prevent: an archive missing documents, or a restore that has already written
 * half a site before noticing the archive was truncated. The callers — one CLI
 * entry point, one HTTP handler — catch it and turn it into an exit code or a
 * status, which is the only place a human is looking anyway.
 */
final class BackupException extends RuntimeException
{
}
