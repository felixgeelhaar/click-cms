<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\History;

use Click\Cms\Application\History\HistoryService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\History\Version;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionTypeRepository;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class HistoryServiceTest extends TestCase
{
    private string $root;
    private JsonVersionStore $versions;
    private VersioningStorage $storage;
    private HistoryService $history;
    private ?string $author = 'ada';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-history-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0o775, true);

        $this->versions = new JsonVersionStore($this->root . '/versions');
        $this->storage = new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            $this->versions,
            fn (): ?string => $this->author,
        );
        $this->history = new HistoryService($this->storage, $this->versions);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function key(): ContentKey
    {
        return ContentKey::page('home');
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'boss', 'role' => 'admin'];
    }

    /**
     * Write a sequence of titles, returning the version identifier of each.
     *
     * @param list<string> $titles
     * @return list<string>
     */
    private function writeEach(array $titles): array
    {
        $page = Content::create($this->key(), ['title' => $titles[0], 'owner' => 'ada']);

        $ids = [];
        foreach ($titles as $title) {
            $page->update(['title' => $title]);
            $this->storage->save($page);
            $ids[] = $this->versions->all($this->key())[0]->id;
        }

        return $ids;
    }

    /* --------------------------------------------------------------- list -- */

    public function testListsEveryVersionNewestFirst(): void
    {
        $this->writeEach(['One', 'Two', 'Three']);

        $result = $this->history->all($this->key(), $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame(['Three', 'Two', 'One'], array_column($result['versions'], 'title'));
    }

    public function testAListingSaysWhenAndByWhom(): void
    {
        $this->author = 'grace';
        $this->writeEach(['One']);

        $entry = $this->history->all($this->key(), $this->admin())['versions'][0];

        $this->assertSame('grace', $entry['author']);
        $this->assertNotFalse(strtotime($entry['recordedAt']));
        $this->assertSame(Version::REASON_SAVE, $entry['reason']);
    }

    public function testADocumentWithNoHistoryListsNothingRatherThanFailing(): void
    {
        $result = $this->history->all($this->key(), $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame([], $result['versions']);
    }

    /**
     * An unrecognised role falls back to Viewer, which may read content and so
     * may read its history. Worth pinning down: history exposes unpublished
     * drafts, so if that fallback ever stops being able to read content this
     * must stop being readable with it.
     */
    public function testAnUnrecognisedRoleGetsTheSameHistoryAccessAsAViewer(): void
    {
        $this->writeEach(['One']);

        $unknown = $this->history->all($this->key(), ['username' => 'nobody', 'role' => 'ghost']);
        $viewer = $this->history->all($this->key(), ['username' => 'val', 'role' => 'viewer']);

        $this->assertSame($viewer['status'], $unknown['status']);
        $this->assertNull($unknown['error']);
    }

    /* ---------------------------------------------------------------- get -- */

    public function testASingleVersionIsReadableInFull(): void
    {
        $ids = $this->writeEach(['One', 'Two']);

        $result = $this->history->get($this->key(), $ids[0], $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame('One', $result['version']->content()->title());
    }

    public function testAnUnknownVersionIsNotFound(): void
    {
        $result = $this->history->get($this->key(), '20260721T104512.123456Z-a3f9', $this->admin());

        $this->assertSame(404, $result['status']);
    }

    /* ------------------------------------------------------------ restore -- */

    /**
     * Rewritten for draft-and-publish. These restore tests all asserted through
     * `find()`, which was the same thing as the working copy while a save went
     * straight into `content/`. It no longer is: a restore replaces what the
     * editor is working on and leaves publication alone, so the assertions moved
     * to `draft()`. Putting an earlier version straight in front of the public
     * would make undo the one editing action with no review step.
     */
    public function testRestoringPutsTheEarlierStateBack(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);

        $result = $this->history->restore($this->key(), $ids[0], $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame('Original', $this->storage->draft($this->key())?->title());
    }

    /**
     * The whole safety argument: a restore writes forward, so the state it
     * replaced is still there to go back to.
     */
    public function testRestoringCannotLoseTheStateItReplaced(): void
    {
        $ids = $this->writeEach(['Original', 'Work I still want']);

        $this->history->restore($this->key(), $ids[0], $this->admin());

        $titles = array_column(
            $this->history->all($this->key(), $this->admin())['versions'],
            'title'
        );

        $this->assertSame(['Original', 'Work I still want', 'Original'], $titles);
    }

    public function testRestoringTheWrongVersionIsItselfUndoable(): void
    {
        $ids = $this->writeEach(['One', 'Two', 'Three']);

        $this->history->restore($this->key(), $ids[0], $this->admin());
        $this->assertSame('One', $this->storage->draft($this->key())?->title());

        // Changed their mind: the state before the restore is still listed.
        $before = $this->history->all($this->key(), $this->admin())['versions'][1];
        $this->history->restore($this->key(), $before['id'], $this->admin());

        $this->assertSame('Three', $this->storage->draft($this->key())?->title());
    }

    public function testARestoreIsMarkedAsOneInTheHistory(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);

        // The storage decorator reads the author from the session rather than
        // from the call, because it serves every write and not just this one.
        // In a request the two are the same person; here the fixture has to be
        // told so.
        $this->author = 'boss';
        $this->history->restore($this->key(), $ids[0], $this->admin());

        $newest = $this->history->all($this->key(), $this->admin())['versions'][0];

        $this->assertSame(Version::REASON_RESTORE, $newest['reason']);
        $this->assertSame('boss', $newest['author']);
    }

    /**
     * The content is old but the edit is new, and a listing sorted by "last
     * edited" would otherwise bury the page the editor just worked on.
     */
    public function testARestoredDocumentCountsAsJustEdited(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);
        $before = $this->storage->draft($this->key())?->updatedAt();

        $this->history->restore($this->key(), $ids[0], $this->admin());

        $this->assertGreaterThanOrEqual($before, $this->storage->draft($this->key())?->updatedAt());
    }

    /**
     * Versions outlive the document, which is what makes a deleted page
     * recoverable rather than merely mournable.
     */
    public function testADeletedDocumentCanBeRestored(): void
    {
        $ids = $this->writeEach(['Wanted after all']);
        $this->storage->delete($this->key());

        $result = $this->history->restore($this->key(), $ids[0], $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame('Wanted after all', $this->storage->draft($this->key())?->title());
    }

    public function testRestoringAnUnknownVersionIsNotFound(): void
    {
        $this->writeEach(['One']);

        $result = $this->history->restore($this->key(), '20260721T104512.123456Z-a3f9', $this->admin());

        $this->assertSame(404, $result['status']);
        $this->assertSame('One', $this->storage->draft($this->key())?->title());
    }

    /**
     * Content written straight onto disk never passed through versioning, so
     * restoring over it would be the one remaining way to lose work.
     */
    public function testAStateThatWasNeverRetainedIsRetainedBeforeItIsReplaced(): void
    {
        $ids = $this->writeEach(['Original']);

        // Behind the decorator's back, as a seeder or a swapped-in backend
        // would be.
        (new JsonStorage($this->root . '/content'))->save(
            Content::create($this->key(), ['title' => 'Never recorded', 'owner' => 'ada'])
        );

        $this->history->restore($this->key(), $ids[0], $this->admin());

        $titles = array_column(
            $this->history->all($this->key(), $this->admin())['versions'],
            'title'
        );

        $this->assertContains('Never recorded', $titles);
        $this->assertSame('Original', $this->storage->draft($this->key())?->title());
    }

    public function testAnAlreadyRetainedStateIsNotRetainedTwice(): void
    {
        $ids = $this->writeEach(['One', 'Two']);

        $this->history->restore($this->key(), $ids[0], $this->admin());

        // Two saves plus the restore, and nothing spurious in between.
        $this->assertCount(3, $this->history->all($this->key(), $this->admin())['versions']);
    }

    /* -------------------------------------------------------- permissions -- */

    public function testAnAuthorMayRestoreTheirOwnPage(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);

        $result = $this->history->restore(
            $this->key(),
            $ids[0],
            ['username' => 'ada', 'role' => 'author']
        );

        $this->assertNull($result['error']);
        $this->assertSame('Original', $this->storage->draft($this->key())?->title());
    }

    public function testAnAuthorMayNotRestoreSomebodyElsesPage(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);

        $result = $this->history->restore(
            $this->key(),
            $ids[0],
            ['username' => 'mallory', 'role' => 'author']
        );

        $this->assertSame(403, $result['status']);
        $this->assertSame('Mistake', $this->storage->draft($this->key())?->title());
    }

    public function testAViewerMayReadHistoryButNotRestore(): void
    {
        $ids = $this->writeEach(['Original', 'Mistake']);
        $viewer = ['username' => 'val', 'role' => 'viewer'];

        $this->assertNull($this->history->all($this->key(), $viewer)['error']);

        $result = $this->history->restore($this->key(), $ids[0], $viewer);

        $this->assertSame(403, $result['status']);
        $this->assertSame('Mistake', $this->storage->draft($this->key())?->title());
    }

    /**
     * With the document gone there is no current owner to ask about, so the
     * snapshot's own record of it decides.
     */
    public function testOwnershipOfADeletedPageComesFromTheVersion(): void
    {
        $ids = $this->writeEach(['Gone']);
        $this->storage->delete($this->key());

        $this->assertSame(
            403,
            $this->history->restore($this->key(), $ids[0], ['username' => 'mallory', 'role' => 'author'])['status']
        );
        $this->assertNull(
            $this->history->restore($this->key(), $ids[0], ['username' => 'ada', 'role' => 'author'])['error']
        );
    }

    /* ------------------------------------------------ schema re-validation -- */

    /**
     * A section type declaring exactly the fields the fixtures use, so a saved
     * page fits the schema until a test removes the type or a field from it.
     */
    private function heroType(string ...$fields): SectionType
    {
        $fields = $fields === [] ? ['heading', 'body'] : $fields;

        return SectionType::fromArray([
            'id' => 'hero',
            'label' => 'Hero',
            'fields' => array_map(
                static fn (string $name): array => ['name' => $name, 'type' => 'text', 'label' => $name],
                $fields
            ),
        ]);
    }

    /**
     * A HistoryService wired to a specific current schema, sharing this test's
     * storage and version store so a page written in setUp can be restored
     * against a schema that has since changed.
     *
     * @param list<SectionType> $types
     */
    private function historyWithSchema(array $types): HistoryService
    {
        return new HistoryService($this->storage, $this->versions, new InMemorySectionTypes($types));
    }

    /**
     * Save a page carrying one hero section, then a second edit, and return the
     * version identifier of the first — the state a test will restore.
     *
     * @param array<string, mixed> $heroValues
     */
    private function saveHeroThenEdit(array $heroValues): string
    {
        $page = Content::create($this->key(), [
            'title' => 'Original',
            'owner' => 'ada',
            'sections' => [['type' => 'hero', 'values' => $heroValues]],
        ]);
        $this->storage->save($page);
        $original = $this->versions->all($this->key())[0]->id;

        $page->update(['title' => 'Later edit', 'sections' => []]);
        $this->storage->save($page);

        return $original;
    }

    /**
     * The reassuring case: the design the restored content uses still exists and
     * uses the same fields, so verbatim recovery reintroduces nothing the schema
     * cannot account for and there is nothing to warn about.
     */
    public function testRestoringContentThatStillFitsTheSchemaWarnsAboutNothing(): void
    {
        $original = $this->saveHeroThenEdit(['heading' => 'Hi', 'body' => 'There']);
        $history = $this->historyWithSchema([$this->heroType()]);

        $result = $history->restore($this->key(), $original, $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['warnings']->fits());

        // Restored verbatim regardless.
        $this->assertSame(
            [['type' => 'hero', 'values' => ['heading' => 'Hi', 'body' => 'There']]],
            $this->storage->draft($this->key())?->data['sections']
        );
    }

    /**
     * The case the rule exists for. Every normal write goes through
     * SectionValidator, which rejects a section type the schema does not
     * declare, so stored content can only ever hold a shape the templates were
     * written for. A restore is exempt from that — verbatim recovery is the
     * right default — so it puts the removed-type section back, and the warning
     * is what tells the editor it will not render before they publish it.
     */
    public function testRestoringASectionWhoseTypeWasRemovedReportsItButStillRestores(): void
    {
        $original = $this->saveHeroThenEdit(['heading' => 'Hi', 'body' => 'There']);

        // The site no longer offers the hero design at all.
        $history = $this->historyWithSchema([]);

        $result = $history->restore($this->key(), $original, $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['warnings']->fits());
        $this->assertSame(
            [['index' => 0, 'type' => 'hero']],
            $result['warnings']->removedSectionTypes
        );

        // The restore still happened and the content is verbatim.
        $this->assertSame(
            [['type' => 'hero', 'values' => ['heading' => 'Hi', 'body' => 'There']]],
            $this->storage->draft($this->key())?->data['sections']
        );
    }

    /**
     * A field the section type no longer declares is exactly what a normal save
     * would strip. The restore keeps it verbatim; the warning tells the editor
     * it would vanish on the next edit, so they lose it on purpose rather than
     * by surprise.
     */
    public function testRestoringAFieldTheSchemaNoLongerDeclaresReportsItButStillRestores(): void
    {
        $original = $this->saveHeroThenEdit(['heading' => 'Hi', 'subtitle' => 'Legacy']);

        // The hero design survives, but its "subtitle" field is gone.
        $history = $this->historyWithSchema([$this->heroType('heading', 'body')]);

        $result = $history->restore($this->key(), $original, $this->admin());

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['warnings']->fits());
        $this->assertSame(
            [['index' => 0, 'type' => 'hero', 'field' => 'subtitle']],
            $result['warnings']->strippedFields
        );

        // Verbatim: the field the schema would strip is still there after the
        // restore, which is the whole point of a verbatim safety net.
        $this->assertSame(
            ['heading' => 'Hi', 'subtitle' => 'Legacy'],
            $this->storage->draft($this->key())?->data['sections'][0]['values']
        );
    }

    /**
     * With no schema wired in — the state before the HTTP layer is taught to
     * supply one — restore behaves exactly as it did before, warning about
     * nothing, so adding the parameter cannot regress an existing caller.
     */
    public function testWithNoSchemaConfiguredRestoreReportsNoWarnings(): void
    {
        $original = $this->saveHeroThenEdit(['heading' => 'Hi', 'body' => 'There']);

        $result = $this->history->restore($this->key(), $original, $this->admin());

        $this->assertNull($result['error']);
        $this->assertTrue($result['warnings']->fits());
        $this->assertSame('Original', $this->storage->draft($this->key())?->title());
    }

    /* ---------------------------------------------------------- retention -- */

    public function testRestoringIsSubjectToTheSameRetentionAsAnyOtherWrite(): void
    {
        $versions = new JsonVersionStore($this->root . '/versions', RetentionPolicy::keeping(2));
        $storage = new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            $versions,
            fn (): ?string => $this->author,
        );
        $history = new HistoryService($storage, $versions);

        $page = Content::create($this->key(), ['title' => 'One', 'owner' => 'ada']);
        foreach (['One', 'Two', 'Three'] as $title) {
            $page->update(['title' => $title]);
            $storage->save($page);
        }

        $oldest = $versions->all($this->key())[1];
        $history->restore($this->key(), $oldest->id, $this->admin());

        $this->assertCount(2, $versions->all($this->key()));
    }
}

/**
 * A section-type source held in memory, so a test can hand a restore whatever
 * schema it needs to exercise — including one a type or a field has been removed
 * from — without writing definition files to disk.
 */
final class InMemorySectionTypes implements SectionTypeRepository
{
    /** @var array<string, SectionType> */
    private array $byId = [];

    /** @param list<SectionType> $types */
    public function __construct(array $types)
    {
        foreach ($types as $type) {
            $this->byId[$type->id] = $type;
        }
    }

    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(string $id): ?SectionType
    {
        return $this->byId[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function errors(): array
    {
        return [];
    }
}
