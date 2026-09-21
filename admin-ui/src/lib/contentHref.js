/**
 * Admin editor URL for a page or collection entry under review / release.
 *
 * Pages stay on `/admin/pages/edit/{slug}`. Collection entries use the
 * deep-linkable `/admin/collections/{type}/entries/{slug}` route so the
 * reviews inbox and release screen can open the right editor.
 */
export function contentEditorHref({ type = 'page', page = '', locale = '' } = {}) {
  const slug = encodeURIComponent(page || '');
  const kind = type && type !== 'page' ? type : 'page';
  const path = kind === 'page'
    ? `/admin/pages/edit/${slug}`
    : `/admin/collections/${encodeURIComponent(kind)}/entries/${slug}`;

  return locale ? `${path}?locale=${encodeURIComponent(locale)}` : path;
}
