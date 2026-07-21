<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity;

/**
 * The roles an account can hold, and what each may do.
 *
 * The mapping lives here and nowhere else. Previously the rules were expressed
 * as `$role === 'admin'` in whichever file happened to need them, which meant
 * they could not be changed without finding every occurrence and could not be
 * shown to anyone.
 *
 * An unrecognised role is treated as the least privileged rather than rejected:
 * a stored account with a role this version does not know about should lose
 * access, not lock the application.
 */
enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';
    case Viewer = 'viewer';

    public static function fromName(?string $name): self
    {
        return self::tryFrom(strtolower(trim((string) $name))) ?? self::Viewer;
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Editor => 'Editor',
            self::Author => 'Author',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            // Everything, including the two that can change what code runs:
            // installing plugins and managing users.
            self::Admin => Capability::cases(),

            // Full editorial control, but cannot change who has access or what
            // code the site runs. This is the role a site's own staff hold.
            self::Editor => [
                Capability::ViewContent,
                Capability::CreateContent,
                Capability::EditAnyContent,
                Capability::EditOwnContent,
                Capability::DeleteOwnContent,
                Capability::PublishContent,
                Capability::PreviewContent,
                Capability::ViewMedia,
                Capability::UploadMedia,
                Capability::UseSectionEditor,
            ],

            // Writes and maintains their own work only. Notably has neither
            // PublishContent nor the free-form builder: an author drafts, and
            // someone else decides it goes live.
            //
            // Preview is granted precisely because of that: showing the draft
            // to whoever makes that decision is the author's job, and without
            // it the only way to be reviewed is to be published first.
            self::Author => [
                Capability::ViewContent,
                Capability::CreateContent,
                Capability::EditOwnContent,
                Capability::DeleteOwnContent,
                Capability::PreviewContent,
                Capability::ViewMedia,
                Capability::UploadMedia,
                Capability::UseSectionEditor,
            ],

            // Read-only. Also the fallback for an unrecognised role, which is
            // why it must be safe to land in by accident — and why it does not
            // get PreviewContent. A viewer may read unpublished work inside the
            // CMS, but minting a link that shows it to somebody with no account
            // is a decision to let it out of the building, and that must not be
            // something an account lands in by having a role nobody recognises.
            self::Viewer => [
                Capability::ViewContent,
                Capability::ViewMedia,
            ],
        };
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Whether this role may act on a piece of content owned by someone.
     *
     * Collapses the "any" and "own" pair into the single question a caller
     * actually has, so no caller has to remember to check both.
     */
    public function canEditContentOwnedBy(?string $owner, ?string $username): bool
    {
        if ($this->can(Capability::EditAnyContent)) {
            return true;
        }

        return $this->can(Capability::EditOwnContent)
            && $owner !== null
            && $username !== null
            && $owner === $username;
    }

    public function canDeleteContentOwnedBy(?string $owner, ?string $username): bool
    {
        if ($this->can(Capability::DeleteAnyContent)) {
            return true;
        }

        return $this->can(Capability::DeleteOwnContent)
            && $owner !== null
            && $username !== null
            && $owner === $username;
    }

    /**
     * @return list<string>
     */
    public function capabilityNames(): array
    {
        return array_map(static fn (Capability $c): string => $c->value, $this->capabilities());
    }
}
