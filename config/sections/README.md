# Section types

Each JSON file here declares one section type an editor can add to a page.
The filename is the section's id unless the file sets `"id"` explicitly.

These are **examples**. They are not part of Click CMS and nothing depends on
them — delete the ones you do not want and add your own. A site's section types
should match the layouts its templates actually implement.

## Format

```json
{
  "label": "Feature Grid",
  "description": "Shown to the editor when choosing a section",
  "icon": "grid",
  "fields": [
    { "name": "heading", "type": "text", "label": "Heading", "required": true },
    { "name": "body",    "type": "richtext", "label": "Body" },
    { "name": "items",   "type": "repeater", "label": "Items", "min": 1, "max": 6,
      "fields": [
        { "name": "title", "type": "text",  "required": true },
        { "name": "image", "type": "image" }
      ]
    }
  ]
}
```

## Field types

`text`, `textarea`, `richtext`, `number`, `boolean`, `select`, `image`, `file`,
`url`, `email`, `date`, `repeater`.

Common options: `label`, `required`, `help`, `default`, `min`, `max`.
`select` requires `options`; `repeater` requires `fields`.

Repeaters may not contain repeaters — one level of nesting is the limit, because
deeper nesting produces an editing experience non-technical editors cannot
follow.

Anything an editor submits that is not declared here is discarded, so a section
can only ever hold the shape its type describes.

## How each type renders

A design is only as good as what `SectionRenderer` does with it, and a field
behaves differently depending on where it sits. Composing a new design means
working to these rules rather than to the field list above.

| Declared as | Rendered as |
| --- | --- |
| `text` named `heading` or `title` | `<h2>` |
| any other `text`, `number`, `date` | `<p>` |
| `textarea` | `<div>` of `<p>`, blank lines splitting paragraphs and single newlines becoming `<br>` |
| `richtext` | `<div>` of the editor's own markup, sanitised to an allowlist |
| `url` | `<p><a href>` — its wording comes from `labelField`; failing that, from the row's `title` inside a repeater, and only then from the address itself |
| `email` | `<p><a href="mailto:">` |
| `image` | `<div><img loading="lazy">`, with a `srcset` of the variants that exist |
| `select` | **nothing printed** — a `cms-section--{field}-{value}` class on the section |
| `boolean` | **nothing at all** |
| `repeater` | `<ul class="cms-items">` of `<li class="cms-item">`, each holding its sub-fields |

Four consequences worth knowing before you write a design:

- **A `select` is for presentation, never for content.** Its value never reaches
  the page as words, only as a class. `"columns": ["2", "3", "4"]` works because
  the default theme implements `.cms-section--columns-N .cms-items`; an option
  the theme has no rule for is a setting that silently does nothing.
- **A `url` needs a `labelField`** or the page shows a reader
  `https://example.com/contact` as a sentence. The named field is then consumed:
  it is not printed on its own.
- **An `image` needs a `labelField` too**, pointing at a text field that
  describes the picture. Without it that description is printed as a paragraph
  under the picture it was written to replace, which is both wrong and worse than
  nothing. It also needs `displayWidth`, the width the section actually shows the
  image at, so the media library's "this file is too small" warning is arithmetic
  rather than a guess.
- **Inside a repeater, `labelField` does not apply.** A sub-field's description
  is taken from the media library, falling back to a sibling field literally
  named `title`. So do not put a separate description field in a repeater row —
  it would print — and do not pair a label field with a `url` there either, since
  the address would still be its own wording. Give the row a `title` and describe
  pictures in the media library.

## What ships here

| Id | For |
| --- | --- |
| `section-heading` | A heading and lead-in, to break a long page into parts |
| `rich-text` | A heading and a block of formatted text |
| `media-text` | An image beside a block of text |
| `card-grid` | A repeating grid of cards |
| `facts` | A row of figures with captions |
| `gallery` | A grid of pictures with captions |
| `people` | Names, roles, photographs and short biographies |
| `logos` | Partner, association or certification marks |
| `quote` | Something a customer said, with their name and role |
| `faq` | Questions with their answers |
| `pricing` | Prices and plans, side by side |
| `details` | Label and value pairs — opening hours, contact details |
| `call-to-action` | A short prompt with a button |
| `collection-list` | The published entries of a collection |
| `form` | A contact form a visitor can fill in |

`form` and `collection-list` are special-cased by id in the renderer: their
fields configure something rather than describe it, so renaming their files
breaks them. Every other design here is ordinary configuration.

## Declaring what a field *is*: `as`

By default the renderer infers the markup from a field's name and type — a field
called `heading` or `title` becomes an `<h2>`, every other scalar a `<p>`, every
repeater a `<ul>`. That is a good default and it used to be the ceiling: a
testimonial could not be a `<blockquote>`, opening hours could not be a `<dl>`,
and no page could have a heading below level two.

A field may now declare a **role**. It is a closed vocabulary of intent, not an
HTML element name — a design says `"as": "quote"` and the renderer decides that
means a `<blockquote>`. Every guarantee about escaping and structure stays inside
the renderer, where it is tested once, instead of being spread across every
design anyone writes.

| Field type | Role | What it renders |
|---|---|---|
| `text` | `heading` | `<h2>` — the default for a field named `heading` or `title` |
| `text` | `subheading` | `<h3>`, for a part within a page |
| `text`, `textarea`, `richtext` | `quote` | `<blockquote>` |
| `text`, `textarea`, `richtext` | `note` | An aside, styled quieter |
| `repeater` | `ordered` | `<ol>`, when the order *is* the meaning |
| `repeater` | `definitions` | `<dl>` — the first sub-field in a row is the `<dt>`, the rest are `<dd>` |

An unrecognised role, or one that makes no sense for the field's type
(`definitions` on a text field, `quote` on a repeater), is **dropped** rather
than honoured — rendering a guess would be worse than rendering the default.
Declaring nothing renders exactly as it did before this existed.

A row in a `definitions` repeater needs both a term and at least one value; a
half-filled row is skipped, the same way an empty list item already is.
