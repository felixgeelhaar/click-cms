<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * Measures a document's sections against the section types a site declares now.
 *
 * The question this answers — "does this content still fit the schema" — is pure
 * domain logic: schema plus content and nothing else, no files and no HTTP.
 * That is why it lives here rather than in the service that loads both off disk,
 * and why it takes already-resolved {@see SectionType} objects rather than a
 * repository it would have to reach through.
 *
 * It exists for one caller in particular: a restore. Every ordinary write goes
 * through {@see SectionValidator}, which rejects unknown section types and drops
 * undeclared fields, so stored content can only ever hold a shape the templates
 * were written for. A restore is deliberately exempt from that — verbatim
 * recovery is the right default for a safety net — which means a restore can put
 * back content the rest of the system guarantees cannot exist. This reports that
 * gap without closing it: the restore still happens, and the editor is told what
 * will not render so they can fix it before it reaches the public.
 */
final class SchemaCompatibility
{
    /** @var array<string, SectionType> */
    private array $byId = [];

    /**
     * @param iterable<SectionType> $schema The section types the site declares
     *        today. Empty is legitimate — a site may have removed every type —
     *        and simply means every section reports its type as gone.
     */
    public function __construct(iterable $schema)
    {
        foreach ($schema as $type) {
            $this->byId[$type->id] = $type;
        }
    }

    /**
     * @param array<string, mixed> $data A {@see \Click\Cms\Domain\Content\Content}
     *        data payload — the same open map a document carries, of which only
     *        `sections` is schema-governed.
     */
    public function check(array $data): SchemaCompatibilityReport
    {
        $sections = $data['sections'] ?? null;
        if (!is_array($sections)) {
            // A page with no sections — plain title-and-body content — has no
            // schema-shaped part to measure, so nothing can fail to fit.
            return SchemaCompatibilityReport::empty();
        }

        $removedSectionTypes = [];
        $strippedFields = [];

        foreach ($sections as $index => $section) {
            // A malformed section is not a schema-compatibility problem: it never
            // passed validation to begin with, and reporting it here would blame
            // the schema for content that was already broken. Skip it silently
            // and leave that judgement to whoever wrote it.
            if (!is_array($section)) {
                continue;
            }

            $typeId = $section['type'] ?? null;
            if (!is_string($typeId) || $typeId === '') {
                continue;
            }

            $index = (int) $index;

            $type = $this->byId[$typeId] ?? null;
            if ($type === null) {
                // The design is gone, so the section will render as nothing.
                // There is no surviving type to strip fields against, so this is
                // the whole of what can be said about it.
                $removedSectionTypes[] = ['index' => $index, 'type' => $typeId];
                continue;
            }

            $values = $section['values'] ?? [];
            if (!is_array($values)) {
                continue;
            }

            $declared = $type->fieldNames();
            foreach (array_keys($values) as $field) {
                // A key the section type no longer declares is exactly what a
                // normal save would drop. The section still renders; this value
                // would not survive the next edit, and the editor should know
                // before they lose it silently.
                if (!in_array($field, $declared, true)) {
                    $strippedFields[] = [
                        'index' => $index,
                        'type' => $typeId,
                        'field' => (string) $field,
                    ];
                }
            }
        }

        return SchemaCompatibilityReport::of($removedSectionTypes, $strippedFields);
    }
}
