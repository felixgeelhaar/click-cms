// The runtime half of the SDK. Hand-written and deliberately small: everything
// specific to a site lives in the generated `types.ts` beside this file, so this
// module never changes when a schema does.
//
// No dependencies, and nothing beyond what a browser and Node 18+ both have —
// global `fetch`, `URL` and `URLSearchParams`. A CMS client that drags in a HTTP
// library is a client nobody wants in their bundle.

import type {
  CollectionData,
  CollectionEntry,
  CollectionName,
  CollectionResponse,
  Media,
  MediaMap,
  MediaRef,
  PageListResponse,
  PageResponse,
} from './types.js';

/** A value a filter can be compared against. Arrays are not a shallow match. */
export type FilterValue = string | number | boolean;

export interface ClientOptions {
  /**
   * Where the CMS is, e.g. `https://cms.example.com` or `''` when a proxy puts
   * the API on the same origin as the front end.
   */
  baseUrl: string;
  /** The language to request unless a call overrides it. */
  locale?: string;
  /** Sent with every request — an edge cache key, a preview token, a tracing id. */
  headers?: Record<string, string>;
  /**
   * The fetch to use. Injected only so a test or a server framework can supply
   * its own; left out, the global one is used at call time rather than captured,
   * so a polyfill installed after the client was created still applies.
   */
  fetch?: typeof globalThis.fetch;
}

export interface RequestOptions {
  /** Overrides the client's locale for this call. */
  locale?: string;
  signal?: AbortSignal;
}

export interface ListOptions extends RequestOptions {
  /** The server caps this; asking for more returns the cap, not an error. */
  limit?: number;
  offset?: number;
  /**
   * Exact match on a top-level field, or membership when the field holds a list
   * (a tag among tags). `{ status: 'featured' }` becomes `?filter[status]=featured`.
   */
  filter?: Record<string, FilterValue>;
}

/** Which rendition {@link ClickCmsClient.mediaUrl} should hand back. */
export interface MediaUrlOptions {
  /**
   * The width the image is displayed at. The narrowest rendition at least this
   * wide wins — asking for a 400px slot must not download the 2000px original.
   */
  width?: number;
  /** A named rendition (`square`, or a site's own art-directed crop). */
  crop?: string;
}

/**
 * A delivery request that did not produce content.
 *
 * Distinct from "not found", which is not an error: a front end asking for a
 * page an editor has not published yet gets `null` and renders its own 404,
 * while a CMS that is down, unreachable or answering with a 500 throws — those
 * need a retry or an alert, not an empty state.
 */
export class ClickCmsError extends Error {
  /** The HTTP status, absent when the request never got a response at all. */
  readonly status?: number;
  readonly url: string;

  constructor(message: string, url: string, status?: number, options?: { cause?: unknown }) {
    super(message, options as ErrorOptions);
    this.name = 'ClickCmsError';
    this.url = url;
    this.status = status;
  }
}

export interface ClickCmsClient {
  /** One published page and the images it references. `null` when there is none. */
  getPage(slug: string, options?: RequestOptions): Promise<PageResponse | null>;

  /** The published pages, paged and filtered. */
  listPages(options?: ListOptions): Promise<PageListResponse | null>;

  /** The published entries of one collection, typed by the collection's schema. */
  listEntries<K extends CollectionName>(
    type: K,
    options?: ListOptions,
  ): Promise<CollectionResponse<CollectionData[K]> | null>;

  /** One published entry. `null` when it is missing, a draft, or taken down. */
  getEntry<K extends CollectionName>(
    type: K,
    slug: string,
    options?: RequestOptions,
  ): Promise<CollectionEntry<CollectionData[K]> | null>;

  /** The resolved image behind a media reference, or `null` if it is not in the map. */
  media(map: MediaMap | undefined, id: MediaRef | undefined | null): Media | null;

  /** A URL for a media reference, picking the smallest rendition that will do. */
  mediaUrl(
    map: MediaMap | undefined,
    id: MediaRef | undefined | null,
    options?: MediaUrlOptions,
  ): string | null;

  /** The `srcset` for a media reference, ready for an `<img>`. */
  srcset(map: MediaMap | undefined, id: MediaRef | undefined | null): string | null;
}

export function createClient(options: ClientOptions): ClickCmsClient {
  const base = options.baseUrl.replace(/\/+$/, '');

  async function get<T>(path: string, query: URLSearchParams, signal?: AbortSignal): Promise<T | null> {
    const search = query.toString();
    const url = `${base}${path}${search === '' ? '' : `?${search}`}`;
    const doFetch = options.fetch ?? globalThis.fetch;

    if (typeof doFetch !== 'function') {
      throw new ClickCmsError(
        'No fetch implementation is available. Use Node 18+, a browser, or pass one as `fetch`.',
        url,
      );
    }

    let response: Response;
    try {
      response = await doFetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json', ...(options.headers ?? {}) },
        signal,
      });
    } catch (cause) {
      // An abort was asked for, so it is not a failure to report as one — a
      // caller cancelling a stale request wants their own AbortError back.
      if (cause instanceof Error && cause.name === 'AbortError') {
        throw cause;
      }
      throw new ClickCmsError(`Could not reach the CMS at ${url}.`, url, undefined, { cause });
    }

    // Not found is an ordinary answer for a front end: the page was never
    // written, or was taken down. Callers branch on null; only real failures
    // deserve a throw, which is what keeps `try`/`catch` meaningful.
    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new ClickCmsError(
        `The CMS answered ${response.status} for ${url}.`,
        url,
        response.status,
      );
    }

    try {
      return (await response.json()) as T;
    } catch (cause) {
      throw new ClickCmsError(
        `The CMS answered ${url} with something that is not JSON.`,
        url,
        response.status,
        { cause },
      );
    }
  }

  function query(request?: ListOptions): URLSearchParams {
    const params = new URLSearchParams();

    const locale = request?.locale ?? options.locale;
    if (locale !== undefined && locale !== '') {
      params.set('locale', locale);
    }

    if (request?.limit !== undefined) {
      params.set('limit', String(request.limit));
    }
    if (request?.offset !== undefined) {
      params.set('offset', String(request.offset));
    }

    for (const [field, value] of Object.entries(request?.filter ?? {})) {
      // The bracket form the delivery API parses; URLSearchParams escapes the
      // brackets, which PHP decodes back before it reads the query.
      params.set(`filter[${field}]`, String(value));
    }

    return params;
  }

  function resolve(map: MediaMap | undefined, id: MediaRef | undefined | null): Media | null {
    if (map === undefined || id === undefined || id === null || id === '') {
      return null;
    }

    return map[id] ?? null;
  }

  return {
    getPage(slug, request) {
      return get<PageResponse>(`/api/pages/${encodeURIComponent(slug)}`, query(request), request?.signal);
    },

    listPages(request) {
      return get<PageListResponse>('/api/pages', query(request), request?.signal);
    },

    listEntries<K extends CollectionName>(type: K, request?: ListOptions) {
      return get<CollectionResponse<CollectionData[K]>>(
        `/api/collections/${encodeURIComponent(type)}/published`,
        query(request),
        request?.signal,
      );
    },

    async getEntry<K extends CollectionName>(type: K, slug: string, request?: RequestOptions) {
      const body = await get<{ data: CollectionEntry<CollectionData[K]> }>(
        `/api/collections/${encodeURIComponent(type)}/published/${encodeURIComponent(slug)}`,
        query(request),
        request?.signal,
      );

      // Unwrapped, because the envelope carries nothing else on this endpoint
      // and making every caller reach through `.data` is friction with no payoff.
      return body?.data ?? null;
    },

    media: resolve,

    mediaUrl(map, id, request) {
      const item = resolve(map, id);
      if (item === null) {
        return null;
      }

      if (request?.crop !== undefined) {
        const named = request.crop === 'square' ? item.urls.square : item.urls.crops?.[request.crop];
        // A crop that was never generated falls through to the ordinary
        // renditions rather than returning nothing: a differently-framed image
        // beats a broken one.
        if (named !== undefined) {
          return named.url;
        }
      }

      const wanted = request?.width;
      if (wanted !== undefined && wanted > 0) {
        const fits = Object.values(item.urls.variants)
          .filter((variant) => variant.width >= wanted)
          .sort((a, b) => a.width - b.width);

        if (fits.length > 0) {
          return fits[0]!.url;
        }
        // Every rendition is narrower than the slot, so the original is the
        // best available — it is what the narrow renditions were cut from.
      }

      return item.urls.original;
    },

    srcset(map, id) {
      const item = resolve(map, id);

      // Empty means the upload produced no scaled renditions (an SVG, or a file
      // too narrow for the smallest rung). Null says "use `src` alone" rather
      // than putting an empty srcset attribute on the page.
      return item === null || item.srcset === '' ? null : item.srcset;
    },
  };
}
