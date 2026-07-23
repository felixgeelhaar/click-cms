<?php

declare(strict_types=1);

namespace Click\Cms\Application\Collection;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Collection\CollectionTypeRepository;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * The reverse of a reference: which entries point *at* a given item.
 *
 * A reference field stores the slug of its target, so the forward direction — an
 * author for this post — is a cheap read. The back direction — every post by
 * this author — has no stored index, and building one that a flat-file write
 * would have to keep consistent is exactly the kind of moving part this CMS
 * avoids. Instead this scans: for each collection type that declares a reference
 * field pointing at the target's type, it reads that type's working copies and
 * collects the ones whose field holds the target slug.
 *
 * The scan is bounded by the reference *schema*, not the content: only types
 * that actually declare a matching reference field are read, so a site with no
 * relation to the target does no work. Matching is within one language, the same
 * way forward resolution is — a German post references the German author — so a
 * back-reference list is per locale and never mixes languages.
 *
 * Referrers are working copies, because this answers an editor's question ("what
 * links here before I delete it?"), which must include drafts. It is not a
 * delivery surface.
 */
final class BackReferenceService
{
    public function __construct(
        private readonly CollectionTypeRepository $types,
        private readonly ContentService $content,
    ) {
    }

    /**
     * Every entry that references ($targetType, $targetSlug) in $locale.
     *
     * @return list<array{type: string, slug: string, title: string, field: string, locale: string}>
     */
    public function referencesTo(
        string $targetType,
        string $targetSlug,
        string|Locale|null $locale = null,
    ): array {
        $out = [];

        foreach ($this->types->all() as $type) {
            // Only the reference fields on this type that could point at the
            // target. A type with none is skipped without reading any content.
            $matchingFields = array_values(array_filter(
                $type->fields(),
                static fn ($field): bool => $field->type === FieldType::Reference
                    && $field->references === $targetType,
            ));

            if ($matchingFields === []) {
                continue;
            }

            foreach ($this->content->workingCopies($type->id, $locale) as $entry) {
                if (!$entry instanceof Content) {
                    continue;
                }

                foreach ($matchingFields as $field) {
                    if ($this->pointsAt($entry->data[$field->name] ?? null, $targetSlug)) {
                        $out[] = [
                            'type' => $type->id,
                            'slug' => $entry->slug(),
                            'title' => $type->titleOf($entry->data),
                            'field' => $field->name,
                            'locale' => $entry->locale()->code,
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Whether a stored field value references the target slug — a single slug is
     * equality, a many-valued field is membership.
     */
    private function pointsAt(mixed $value, string $targetSlug): bool
    {
        if (is_array($value)) {
            return in_array($targetSlug, $value, true);
        }

        return is_string($value) && $value === $targetSlug;
    }
}
