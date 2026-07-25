<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\RichTextSanitizer;
use Click\Cms\Application\Collection\EntryListings;
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
    /**
     * The section type whose content is not its own field values but other
     * documents: the published entries of a collection. Special-cased by id for
     * the same reason `form` is — the generic field renderer would print the
     * configuration ("post", "6") as paragraphs, which is not what any of it means.
     */
    private const COLLECTION_LIST = 'collection-list';

    private readonly RichTextSanitizer $sanitizer;

    public function __construct(
        private readonly SectionTypeRepository $sectionTypes,
        private readonly ?MediaService $media = null,
        private readonly string $mediaBaseUrl = '/api/media/file',
        ?RichTextSanitizer $sanitizer = null,
        /**
         * Where a listing section's entries come from. Optional because most
         * renders have no listing on the page and because every existing caller
         * constructs this without one; left out, a listing section renders nothing
         * rather than half of something.
         */
        private readonly ?EntryListings $listings = null,
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

            // Two section types configure something rather than describing it, so
            // neither goes through the generic field renderer: a form's fields are
            // the form's labels, and a listing's fields say which entries to fetch.
            // Printing either as prose is how "post" and "6" end up as paragraphs
            // on a page.
            $html .= match ($section['type']) {
                'form' => $this->renderForm($values, $page),
                self::COLLECTION_LIST => $this->renderCollectionList($values, $page),
                default => $this->renderSection($type, $values),
            };
        }

        return $html;
    }

    /**
     * Render a set of field values against a schema, as one section.
     *
     * This is what gives a collection entry a body: a collection type's field set
     * *is* a {@see SectionType}, so an entry's stored values render through exactly
     * the machinery a section does — the same escaping, the same rich-text
     * sanitising, the same media resolution and responsive images. A second
     * renderer for entries would be a second place for an unescaped value to get
     * out, which is the one thing this class exists to prevent.
     *
     * @param array<string, mixed> $values
     */
    public function renderFields(SectionType $type, array $values): string
    {
        return $this->renderSection($type, $values);
    }

    /**
     * A listing of a collection's published entries.
     *
     * Which entries, in what order and how many is decided by
     * {@see EntryListings}; everything here is markup and escaping. Every value
     * printed is editor input — an entry's title and summary reach this method
     * straight from storage — so all of it goes through {@see escape()}, and the
     * picture through the same {@see imageTag()} the rest of the class uses.
     *
     * An entry links to its own address when its collection declares one, and is
     * plain text when it does not: a listing of team members on a site that never
     * gave team members a public page is a perfectly good listing, and inventing
     * an href for it would be a link to a 404.
     *
     * Nothing at all is rendered when the collection is empty — a heading over an
     * absent list is worse than silence, and it is what an unpopulated Journal
     * page would otherwise show every visitor.
     *
     * @param array<string, mixed> $values
     */
    private function renderCollectionList(array $values, Content $page): string
    {
        // The language actually being served, taken from the document rather than
        // the request: a German URL falling back to the English page must list the
        // English entries it is showing prose in, not German ones.
        $cards = $this->listings?->forSection($values, $page->locale()) ?? [];
        if ($cards === []) {
            return '';
        }

        $items = '';

        foreach ($cards as $card) {
            $title = $this->escape($card['title']);
            $inner = $card['href'] !== null
                ? '<h3 class="cms-entry-title"><a href="' . $this->escape($card['href']) . '">' . $title . '</a></h3>'
                : '<h3 class="cms-entry-title">' . $title . '</h3>';

            if ($card['image'] !== '') {
                // The entry's own title as the fallback description, and only as a
                // fallback: a description written in the media library was written
                // about the picture and wins over the heading beside it.
                $inner = $this->imageTag($card['image'], '', $card['title']) . $inner;
            }

            if ($card['excerpt'] !== '') {
                $inner .= '<p class="cms-entry-excerpt">' . nl2br($this->escape($card['excerpt'])) . '</p>';
            }

            $items .= '<li class="cms-entry">' . $inner . '</li>';
        }

        $heading = isset($values['heading']) && is_scalar($values['heading']) && (string) $values['heading'] !== ''
            ? '<h2 class="cms-field cms-field--heading">' . $this->escape((string) $values['heading']) . '</h2>'
            : '';
        $intro = is_string($values['intro'] ?? null)
            ? $this->renderRichTextValue('cms-field cms-field--intro', $values['intro'])
            : '';

        return '<section class="cms-section cms-section--' . self::COLLECTION_LIST . '">'
            . $heading . $intro
            . '<ul class="cms-entries">' . $items . '</ul>'
            . '</section>';
    }

    /**
     * A contact form section, rendered as a working POST form.
     *
     * The input names are fixed because the forms plugin's submit endpoint reads
     * them by name; only the labels are the editor's. The page and locale ride
     * along hidden so a submission records where it came from. The honeypot is
     * hidden from people but not from bots — one filled in is dropped silently by
     * the endpoint. Every configured label is escaped, since it is editor text
     * going into HTML.
     *
     * @param array<string, mixed> $values
     */
    private function renderForm(array $values, Content $page): string
    {
        $label = fn (string $key, string $default): string => $this->escape(
            is_scalar($values[$key] ?? null) && (string) $values[$key] !== ''
                ? (string) $values[$key]
                : $default
        );

        $heading = isset($values['heading']) && is_scalar($values['heading'])
            ? '<h2 class="cms-field cms-field--heading">' . $this->escape((string) $values['heading']) . '</h2>'
            : '';
        $intro = isset($values['intro']) && is_scalar($values['intro']) && (string) $values['intro'] !== ''
            ? '<p class="cms-field cms-field--intro">' . $this->escape((string) $values['intro']) . '</p>'
            : '';

        $slug = $this->escape($page->slug());
        $locale = $this->escape($page->locale()->code);

        $confirmation = isset($values['confirmation']) && is_scalar($values['confirmation'])
            && (string) $values['confirmation'] !== ''
            ? (string) $values['confirmation']
            : 'Thank you. Your message has been received.';

        return '<section class="cms-section cms-section--form">'
            . $heading . $intro
            // The confirmation the visitor sees after submitting, carried on the
            // form so the enhancement script below can show it without a second
            // round trip. Escaped: it is editor text landing in an attribute.
            . '<form class="cms-form" method="POST" action="/api/forms/submit"'
            . ' data-confirmation="' . $this->escape($confirmation) . '">'
            . '<input type="hidden" name="page" value="' . $slug . '">'
            . '<input type="hidden" name="locale" value="' . $locale . '">'
            // The honeypot: positioned off-screen and hidden from assistive tech,
            // so a person never sees it and a bot that fills every field does.
            . '<div class="cms-form-hp" aria-hidden="true" style="position:absolute;left:-9999px" >'
            . '<label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
            . '</div>'
            . '<p class="cms-form-field"><label for="cf-name">' . $label('nameLabel', 'Your name') . '</label>'
            . '<input id="cf-name" type="text" name="name" required></p>'
            . '<p class="cms-form-field"><label for="cf-email">' . $label('emailLabel', 'Email address') . '</label>'
            . '<input id="cf-email" type="email" name="email" required></p>'
            . '<p class="cms-form-field"><label for="cf-message">' . $label('messageLabel', 'Message') . '</label>'
            . '<textarea id="cf-message" name="message" required></textarea></p>'
            . '<button type="submit">' . $label('submitLabel', 'Send message') . '</button>'
            . '<p class="cms-form-status" role="status" aria-live="polite"></p>'
            . '</form>'
            // Progressive enhancement. Without JavaScript the form posts normally
            // and the endpoint's JSON is shown — it still works. With it, the
            // submit is caught, sent in the background, and the visitor gets the
            // confirmation in place rather than a page of JSON. No inline handler
            // and no framework: one listener bound to this form.
            . '<script>' . self::FORM_ENHANCE_JS . '</script>'
            . '</section>';
    }

    /**
     * The one script that upgrades a contact form's submit into a background
     * request. Kept tiny and dependency-free; the form is fully functional
     * without it.
     */
    private const FORM_ENHANCE_JS = <<<'JS'
        (function () {
          var form = document.currentScript.previousElementSibling;
          if (!form || form.tagName !== 'FORM') { return; }
          var status = form.querySelector('.cms-form-status');
          form.addEventListener('submit', function (e) {
            e.preventDefault();
            var button = form.querySelector('button[type=submit]');
            if (button) { button.disabled = true; }
            if (status) { status.textContent = 'Sending…'; }
            fetch(form.action, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(Object.fromEntries(new FormData(form)))
            }).then(function (r) { return r.json().catch(function () { return {}; }); })
              .then(function (body) {
                if (body && body.success) {
                  form.innerHTML = '<p class="cms-form-thanks">' +
                    (form.getAttribute('data-confirmation') || 'Thank you.') + '</p>';
                } else {
                  if (status) { status.textContent = (body && body.error) || 'Something went wrong. Please try again.'; }
                  if (button) { button.disabled = false; }
                }
              }).catch(function () {
                if (status) { status.textContent = 'Could not send. Please try again.'; }
                if (button) { button.disabled = false; }
              });
          });
        })();
        JS;

    /**
     * @param array<string, mixed> $values
     */
    private function renderSection(SectionType $type, array $values): string
    {
        $body = '';
        $modifiers = '';

        // Fields consumed as another field's wording must not also be printed on
        // their own, or the page shows it twice — a link's text beside its raw
        // address, or an image's description as a paragraph under the picture it
        // exists to stand in for.
        $consumed = [];
        foreach ($type->fields as $field) {
            if ($field->labelField !== null) {
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

            $wording = null;
            if ($field->labelField !== null) {
                $candidate = $values[$field->labelField] ?? null;
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    $wording = (string) $candidate;
                }
            }

            $body .= $this->renderField($field, $values[$field->name], $wording);
        }

        if (trim($body) === '') {
            return '';
        }

        $class = 'cms-section cms-section--' . $this->escape($type->id) . $modifiers;

        return '<section class="' . $class . '">' . $body . '</section>';
    }

    /**
     * @param ?string $wording the value of this field's declared `labelField`,
     *                         which is a link's text on a Url and a picture's
     *                         description on an Image
     */
    private function renderField(FieldDefinition $field, mixed $value, ?string $wording = null): string
    {
        return match ($field->type) {
            FieldType::Repeater => $this->renderRepeater($field, $value),
            FieldType::Image => $this->renderImage($field, $value, $wording ?? ''),
            FieldType::RichText => $this->renderRichText($field, $value),
            FieldType::Textarea => $this->renderProse($field, $value),
            FieldType::Boolean => '',
            // A reference is a pointer, not prose. Its stored value is the target's
            // slug, so printing it puts `jun-park` on the page where a reader
            // expects a name — visible the moment collection entries gained a
            // public address, since a post's author is a reference. Showing the
            // target properly means reading it, deciding whether it has an address
            // of its own and how deep to follow the chain; until this renderer is
            // given that, saying nothing is the honest output.
            FieldType::Reference => '',
            default => $this->renderScalar($field, $value, $wording),
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
        return is_string($value)
            ? $this->renderRichTextValue($this->fieldClass($field), $value)
            : '';
    }

    /**
     * The one place rich text becomes markup, so the sanitiser cannot be skipped
     * by a caller that has a class name but no {@see FieldDefinition} to hand.
     */
    private function renderRichTextValue(string $class, string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $safe = $this->sanitizer->sanitize($value);
        if (trim($safe) === '') {
            return '';
        }

        return '<div class="' . $class . '">' . $safe . '</div>';
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

    private function renderImage(FieldDefinition $field, mixed $value, string $alt = ''): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return '<div class="' . $this->fieldClass($field) . '">' . $this->imageTag($value, $alt) . '</div>';
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
