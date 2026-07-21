<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Schema\FieldDefinition;
use Click\Cms\Domain\Schema\FieldType;
use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionTypeRepository;

/**
 * Renders a page's sections to HTML.
 *
 * Without this a site has to bring its own front end, because the CMS could
 * store sections but not show them — a page rendered its title and an empty
 * div. This is the minimum needed for the CMS to serve a site on its own.
 *
 * Deliberately plain and unopinionated. It emits semantic markup with stable
 * class names and no styling of its own, so a theme can style it without
 * fighting anything, and a site that wants full control uses the delivery API
 * and its own front end instead.
 *
 * Every value is escaped. Content is written by trusted editors, but "trusted"
 * is a statement about intent rather than a guarantee about what a paste from
 * elsewhere contains.
 */
final class SectionRenderer
{
    public function __construct(
        private readonly SectionTypeRepository $sectionTypes,
        private readonly ?MediaService $media = null,
        private readonly string $mediaBaseUrl = '/api/media/file',
    ) {}

    /**
     * Render every section of a page, in order.
     */
    public function render(Content $page): string
    {
        $sections = $page->data['sections'] ?? null;
        if (!is_array($sections)) {
            return '';
        }

        $html = '';

        foreach ($sections as $section) {
            if (!is_array($section) || !isset($section['type']) || !is_string($section['type'])) {
                continue;
            }

            $type = $this->sectionTypes->find($section['type']);
            if ($type === null) {
                // A section whose design is no longer declared is skipped rather
                // than guessed at. Its content is still in storage.
                continue;
            }

            $values = is_array($section['values'] ?? null) ? $section['values'] : [];
            $html .= $this->renderSection($type, $values);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderSection(SectionType $type, array $values): string
    {
        $body = '';
        $modifiers = '';

        foreach ($type->fields as $field) {
            if (!array_key_exists($field->name, $values)) {
                continue;
            }

            // A select is a choice about presentation — how wide, how many
            // columns — not something to print. It becomes a class the theme
            // can style, which is what "wide" and "4" appearing as paragraphs
            // showed was wrong.
            if ($field->type === FieldType::Select && is_scalar($values[$field->name])) {
                $modifiers .= ' cms-section--' . $this->escape($field->name)
                    . '-' . $this->escape((string) $values[$field->name]);
                continue;
            }

            $body .= $this->renderField($field, $values[$field->name]);
        }

        if (trim($body) === '') {
            return '';
        }

        $class = 'cms-section cms-section--' . $this->escape($type->id) . $modifiers;

        return '<section class="' . $class . '">' . $body . '</section>';
    }

    private function renderField(FieldDefinition $field, mixed $value): string
    {
        return match ($field->type) {
            FieldType::Repeater => $this->renderRepeater($field, $value),
            FieldType::Image => $this->renderImage($field, $value),
            FieldType::RichText, FieldType::Textarea => $this->renderProse($field, $value),
            FieldType::Boolean => '',
            default => $this->renderScalar($field, $value),
        };
    }

    private function renderScalar(FieldDefinition $field, mixed $value): string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }

        $text = $this->escape((string) $value);
        $class = $this->fieldClass($field);

        // The first text field of a section reads as its heading.
        if ($field->name === 'heading' || $field->name === 'title') {
            return '<h2 class="' . $class . '">' . $text . '</h2>';
        }

        if ($field->type === FieldType::Url) {
            return '<p class="' . $class . '"><a href="' . $text . '" rel="noopener noreferrer">' . $text . '</a></p>';
        }

        if ($field->type === FieldType::Email) {
            return '<p class="' . $class . '"><a href="mailto:' . $text . '">' . $text . '</a></p>';
        }

        return '<p class="' . $class . '">' . $text . '</p>';
    }

    private function renderProse(FieldDefinition $field, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        // Paragraph breaks are preserved; everything else is escaped. A rich
        // text editor storing HTML would need sanitising here, which is why the
        // field is still plain text today.
        $paragraphs = preg_split('/\n{2,}/', trim($value)) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br($this->escape(trim($paragraph))) . '</p>';
        }

        return '<div class="' . $this->fieldClass($field) . '">' . $html . '</div>';
    }

    private function renderImage(FieldDefinition $field, mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return '<div class="' . $this->fieldClass($field) . '">' . $this->imageTag($value, '') . '</div>';
    }

    /**
     * An <img>, with a srcset listing only variants that were actually
     * generated.
     *
     * Building the srcset from the naming rule alone produced entries for sizes
     * that do not exist: an upload narrower than a rung is never scaled up, so
     * the browser would pick a URL that 404s and show nothing at all.
     */
    private function imageTag(string $reference, string $alt): string
    {
        $item = $this->media?->find($reference);

        if ($item === null) {
            // The reference does not resolve — most likely content written
            // before the media library, or an item since deleted.
            $src = rtrim($this->mediaBaseUrl, '/') . '/' . $this->escape($reference);

            return '<img src="' . $src . '" alt="' . $this->escape($alt) . '" loading="lazy">';
        }

        $urls = $item->urls($this->mediaBaseUrl);
        $srcset = $item->srcset($this->mediaBaseUrl);
        $description = $alt !== '' ? $alt : $item->alt;

        $tag = '<img src="' . $this->escape($urls['original']) . '"';

        if ($srcset !== '') {
            $tag .= ' srcset="' . $this->escape($srcset) . '"'
                . ' sizes="(max-width: 640px) 100vw, 50vw"';
        }

        if ($item->width !== null && $item->height !== null) {
            // Given so the browser can reserve space and not shift the layout
            // as images arrive.
            $tag .= ' width="' . $item->width . '" height="' . $item->height . '"';
        }

        return $tag . ' alt="' . $this->escape($description) . '" loading="lazy">';
    }

    private function renderRepeater(FieldDefinition $field, mixed $value): string
    {
        if (!is_array($value) || $value === []) {
            return '';
        }

        $items = '';

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $inner = '';
            foreach ($field->fields as $sub) {
                if (!array_key_exists($sub->name, $row)) {
                    continue;
                }

                // Inside a repeater an image is the item's own picture, and any
                // sibling title is its description.
                if ($sub->type === FieldType::Image && is_string($row[$sub->name])) {
                    $alt = is_string($row['title'] ?? null) ? $row['title'] : '';
                    $inner .= $this->imageTag($row[$sub->name], $alt);
                    continue;
                }

                $inner .= $this->renderField($sub, $row[$sub->name]);
            }

            if (trim($inner) !== '') {
                $items .= '<li class="cms-item">' . $inner . '</li>';
            }
        }

        if ($items === '') {
            return '';
        }

        return '<ul class="' . $this->fieldClass($field) . ' cms-items">' . $items . '</ul>';
    }

    private function fieldClass(FieldDefinition $field): string
    {
        return 'cms-field cms-field--' . $this->escape($field->name);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
