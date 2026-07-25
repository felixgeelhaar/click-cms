/* eslint-disable */
// ---------------------------------------------------------------------------
// GENERATED FILE — do not edit.
//
// Produced by `php scripts/generate-ts-client.php` from this site's config/sections/*.json and
// config/collections/*.json. Any edit here is lost the next time it runs; to
// change a type, change the schema it was generated from.
//
// Types only: no imports, no runtime code, nothing left after compilation.
// ---------------------------------------------------------------------------


/** A media id. Resolve it against the `media` map of the response it came in. */
export type MediaRef = string;

/** One rendition of an image: a URL and the box it was cut to. */
export interface MediaVariant {
  url: string;
  width: number;
  height?: number;
}

/**
 * A resolved image, as the delivery API returns it in a page's `media` map.
 * `variants` is keyed by rung name (thumb, small, …); which rungs exist depends
 * on the upload, because a narrow original is never scaled up.
 */
export interface Media {
  urls: {
    original: string;
    variants: Record<string, MediaVariant>;
    square?: MediaVariant;
    crops?: Record<string, MediaVariant>;
  };
  /** Ready to drop into an <img srcset>, widest last. */
  srcset: string;
  width: number | null;
  height: number | null;
  alt: string | null;
}

/** Media id -> resolved image, for every image a page references. */
export type MediaMap = Record<string, Media>;

/** What `?limit`, `?offset` and `?filter[field]=value` produced. */
export interface DeliveryMeta {
  /** Matches after filtering, before paging — the number to build a pager from. */
  total: number;
  /** Items in this response. */
  count: number;
  limit: number | null;
  offset: number;
}

/** A page's editorial content: its sections plus the SEO an editor set. */
export interface PageData {
  title?: string;
  sections: Section[];
  seo?: PageSeo;
}

export interface PageSeo {
  metaTitle?: string;
  description?: string;
  ogImage?: MediaRef;
  canonicalUrl?: string;
  noindex?: boolean;
}

export interface Page {
  /** The composite content key, e.g. `page:en:home`. */
  key: string;
  type: string;
  slug: string;
  locale: string;
  data: PageData;
  createdAt: string;
  updatedAt: string;
}

/**
 * One page, plus every image it references already resolved — so a front end
 * never has to turn a media id into a srcset by guessing variant names.
 */
export interface PageResponse {
  data: Page;
  media?: MediaMap;
  /** The language actually served, which is not always the one asked for. */
  locale: string;
  requestedLocale?: string;
  /** True when the requested translation was missing and another was served. */
  fallback?: boolean;
  availableLocales?: string[];
}

export interface PageListResponse {
  data: Page[];
  meta: DeliveryMeta;
  locale: string;
  locales: string[];
}

/** A resolved reference: enough to render a link without a second request. */
export interface ReferenceDescriptor {
  type: string;
  slug: string;
  title: string;
  /** False when the target is gone or not published — render a dead link as text. */
  exists: boolean;
}

/**
 * One collection entry. `data` holds exactly the fields the collection type
 * declares; `references` carries reference fields resolved to titles, while
 * `data` keeps the bare slugs the entry was saved with.
 */
export interface CollectionEntry<TData = Record<string, unknown>> {
  slug: string;
  locale: string;
  title: string;
  data: TData;
  updatedAt: string;
  references?: Record<string, ReferenceDescriptor | ReferenceDescriptor[]>;
}

export interface CollectionResponse<TData = Record<string, unknown>> {
  data: Array<CollectionEntry<TData>>;
  meta: DeliveryMeta;
}

export interface CollectionEntryResponse<TData = Record<string, unknown>> {
  data: CollectionEntry<TData>;
}


/* ---------------------------------------------------------------- sections -- */

export interface CardGridCardsItem {
  /** Title */
  title: string;
  /** Text */
  body?: string;
  /** Image */
  image?: MediaRef;
  /** Link */
  link?: string;
}

export interface FactsItemsItem {
  /**
   * Figure
   * For example 2013, 50+, ISO 9001.
   */
  value: string;
  /** Caption */
  caption: string;
}
/**
 * Call to action
 * A short prompt with a button.
 */
export interface CallToActionSection {
  type: 'call-to-action';
  values: {
    /** Heading */
    heading: string;
    /** Text */
    body?: string;
    /** Button label */
    buttonLabel: string;
    /** Button link */
    buttonUrl: string;
  };
}

/**
 * Card grid
 * A repeating grid of cards, each with a title, text and optional image.
 */
export interface CardGridSection {
  type: 'card-grid';
  values: {
    /** Heading */
    heading?: string;
    /** Introduction */
    intro?: string;
    /** Columns */
    columns?: '2' | '3' | '4';
    /** Cards */
    cards?: CardGridCardsItem[];
  };
}

/**
 * Facts
 * A row of figures with captions.
 */
export interface FactsSection {
  type: 'facts';
  values: {
    /** Heading */
    heading?: string;
    /** Figures */
    items?: FactsItemsItem[];
  };
}

/**
 * Contact form
 * A form a visitor can fill in. Submissions are stored and read under Form submissions.
 */
export interface FormSection {
  type: 'form';
  values: {
    /** Heading */
    heading: string;
    /**
     * Intro text
     * Shown above the form.
     */
    intro?: string;
    /** Name field label */
    nameLabel: string;
    /** Email field label */
    emailLabel: string;
    /** Message field label */
    messageLabel: string;
    /** Submit button label */
    submitLabel: string;
    /**
     * Confirmation message
     * Shown to the visitor after a successful submission.
     */
    confirmation?: string;
    /**
     * Destination note
     * A private note for editors on where these submissions go. Not shown to visitors.
     */
    destinationNote?: string;
  };
}

/**
 * Media and text
 * An image beside a block of text.
 */
export interface MediaTextSection {
  type: 'media-text';
  values: {
    /** Heading */
    heading?: string;
    /** Body */
    body?: string;
    /** Image */
    image: MediaRef;
    /**
     * Image description
     * Describes the image for screen readers and when it fails to load.
     */
    alt?: string;
    /** Image position */
    imagePosition?: 'left' | 'right';
  };
}

/**
 * Rich text
 * A heading and a block of formatted text.
 */
export interface RichTextSection {
  type: 'rich-text';
  values: {
    /** Heading */
    heading?: string;
    /** Body */
    body: string;
    /** Width */
    width?: 'narrow' | 'wide' | 'full';
  };
}

/**
 * Every section a page can hold, discriminated on `type` — so a
 * `switch (section.type)` narrows `section.values` to that design's fields.
 */
export type Section =
  | CallToActionSection
  | CardGridSection
  | FactsSection
  | FormSection
  | MediaTextSection
  | RichTextSection;

/** The id of every section design this site declares. */
export type SectionTypeId =
  | 'call-to-action'
  | 'card-grid'
  | 'facts'
  | 'form'
  | 'media-text'
  | 'rich-text';


/* ------------------------------------------------------------- collections -- */


/**
 * Blog posts
 * Articles and news, each published on its own.
 */
export interface PostFields {
  /** Title */
  title: string;
  /** Author */
  author?: string;
  /** Date */
  date?: string;
  /**
   * Excerpt
   * A short summary shown in listings.
   */
  excerpt?: string;
  /** Cover image */
  coverImage?: MediaRef;
  /** Body */
  body: string;
  /** Related posts */
  relatedPosts?: string[];
}

/**
 * Team members
 * People to show on an About or Team page.
 */
export interface TeamMemberFields {
  /** Name */
  name: string;
  /** Role */
  role?: string;
  /** Photo */
  photo?: MediaRef;
  /** Bio */
  bio?: string;
}

export type PostEntry = CollectionEntry<PostFields>;
export type TeamMemberEntry = CollectionEntry<TeamMemberFields>;

/** Collection id -> the shape of one entry's `data`, for typing a call by name. */
export interface CollectionData {
  post: PostFields;
  'team-member': TeamMemberFields;
}

export type CollectionName = Extract<keyof CollectionData, string>;

