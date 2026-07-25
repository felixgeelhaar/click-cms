# @click-cms/client

A typed TypeScript client for the click-cms **delivery API** — the public,
read-only API a headless front end consumes.

The types are not hand-written. They are generated from *your* site's
`config/sections/*.json` and `config/collections/*.json`, so the shape your front
end compiles against is the shape your editors are actually filling in. Add a
required field to a section and your front end stops compiling until it renders
it; rename a select option and every `switch` over it lights up.

Zero runtime dependencies. Works in a browser and in Node 18+ on global `fetch`.

---

## Install

```sh
npm install @click-cms/client
```

Or, while it lives in the CMS repository, point at it directly:

```sh
npm install file:../click-cms/sdk/typescript
```

## Generate types for your site

The generator is a single PHP file with no Composer dependencies. Run it against
the CMS checkout your front end reads from:

```sh
php scripts/generate-ts-client.php --config=config --out=sdk/typescript/src
```

| Flag | Default | What it is |
| --- | --- | --- |
| `--config` | `config` | The directory holding `sections/` and `collections/` |
| `--out` | `sdk/typescript/src` | Where `types.ts` is written |

It writes exactly one file, `types.ts`: types only, no imports, nothing left
after compilation. **Do not edit it** — regenerate instead. Re-running on an
unchanged site produces a byte-identical file, so it is safe to commit and it
will not appear in unrelated diffs.

Run it whenever a schema changes. In `sdk/typescript` there is a shortcut:

```sh
npm run generate && npm run build
```

## Fetch a page

```ts
import { createClient } from '@click-cms/client';

const client = createClient({
  baseUrl: 'https://cms.example.com', // '' when a proxy puts the API on this origin
  locale: 'en',                       // optional; any call can override it
});

const page = await client.getPage('about');

if (page === null) {
  // A missing or unpublished page is a normal outcome, not an error — render
  // your own 404. Only a transport failure or a 5xx throws (a `ClickCmsError`).
  return notFound();
}

page.data.data.title;      // the page's title
page.data.data.sections;   // Section[]
page.locale;               // the language actually served
page.fallback;             // true when the translation you asked for was missing
```

## Render sections with the discriminated union

Every section type in your `config/sections/` becomes a member of `Section`,
discriminated on `type`. Inside a `case`, `section.values` narrows to exactly
that design's fields — required ones non-optional, optional ones `| undefined`,
selects narrowed to their declared options.

```ts
import type { MediaMap, Section } from '@click-cms/client';

function renderSection(section: Section, media: MediaMap | undefined): string {
  switch (section.type) {
    case 'rich-text':
      // `body` is required in the schema, so it is a plain string here.
      // `width` is 'narrow' | 'wide' | 'full' | undefined.
      return `<div class="${section.values.width ?? 'narrow'}">${section.values.body}</div>`;

    case 'card-grid':
      // A repeater is an array of a generated interface.
      return (section.values.cards ?? [])
        .map((card) => `<article><h3>${card.title}</h3>${card.body ?? ''}</article>`)
        .join('');

    case 'media-text':
      return `<img src="${client.mediaUrl(media, section.values.image) ?? ''}" />`;

    default: {
      // Exhaustiveness: when the CMS gains a section design, this line fails to
      // compile until the front end handles it. That is the whole point.
      const unhandled: never = section;
      return String(unhandled);
    }
  }
}
```

## Collections

```ts
// `type` is checked against the collections your site declares, and the entry
// data is typed by that collection's schema.
const posts = await client.listEntries('post', {
  limit: 10,
  offset: 0,
  filter: { status: 'featured' },  // ?filter[status]=featured
});

posts?.meta.total;                  // matches before paging, for building a pager
posts?.data[0]?.data.title;         // typed by post.json

const post = await client.getEntry('post', 'hello-world');
post?.data.body;
post?.references?.author;           // reference fields resolved to titles
```

`filter` is an exact match on a top-level field, or membership when that field
holds a list (a tag among tags). `limit` is capped server-side; asking for more
returns the cap rather than an error.

## Images and srcset

Sections store a media *id*, not a URL. A page response carries a `media` map
with every referenced image already resolved — its renditions, its `srcset`, its
dimensions and its alt text — so you never have to guess a variant filename.

```ts
const image = section.values.image;          // a MediaRef (a string id)

const src = client.mediaUrl(page.media, image, { width: 640 });
// The narrowest rendition at least 640px wide, falling back to the original.

const srcset = client.srcset(page.media, image);
// "…/thumb.jpg 320w, …/small.jpg 640w, …" — or null when there are no
// renditions (an SVG, or an upload too narrow for the smallest rung).

const alt = client.media(page.media, image)?.alt ?? '';
```

```html
<img
  :src="src"
  :srcset="srcset ?? undefined"
  sizes="(max-width: 700px) 100vw, 640px"
  :alt="alt"
/>
```

Named crops work the same way: `client.mediaUrl(page.media, image, { crop: 'square' })`,
falling back to the ordinary renditions when that crop was never generated.

## Errors

```ts
import { ClickCmsError } from '@click-cms/client';

try {
  const pages = await client.listPages({ limit: 20 });
} catch (error) {
  if (error instanceof ClickCmsError) {
    error.status;  // the HTTP status, or undefined if nothing answered at all
    error.url;
  }
}
```

The rule: **404 returns `null`, everything else throws.** A page that is not
published is a normal thing for a front end to encounter and deserves an empty
state; a CMS that is unreachable or answering 500 deserves an alert. Keeping
those apart is what makes `try`/`catch` around a delivery call mean something.

## What is generated

| Your schema | The type you get |
| --- | --- |
| `config/sections/card-grid.json` | `CardGridSection` — `{ type: 'card-grid'; values: { … } }`, a member of `Section` |
| a `repeater` field | `CardGridCardsItem[]`, with the nested interface emitted beside it |
| a `select` with `options` | a string-literal union of exactly those options |
| an `image` field | `MediaRef` (a media id — resolve it against the `media` map) |
| a `reference` field | `string`, or `string[]` when `multiple` |
| a field without `required: true` | optional (`field?: …`) |
| `config/collections/post.json` | `PostFields`, `PostEntry`, and an entry in `CollectionData` |
| a field type the generator does not know | `unknown`, rather than a plausible guess that fails at runtime |

Plus the response shapes that do not depend on your schemas: `Page`,
`PageResponse`, `PageListResponse`, `CollectionEntry`, `CollectionResponse`,
`Media`, `MediaMap` and `DeliveryMeta`.

## Development

```sh
npm install     # typescript, dev-only — there are no runtime dependencies
npm run generate
npm run typecheck
npm run build
```

The generator has its own PHPUnit tests in the CMS repository under
`tests/Unit/Sdk/`, including one that compiles the generated types together with
this client to make sure the emitted TypeScript is not merely well-worded but
actually valid.
