<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity;

/**
 * The things an account may be permitted to do.
 *
 * Named capabilities rather than role checks scattered through the code. The
 * difference matters: `$role === 'admin'` spread across a dozen files cannot be
 * changed without finding every one of them, and cannot express "an editor may
 * do this but not that" at all. A capability is asked about once, in the place
 * that cares, and the mapping lives in exactly one file.
 *
 * This is what makes the editing-modes decision expressible: an administrator
 * building a site may use a free-form builder while an editor maintaining it is
 * restricted to declared section types, and that is a capability question
 * rather than a role check.
 */
enum Capability: string
{
    /* Content */
    case ViewContent = 'content.view';
    case CreateContent = 'content.create';
    case EditAnyContent = 'content.edit.any';
    case EditOwnContent = 'content.edit.own';
    case DeleteAnyContent = 'content.delete.any';
    case DeleteOwnContent = 'content.delete.own';
    case PublishContent = 'content.publish';

    // Putting an earlier version of a document back. Separate from editing
    // because it is the one action whose whole purpose is to undo somebody
    // else's, and a site may reasonably want that held to a higher bar than
    // ordinary edits — while still being safe to grant widely, since a restore
    // writes a new version rather than discarding one.
    case RestoreContent = 'content.restore';

    /* Media */
    case ViewMedia = 'media.view';
    case UploadMedia = 'media.upload';
    case DeleteAnyMedia = 'media.delete.any';

    /* Editing modes — see docs/backlog.md */
    case UseSectionEditor = 'edit.sections';
    case UseFreeFormBuilder = 'edit.freeform';

    /* Administration */
    case ManageUsers = 'users.manage';
    case ManagePlugins = 'plugins.manage';
    case InstallPlugins = 'plugins.install';
    case ManageSettings = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::ViewContent => 'View content',
            self::CreateContent => 'Create content',
            self::EditAnyContent => "Edit anyone's content",
            self::EditOwnContent => 'Edit own content',
            self::DeleteAnyContent => "Delete anyone's content",
            self::DeleteOwnContent => 'Delete own content',
            self::PublishContent => 'Publish content',
            self::RestoreContent => 'Restore a previous version',
            self::ViewMedia => 'View media',
            self::UploadMedia => 'Upload media',
            self::DeleteAnyMedia => 'Delete any media',
            self::UseSectionEditor => 'Use the section editor',
            self::UseFreeFormBuilder => 'Use the free-form builder',
            self::ManageUsers => 'Manage users',
            self::ManagePlugins => 'Manage plugins',
            self::InstallPlugins => 'Install plugins',
            self::ManageSettings => 'Manage settings',
        };
    }
}
