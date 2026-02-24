# Visual Builder

## Data model

Visual Builder data lives alongside page content as a structured object. It does not replace
the `content` string; the builder output can be rendered to HTML and stored separately later.

Recommended page data shape:

```json
{
  "title": "Landing Page",
  "status": "draft",
  "content": "",
  "builder": {
    "version": "1.0",
    "root": "node-1",
    "breakpoints": [
      { "id": "base", "label": "Base", "minWidth": 0 },
      { "id": "sm", "label": "Small", "minWidth": 640 },
      { "id": "lg", "label": "Large", "minWidth": 1024 }
    ],
    "nodes": {
      "node-1": {
        "id": "node-1",
        "type": "section",
        "children": ["node-2", "node-3"],
        "styles": { "padding": "48px" }
      },
      "node-2": {
        "id": "node-2",
        "type": "text",
        "props": { "text": "Hello world" },
        "responsive": {
          "sm": { "styles": { "textAlign": "center" } }
        }
      },
      "node-3": {
        "id": "node-3",
        "type": "chart",
        "props": {
          "chartType": "bar",
          "title": "Quarterly revenue",
          "color": "#0ea5a4",
          "width": 640,
          "height": 280,
          "data": [
            { "label": "Q1", "value": 18 },
            { "label": "Q2", "value": 24 },
            { "label": "Q3", "value": 16 },
            { "label": "Q4", "value": 30 }
          ]
        }
      }
    }
  }
}
```

## Schema

See `schemas/visual-builder.schema.json` for full validation.

## Chart data format

Chart nodes expect `props.data` as an array of `{ label, value }` pairs. Supported types are
`bar` and `line`, configured by `props.chartType`.

## Rendering pipeline (planned)

1. Detect `data.builder` in page content.
2. Render `builder.nodes` into HTML for public pages.
3. If `builder` is missing, fall back to the `content` string.

This preserves backward compatibility with existing pages.
