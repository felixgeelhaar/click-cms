<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\RichTextSanitizer;
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
 * Plain values are escaped. Content is written by trusted editors, but "trusted"
 * is a statement about intent rather than a guarantee about what a paste from
 * elsewhere contains. Rich-text values are the one exception: they are HTML by
 * design, so escaping them would print their tags instead of applying them.
 * They are run through {@see RichTextSanitizer} instead, which reduces them to a
 * safe allowlist — because a value emitted as markup is an XSS surface, and the
 * admin editor's own sanitising is bypassable by anyone posting to the API.
 */
final class SectionRenderer
{
    private readonly RichTextSanitizer $sanitizer;

    public function __construct(
        private readonly SectionTypeRepository $sectionTypes,
        private readonly ?MediaService $media = null,
        private readonly string $mediaBaseUrl = '/api/media/file',
        ?RichTextSanitizer $sanitizer = null,
    ) {
        // Defaulted rather than required so existing callers are unaffected: the
        // sanitiser is pure domain logic with no dependencies of its own.
        $this->sanitizer = $sanitizer ?? new RichTextSanitizer();
    }

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

        // Fields consumed as another field's link text must not also be printed
        // on their own, or the page shows the wording twice.
        $consumed = [];
        foreach ($type->fields as $field) {
            if ($field->type === FieldType::Url && $field->labelField !== null) {
                $consumed[$field->labelField] = true;
            }
        }

        foreach ($type->fields as $field) {
            if (!array_key_exists($field->name, $values) || isset($consumed[$field->name])) {
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

            $linkText = null;
            if ($field->type === FieldType::Url && $field->labelField !== null) {
                $candidate = $values[$field->labelField] ?? null;
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $linkText = (string) $candidate;
                }
            }

            $body .= $this->renderField($field, $values[$field->name], $linkText);
        }

        if (trim($body) === '') {
            return '';
        }

        $class = 'cms-section cms-section--' . $this->escape($type->id) . $modifiers;

        return '<section class="' . $class . '">' . $body . '</section>';
    }

    private function renderField(FieldDefinition $field, mixed $value, ?string $linkText = null): string
    {
        return match ($field->type) {
            FieldType::Repeater => $this->renderRepeater($field, $value),
            FieldType::Image => $this->renderImage($field, $value),
            FieldType::RichText => $this->renderRichText($field, $value),
            FieldType::Textarea => $this->renderProse($field, $value),
            FieldType::Boolean => '',
            default => $this->renderScalar($field, $value, $linkText),
        };
    }

    private function renderScalar(FieldDefinition $field, mixed $value, ?string $linkText = null): string
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
            // Showing the address as the link's wording is what a reader sees as
            // "https://…/kontakt" sitting on the page. When the section declares a
            // field for the wording, that is what the link says instead.
            $label = $linkText !== null ? $this->escape($linkText) : $text;

            return '<p class="' . $class . '"><a href="' . $text . '" rel="noopener noreferrer">' . $label . '</a></p>';
        }

        if ($field->type === FieldType::Email) {
            return '<p class="' . $class . '"><a href="mailto:' . $text . '">' . $text . '</a></p>';
        }

        return '<p class="' . $class . '">' . $text . '</p>';
    }

    /**
     * A rich-text value is HTML the editor authored, so it is emitted as markup
     * rather than escaped — its whole point is that the bold, links and lists
     * apply. That makes it an XSS surface, since a value stored through a direct
     * API call never passed the admin editor's own sanitising. The sanitiser is
     * the boundary that holds: it reduces the value to a safe allowlist before a
     * single byte reaches the page. An empty result renders nothing, so a value
     * that was entirely stripped does not leave a bare, classed div behind.
     */
    private function renderRichText(FieldDefinition $field, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $safe = $this->sanitizer->sanitize($value);
        if (trim($safe) === '') {
            return '';
        }

        return '<div class="' . $this->fieldClass($field) . '">' . $safe . '</div>';
    }

    private function renderProse(FieldDefinition $field, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        // A textarea is plain prose, not markup: paragraph breaks are preserved
        // and everything else is escaped. Rich text takes the separate path
        // above, where its HTML is sanitised rather than printed as text.
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
    private function imageTag(string $reference, string $alt, string $fallbackAlt = ''): string
    {
        $item = $this->media?->find($reference);

        if ($item === null) {
            // The reference does not resolve — most likely content written
            // before the media library, or an item since deleted.
            $src = rtrim($this->mediaBaseUrl, '/') . '/' . $this->escape($reference);
            $unresolved = $alt !== '' ? $alt : $fallbackAlt;

            return '<img src="' . $src . '" alt="' . $this->escape($unresolved) . '" loading="lazy">';
        }

        $urls = $item->urls($this->mediaBaseUrl);
        $srcset = $item->srcset($this->mediaBaseUrl);
        // The library's own description is the one written to describe this
        // picture, so it wins over anything inferred from surrounding content.
        $description = $alt !== '' ? $alt : ($item->alt !== '' ? $item->alt : $fallbackAlt);

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

                // A sibling title is only a fallback description. Letting it win
                // threw away the wording an editor wrote in the media library and
                // made a screen reader announce the card's heading twice — once as
                // the heading, once as the picture.
                if ($sub->type === FieldType::Image && is_string($row[$sub->name])) {
                    $fallback = is_string($row['title'] ?? null) ? $row['title'] : '';
                    $inner .= $this->imageTag($row[$sub->name], '', $fallback);
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
