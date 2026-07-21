<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

use RuntimeException;

/**
 * The configuration asks for something that cannot be provided.
 *
 * Distinct from every other `RuntimeException` so the entry point can tell the
 * two apart. An unexpected failure must not have its message echoed to whoever
 * happens to be visiting — that is how internal paths leak. This one is
 * different: it is only reachable when an administrator has edited a config file
 * into a state the CMS cannot honour, it names a setting rather than an
 * internal, and the person who needs to read it is the person who broke it.
 *
 * Carries no fallback by design. Continuing on a backend nobody asked for would
 * present an empty site as a working one.
 */
final class ConfigurationException extends RuntimeException
{
}
