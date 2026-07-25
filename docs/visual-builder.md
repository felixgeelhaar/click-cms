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

See `schemas/visual-builder.schema.json` for full validation. Nothing enforces it at runtime —
the renderer in `plugins/visual-builder/bootstrap.php` is the authority on what a node does, and
its `type` enum currently lags the list below.

## Node types

| Type | Element | Holds children | Key props |
| --- | --- | --- | --- |
| `section` | `<section>` | yes | — |
| `grid` | `<div>` | yes | `columns` |
| `columns` | `<div>` | yes (columns only) | `count`, `stackAt` |
| `column` | `<div>` | yes | — |
| `text` | `<p>` | no | `text` |
| `image` | `<img>` | no | `src`, `alt` |
| `video` | `<video>` | no | `src`, `poster`, `captions`, `label`, `controls`, `autoplay`, `preload` |
| `embed` | `<iframe>` | no | `url`, `title`, `height` |
| `list` | `<ul>` / `<ol>` | no | `items[]`, `ordered` |
| `quote` | `<figure>` | no | `text`, `attribution`, `source`, `cite` |
| `button` | `<a>` | no | `label`, `href` |
| `divider` | `<hr>` | no | `lineStyle`, `thickness`, `color` |
| `spacer` | `<div>` | no | — |
| `chart` | inline `<svg>` | no | `chartType`, `title`, `color`, `width`, `height`, `data[]` |

A type the renderer does not recognise is skipped: the node produces no output and the rest of
the page still publishes.

### Columns and stacking

A `columns` node holds `column` nodes, each with its own children. It is rendered mobile-first:
the base rule is a single stacked column, and the renderer *generates* the media query that puts
the columns side by side at `props.stackAt` (a breakpoint id, default `sm`). Authors never have
to hand-write a responsive override to get a layout that survives a phone. If the document
declares no breakpoint under that id, the query falls back to `min-width: 640px`.

An explicit `responsive.<breakpoint>.styles.gridTemplateColumns` on the node wins over the
generated rule.

### Video

Defaults are `preload="none"`, `playsinline`, and controls on. `autoplay` forces `muted` and
`loop`, because browsers refuse to start an audible video unprompted. `captions` (a `.vtt` URL)
becomes a default `<track kind="captions">`.

### Embeds are an allowlist, not an HTML field

There is no "paste your embed code" field, by design: author HTML in a page is stored XSS.
An author supplies a **URL**; the renderer matches its host against a fixed provider list,
extracts an id under a strict charset, and constructs the iframe itself.

| Provider | Accepted URLs | Emitted frame |
| --- | --- | --- |
| YouTube | `youtube.com/watch?v=`, `youtu.be/`, `/embed/`, `/shorts/`, `/live/`, `m.youtube.com`, `youtube-nocookie.com` | `https://www.youtube-nocookie.com/embed/{id}` |
| Vimeo | `vimeo.com/{id}`, `player.vimeo.com/video/{id}` | `https://player.vimeo.com/video/{id}` |
| OpenStreetMap | `openstreetmap.org` with a `bbox=` query or a `#map=zoom/lat/lon` fragment | `.../export/embed.html?bbox=…` |
| Google Maps | `google.com/maps/embed?pb=…` (the URL its own Embed dialog produces) | the same URL, re-emitted |

Every frame gets `loading="lazy"`, a `title`, a `sandbox` and a `referrerpolicy`. Only the `www.`
prefix is folded away when matching a host, so `evil.youtube.com` and `youtube.com.attacker.test`
do not match. A URL from any other host is published as a plain link; a `javascript:`, `data:`,
relative or otherwise unusable URL renders nothing at all.

## Chart data format

Chart nodes expect `props.data` as an array of `{ label, value }` pairs. Supported types are
`bar` and `line`, configured by `props.chartType`.

## Escaping

Every value the renderer emits passes through `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`, or is
reconstructed from an allowlist. URL props (`href`, `src`, `poster`, `cite`, embed `url`) are
additionally scheme-checked, with the check run against a copy stripped of whitespace and control
characters so `java&#9;script:` cannot slip past. Style values have `;` and braces removed so a
value cannot append declarations of its own.

## Rendering pipeline (planned)

1. Detect `data.builder` in page content.
2. Render `builder.nodes` into HTML for public pages.
3. If `builder` is missing, fall back to the `content` string.

This preserves backward compatibility with existing pages.
