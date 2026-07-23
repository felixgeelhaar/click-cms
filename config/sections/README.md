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
