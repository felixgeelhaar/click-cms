<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testAdminHasEveryCapability(): void
    {
        foreach (Capability::cases() as $capability) {
            $this->assertTrue(Role::Admin->can($capability), $capability->value);
        }
    }

    /**
     * The two capabilities that can change what code runs, or who can reach it,
     * must stay with administrators only.
     */
    public function testOnlyAdminsMayInstallPluginsOrManageUsers(): void
    {
        foreach ([Role::Editor, Role::Author, Role::Viewer] as $role) {
            $this->assertFalse($role->can(Capability::InstallPlugins), $role->value);
            $this->assertFalse($role->can(Capability::ManageUsers), $role->value);
            $this->assertFalse($role->can(Capability::ManagePlugins), $role->value);
        }
    }

    /**
     * An author drafts; someone else decides it goes live.
     */
    public function testAuthorsCannotPublish(): void
    {
        $this->assertFalse(Role::Author->can(Capability::PublishContent));
        $this->assertTrue(Role::Editor->can(Capability::PublishContent));
    }

    /**
     * The editing-modes decision, made expressible: the builder can change a
     * site's layout, so it is not something an editor gets by default.
     */
    public function testOnlyAdminsGetTheFreeFormBuilderByDefault(): void
    {
        $this->assertTrue(Role::Admin->can(Capability::UseFreeFormBuilder));
        $this->assertFalse(Role::Editor->can(Capability::UseFreeFormBuilder));
        $this->assertFalse(Role::Author->can(Capability::UseFreeFormBuilder));

        // But everyone who edits gets the constrained editor.
        $this->assertTrue(Role::Editor->can(Capability::UseSectionEditor));
        $this->assertTrue(Role::Author->can(Capability::UseSectionEditor));
    }

    /**
     * Preview hands unpublished work to whoever holds the link, so it is
     * granted to the roles that write and reviewed — and withheld from the one
     * an unrecognised role falls back into.
     */
    public function testPreviewIsForThoseWhoEditAndNotForTheFallbackRole(): void
    {
        $this->assertTrue(Role::Admin->can(Capability::PreviewContent));
        $this->assertTrue(Role::Editor->can(Capability::PreviewContent));

        // An author cannot publish, so preview is the only way their work can
        // be seen by whoever decides that it should be.
        $this->assertTrue(Role::Author->can(Capability::PreviewContent));

        $this->assertFalse(Role::Viewer->can(Capability::PreviewContent));
        $this->assertFalse(Role::fromName('wizard')->can(Capability::PreviewContent));
    }

    public function testViewersMayOnlyRead(): void
    {
        $this->assertTrue(Role::Viewer->can(Capability::ViewContent));
        $this->assertTrue(Role::Viewer->can(Capability::ViewMedia));
        $this->assertFalse(Role::Viewer->can(Capability::CreateContent));
        $this->assertFalse(Role::Viewer->can(Capability::UploadMedia));
        $this->assertFalse(Role::Viewer->can(Capability::UseSectionEditor));
    }

    /**
     * A stored account naming a role this version does not know about should
     * lose access, not break the application.
     */
    public function testAnUnknownRoleFallsBackToTheLeastPrivileged(): void
    {
        $this->assertSame(Role::Viewer, Role::fromName('wizard'));
        $this->assertSame(Role::Viewer, Role::fromName(null));
        $this->assertSame(Role::Viewer, Role::fromName(''));
    }

    public function testRoleNamesAreMatchedCaseInsensitively(): void
    {
        $this->assertSame(Role::Admin, Role::fromName('Admin'));
        $this->assertSame(Role::Editor, Role::fromName('  EDITOR '));
    }

    public function testEditingSomeoneElsesContent(): void
    {
        $this->assertTrue(Role::Editor->canEditContentOwnedBy('bob', 'ann'));
        $this->assertFalse(Role::Author->canEditContentOwnedBy('bob', 'ann'));
        $this->assertTrue(Role::Author->canEditContentOwnedBy('ann', 'ann'));
    }

    /**
     * Deleting cannot be partially undone, so an editor may change anyone's
     * page but remove only their own.
     */
    public function testDeletionIsStricterThanEditing(): void
    {
        $this->assertTrue(Role::Editor->canEditContentOwnedBy('bob', 'ann'));
        $this->assertFalse(Role::Editor->canDeleteContentOwnedBy('bob', 'ann'));
        $this->assertTrue(Role::Editor->canDeleteContentOwnedBy('ann', 'ann'));
        $this->assertTrue(Role::Admin->canDeleteContentOwnedBy('bob', 'ann'));
    }

    /**
     * Ownership checks must not pass on missing data — an unowned page is not
     * everyone's page.
     */
    public function testMissingOwnerOrUsernameNeverGrantsAccess(): void
    {
        $this->assertFalse(Role::Author->canEditContentOwnedBy(null, 'ann'));
        $this->assertFalse(Role::Author->canEditContentOwnedBy('ann', null));
        $this->assertFalse(Role::Author->canEditContentOwnedBy(null, null));
    }

    public function testCapabilityNamesAreStable(): void
    {
        $this->assertContains('content.view', Role::Viewer->capabilityNames());
        $this->assertContains('plugins.install', Role::Admin->capabilityNames());
        $this->assertContains('content.restore', Role::Editor->capabilityNames());
    }

    /**
     * Restoring is safe to grant widely because it writes a new version rather
     * than discarding one, so everyone who may edit gets it — but a reader
     * does not.
     */
    public function testRestoringIsGrantedToEveryoneWhoMayEdit(): void
    {
        $this->assertTrue(Role::Admin->can(Capability::RestoreContent));
        $this->assertTrue(Role::Editor->can(Capability::RestoreContent));
        $this->assertTrue(Role::Author->can(Capability::RestoreContent));
        $this->assertFalse(Role::Viewer->can(Capability::RestoreContent));
    }

    /**
     * Both halves of the question, so a role cannot be granted history over
     * content it has no business editing.
     */
    public function testRestoringNeedsTheReachToEditThatDocumentToo(): void
    {
        $this->assertTrue(Role::Editor->canRestoreContentOwnedBy('bob', 'ann'));
        $this->assertTrue(Role::Author->canRestoreContentOwnedBy('ann', 'ann'));
        $this->assertFalse(Role::Author->canRestoreContentOwnedBy('bob', 'ann'));
        $this->assertFalse(Role::Viewer->canRestoreContentOwnedBy('ann', 'ann'));
        $this->assertFalse(Role::Author->canRestoreContentOwnedBy(null, 'ann'));
    }
}
