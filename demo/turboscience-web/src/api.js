// The delivery client for the TurboScience front end.
//
// Everything is read-only and anonymous — click-cms's delivery API serves only
// *published* content with no account. The base is empty because nginx (and the
// Vite dev proxy) forward /api to the click-cms service, so the SPA is always
// same-origin: no CORS, no API host baked into the bundle.

async function get(path) {
  const res = await fetch(path, { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    const err = new Error(`Request failed (${res.status})`);
    err.status = res.status;
    throw err;
  }
  return res.json();
}

// A page and the media it references, in a shape the section renderer can use.
export async function fetchPage(slug) {
  const body = await get(`/api/pages/${encodeURIComponent(slug)}`);
  const page = body.data?.data ?? {};
  return {
    title: page.title ?? '',
    sections: Array.isArray(page.sections) ? page.sections : [],
    seo: page.seo ?? {},
    // id -> { urls, alt, width, height } for image fields.
    media: body.media ?? {},
  };
}

// The published entries of a collection, newest the store's order.
export async function fetchCollection(type, params = '') {
  const body = await get(`/api/collections/${encodeURIComponent(type)}/published${params}`);
  return { items: body.data ?? [], meta: body.meta ?? null };
}

export async function fetchEntry(type, slug) {
  const body = await get(`/api/collections/${encodeURIComponent(type)}/published/${encodeURIComponent(slug)}`);
  return body.data ?? null;
}

// Resolve a media id from a page's media map to a usable image URL.
export function imageUrl(media, id) {
  const item = media?.[id];
  return item?.urls?.original ?? '';
}
