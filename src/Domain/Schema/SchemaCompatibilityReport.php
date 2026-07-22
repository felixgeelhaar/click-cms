<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * What no longer fits, when a document from history is measured against the
 * schema the site declares today.
 *
 * A restore is verbatim on purpose — a safety net that silently strips content
 * during a recovery is worse than one that warns — so this report never changes
 * what is restored. It exists to say what {@see SectionValidator} would have
 * refused or dropped had the same content arrived through a normal write, so an
 * editor can fix a restored page before publishing rather than discover live
 * that a section renders as nothing.
 *
 * Two kinds of trouble, kept apart because the remedy differs:
 *
 *  - {@see $removedSectionTypes} — a section whose type the schema no longer
 *    declares. The site has no template for it, so it will not render at all;
 *    the editor's only options are to recreate the design or delete the section.
 *  - {@see $strippedFields} — a field a surviving section type no longer
 *    declares. The section still renders, but that value would vanish the moment
 *    the page is saved through the editor, because a normal write keeps only
 *    declared fields — the rule that stored content only ever holds a shape the
 *    templates were written for.
 *
 * When both lists are empty the restored content fits the current schema exactly
 * and nothing about today's behaviour changes.
 */
final class SchemaCompatibilityReport
{
    /**
     * @param list<array{index: int, type: string}> $removedSectionTypes
     * @param list<array{index: int, type: string, field: string}> $strippedFields
     */
    private function __construct(
        public readonly array $removedSectionTypes,
        public readonly array $strippedFields,
    ) {}

    /**
     * @param list<array{index: int, type: string}> $removedSectionTypes
     * @param list<array{index: int, type: string, field: string}> $strippedFields
     */
    public static function of(array $removedSectionTypes, array $strippedFields): self
    {
        return new self($removedSectionTypes, $strippedFields);
    }

    /**
     * No warnings at all.
     *
     * Used both when a document fits the schema and when there is no schema to
     * measure against — a caller with no section types configured gets the same
     * "nothing to warn about" as one whose content is clean, because in neither
     * case is there anything the editor could act on.
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    public function fits(): bool
    {
        return $this->removedSectionTypes === [] && $this->strippedFields === [];
    }

    /**
     * @return array{
     *     fits: bool,
     *     removedSectionTypes: list<array{index: int, type: string}>,
     *     strippedFields: list<array{index: int, type: string, field: string}>
     * }
     */
    public function toArray(): array
    {
        // `fits` is derived, but included so the HTTP layer and the admin UI can
        // branch on one boolean rather than each re-deriving it from two lists
        // and risking a different answer.
        return [
            'fits' => $this->fits(),
            'removedSectionTypes' => $this->removedSectionTypes,
            'strippedFields' => $this->strippedFields,
        ];
    }
}
