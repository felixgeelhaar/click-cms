<?php

declare(strict_types=1);

namespace Click\Cms\Application\Editing;

use Click\Cms\Application\Config\Settings;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Whether free-form (visual builder) editing is allowed for a given account on
 * this site.
 *
 * Two gates, both required:
 *
 * 1. The site has free-form editing turned on ({@see Settings::freeformEditing()}).
 *    Off means nobody — including an administrator — may write a `builder`
 *    payload. That is the agency guarantee: a constrained site stays constrained.
 * 2. The account holds {@see Capability::UseFreeFormBuilder}. By default only
 *    administrators do; the role map is the per-role half of the backlog's
 *    "site declares which modes editors get".
 *
 * Either gate alone used to be incomplete: the UI hid the Builder from
 * non-admins, but the page API accepted a `builder` body from anyone who could
 * edit pages.
 */
final class FreeformPolicy
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param array<string, mixed>|null $user Session user, or null when anonymous.
     */
    public function allows(?array $user): bool
    {
        if (!$this->settings->freeformEditing()) {
            return false;
        }

        return Role::fromName($user['role'] ?? null)->can(Capability::UseFreeFormBuilder);
    }

    /**
     * Why {@see allows()} returned false, for an error message. Null when allowed.
     *
     * @param array<string, mixed>|null $user
     */
    public function refusal(?array $user): ?string
    {
        if ($this->allows($user)) {
            return null;
        }

        if (!$this->settings->freeformEditing()) {
            return 'Free-form editing is turned off for this site.';
        }

        return 'You do not have permission to use the free-form builder.';
    }
}
