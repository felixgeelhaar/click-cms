<?php

declare(strict_types=1);

use Click\Cms\Application\Plugin\BasePlugin;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Http\PageShell;

class Plugin_visual_builder extends BasePlugin
{
    /**
     * The viewport at which a `columns` node stops stacking when the document
     * declares no breakpoint under the id the node asks for. Without it a
     * document saved before breakpoints existed would stack forever, which
     * looks broken on a desktop.
     */
    /**
     * Every node type this renderer knows how to draw.
     *
     * Declared rather than left implicit in the dispatch below because
     * `schemas/visual-builder.schema.json` lists the same set and nothing
     * validates against it at runtime — so the schema went stale the first time
     * a type was added and nobody noticed. A test asserts the two agree, which
     * is the only thing that keeps a published schema honest when no code path
     * reads it.
     */
    public const NODE_TYPES = [
        'section', 'text', 'image', 'button', 'grid', 'spacer', 'chart',
        'columns', 'column', 'video', 'embed', 'list', 'quote', 'divider',
    ];

    private const COLUMNS_FALLBACK_MIN_WIDTH = 640;

    /**
     * Third-party embeds are built from an allowlist, never from author HTML.
     *
     * The rule this encodes: an author supplies a *URL*, the CMS decides whether
     * it belongs to a provider it knows, extracts an id under a strict charset,
     * and then constructs the iframe itself. Nothing an author types is ever
     * emitted as markup, so there is no path from an embed field to a script
     * tag — the classic "paste your embed code here" stored-XSS hole simply does
     * not exist here.
     *
     * `sandbox` is set as tightly as each provider still functions under.
     * allow-same-origin looks like it defeats the sandbox but does not: the
     * origin restored is the *provider's*, not this site's, so the frame still
     * cannot reach the embedding document.
     */
    private const EMBED_PROVIDERS = [
        'youtube' => [
            'title' => 'YouTube video player',
            'allow' => 'accelerometer; encrypted-media; picture-in-picture; web-share',
            'sandbox' => 'allow-scripts allow-same-origin allow-presentation allow-popups',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'fullscreen' => true,
        ],
        'vimeo' => [
            'title' => 'Vimeo video player',
            'allow' => 'encrypted-media; picture-in-picture',
            'sandbox' => 'allow-scripts allow-same-origin allow-presentation allow-popups',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'fullscreen' => true,
        ],
        'openstreetmap' => [
            'title' => 'Map',
            'allow' => '',
            'sandbox' => 'allow-scripts allow-same-origin allow-popups',
            'referrerpolicy' => 'no-referrer',
            'fullscreen' => false,
        ],
        'googlemaps' => [
            'title' => 'Map',
            'allow' => '',
            'sandbox' => 'allow-scripts allow-same-origin allow-popups',
            'referrerpolicy' => 'no-referrer',
            'fullscreen' => false,
        ],
    ];

    public function getPluginId(): string
    {
        return 'visual-builder';
    }

    public function getPluginName(): string
    {
        return 'Visual Builder';
    }

    public function hook_web_render(array $params): ?string
    {
        $page = $params['page'] ?? null;
        if (!$page instanceof Content) {
            return null;
        }

        $data = $page->data ?? [];
        $builder = $data['builder'] ?? null;
        if (!is_array($builder)) {
            return null;
        }

        $nodes = $builder['nodes'] ?? [];
        $rootId = $builder['root'] ?? null;
        if (!is_string($rootId) || !isset($nodes[$rootId])) {
            return null;
        }

        $body = $this->renderNode($nodes, $rootId);

        // The per-breakpoint media queries are a head concern, not body markup,
        // so they ride into the shell's head rather than being inlined before
        // the body. An empty rule set adds no style tag.
        $responsiveStyles = $this->buildResponsiveStyles($nodes, $builder['breakpoints'] ?? []);
        $extraHead = $responsiveStyles !== '' ? '<style>' . $responsiveStyles . '</style>' : '';

        // Wrap the node tree in the site's shared shell so a builder page carries
        // the same navigation, SEO metadata and theme as an ordinary page. When
        // the shell is unavailable — an older core, or a direct call — fall back
        // to a minimal standalone document so the page still renders.
        $shell = $params['shell'] ?? null;
        if ($shell instanceof PageShell) {
            return $shell->render($body, $extraHead);
        }

        $title = htmlspecialchars($page->title(), ENT_QUOTES, 'UTF-8');
        $head = $extraHead !== '' ? '<title>' . $title . '</title>' . $extraHead : '<title>' . $title . '</title>';

        return '<!doctype html><html><head><meta charset="utf-8">' . $head . '</head><body>' . $body . '</body></html>';
    }

    private function renderNode(array $nodes, string $id): string
    {
        $node = $nodes[$id] ?? null;
        if (!is_array($node)) {
            return '';
        }

        $type = $node['type'] ?? 'section';
        $children = $node['children'] ?? [];
        $props = $node['props'] ?? [];
        $styles = $node['styles'] ?? [];
        $responsive = $node['responsive']['base'] ?? null;
        if (is_array($responsive)) {
            $props = array_merge($props, $responsive['props'] ?? []);
            $styles = array_merge($styles, $responsive['styles'] ?? []);
        }

        if ($type === 'grid' && isset($props['columns']) && !isset($styles['gridTemplateColumns'])) {
            $styles['display'] = $styles['display'] ?? 'grid';
            $styles['gridTemplateColumns'] = 'repeat(' . (int) $props['columns'] . ', minmax(0, 1fr))';
        }

        if ($type === 'columns') {
            // Mobile-first on purpose: the base rule is a single stacked column
            // and buildResponsiveStyles() emits the min-width query that widens
            // it. A viewport that never matches — or a document carrying no
            // breakpoints at all — therefore stacks, which is the readable
            // failure mode rather than six columns crushed into a phone.
            $styles['display'] = $styles['display'] ?? 'grid';
            $styles['gridTemplateColumns'] = $styles['gridTemplateColumns'] ?? '1fr';
        }

        if ($type === 'divider') {
            $styles = $this->dividerStyles($props, $styles);
        }

        $styleAttr = $this->styleString($styles);
        $dataAttr = ' data-node-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
        $inner = '';
        foreach ($children as $childId) {
            if (!is_string($childId)) {
                continue;
            }
            $inner .= $this->renderNode($nodes, $childId);
        }

        if ($type === 'text') {
            $text = htmlspecialchars((string)($props['text'] ?? ''), ENT_QUOTES, 'UTF-8');
            return '<p' . $dataAttr . $styleAttr . '>' . $text . '</p>';
        }

        if ($type === 'image') {
            $src = htmlspecialchars($this->safeUrl((string)($props['src'] ?? '')), ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars((string)($props['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            return '<img src="' . $src . '" alt="' . $alt . '"' . $dataAttr . $styleAttr . ' />';
        }

        if ($type === 'button') {
            $label = htmlspecialchars((string)($props['label'] ?? 'Button'), ENT_QUOTES, 'UTF-8');
            // mailto:/tel: are as legitimate on a call-to-action as http(s);
            // everything else — javascript:, data: — is dropped to '#' rather
            // than becoming a one-click script in the published page.
            $href = $this->safeUrl((string)($props['href'] ?? '#'), ['http', 'https', 'mailto', 'tel']);
            $href = htmlspecialchars($href === '' ? '#' : $href, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $href . '"' . $dataAttr . $styleAttr . '>' . $label . '</a>';
        }

        if ($type === 'spacer') {
            return '<div' . $dataAttr . $styleAttr . '></div>';
        }

        if ($type === 'chart') {
            return $this->renderChart($props, $styleAttr, $dataAttr);
        }

        if ($type === 'video') {
            return $this->renderVideo($props, $styleAttr, $dataAttr);
        }

        if ($type === 'embed') {
            return $this->renderEmbed($props, $styleAttr, $dataAttr);
        }

        if ($type === 'list') {
            return $this->renderList($props, $styleAttr, $dataAttr);
        }

        if ($type === 'quote') {
            return $this->renderQuote($props, $styleAttr, $dataAttr);
        }

        if ($type === 'divider') {
            // A thematic break carries its own semantics, so it stays an <hr>
            // rather than a styled div; the styles were folded in above.
            return '<hr' . $dataAttr . $styleAttr . ' />';
        }

        if ($type === 'section') {
            return '<section' . $dataAttr . $styleAttr . '>' . $inner . '</section>';
        }

        // grid, columns and column are all plain boxes; the layout they impose
        // lives entirely in the styles computed above.
        if ($type === 'grid' || $type === 'columns' || $type === 'column') {
            return '<div' . $dataAttr . $styleAttr . '>' . $inner . '</div>';
        }

        // A type this renderer does not know — a document written by a newer
        // editor, or a typo — is skipped rather than guessed at. Emitting a
        // wrapper of the wrong kind would corrupt the surrounding layout, and
        // the rest of the page must still publish.
        return '';
    }

    /* ------------------------------------------------------------ media -- */

    /**
     * An uploaded or externally hosted video.
     *
     * Defaults are chosen for a page that has to load fast and behave: nothing
     * is fetched until the visitor asks for it, and an autoplaying clip is
     * necessarily muted because every current browser refuses to start an
     * audible video on its own — a clip that "does not play" would otherwise be
     * the author's problem to diagnose.
     */
    private function renderVideo(array $props, string $styleAttr, string $dataAttr): string
    {
        $src = $this->safeUrl((string)($props['src'] ?? ''));
        if ($src === '') {
            return '';
        }

        $autoplay = !empty($props['autoplay']);
        $muted = $autoplay || !empty($props['muted']);
        $loop = $autoplay || !empty($props['loop']);
        $controls = !array_key_exists('controls', $props) || !empty($props['controls']);
        $preload = (string)($props['preload'] ?? 'none');
        if (!in_array($preload, ['none', 'metadata', 'auto'], true)) {
            $preload = 'none';
        }

        $attrs = ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
        $attrs .= ' preload="' . $preload . '"';
        // playsinline keeps a phone from hijacking the page into fullscreen.
        $attrs .= ' playsinline';
        if ($controls) {
            $attrs .= ' controls';
        }
        if ($autoplay) {
            $attrs .= ' autoplay';
        }
        if ($muted) {
            $attrs .= ' muted';
        }
        if ($loop) {
            $attrs .= ' loop';
        }

        $poster = $this->safeUrl((string)($props['poster'] ?? ''));
        if ($poster !== '') {
            $attrs .= ' poster="' . htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') . '"';
        }

        // A control-less video has no accessible name of its own, so the label
        // is what a screen reader has to go on.
        $label = trim((string)($props['label'] ?? ''));
        if ($label !== '') {
            $attrs .= ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';
        }

        $inner = '';
        $captions = $this->safeUrl((string)($props['captions'] ?? ''));
        if ($captions !== '') {
            $lang = (string)($props['captionsLang'] ?? 'en');
            $lang = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) === 1 ? $lang : 'en';
            $inner .= '<track kind="captions" src="' . htmlspecialchars($captions, ENT_QUOTES, 'UTF-8') . '"'
                . ' srclang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"'
                . ' label="' . htmlspecialchars((string)($props['captionsLabel'] ?? 'Captions'), ENT_QUOTES, 'UTF-8') . '" default />';
        }

        // Fallback content for a browser that cannot play the file at all: a
        // link is still a way to reach the material.
        $inner .= '<a href="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '">Download the video</a>';

        return '<video' . $dataAttr . $attrs . $styleAttr . '>' . $inner . '</video>';
    }

    /* ----------------------------------------------------------- embeds -- */

    private function renderEmbed(array $props, string $styleAttr, string $dataAttr): string
    {
        $url = $this->safeUrl((string)($props['url'] ?? ''));
        // An embed is by definition somebody else's absolute http(s) URL. Empty,
        // a scheme safeUrl() refused, or anything relative gets nothing at all:
        // there is no degraded form of a javascript: URL — or of a stray blob of
        // text pasted into the field — worth putting on a public page.
        if ($url === '' || preg_match('~^https?://~i', $url) !== 1) {
            return '';
        }

        $embed = $this->resolveEmbed($url);
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        if ($embed === null) {
            // Unrecognised provider. A link is the whole fallback: it is content
            // this renderer built, not markup the author supplied.
            $label = trim((string)($props['title'] ?? ''));
            $label = $label !== '' ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') : $escapedUrl;
            return '<div' . $dataAttr . $styleAttr . '><a href="' . $escapedUrl
                . '" rel="noopener noreferrer">' . $label . '</a></div>';
        }

        $provider = self::EMBED_PROVIDERS[$embed['provider']];
        $title = trim((string)($props['title'] ?? ''));
        $title = $title !== '' ? $title : $provider['title'];

        $height = (int)($props['height'] ?? 0);
        if ($height > 0) {
            $frameStyle = 'border:0;width:100%;height:' . max(120, min(2000, $height)) . 'px';
        } elseif ($provider['fullscreen']) {
            $frameStyle = 'border:0;width:100%;aspect-ratio:16 / 9';
        } else {
            $frameStyle = 'border:0;width:100%;height:400px';
        }

        $iframe = '<iframe src="' . htmlspecialchars($embed['src'], ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
            . ' loading="lazy"'
            . ' referrerpolicy="' . $provider['referrerpolicy'] . '"'
            . ' sandbox="' . $provider['sandbox'] . '"';
        if ($provider['allow'] !== '') {
            $iframe .= ' allow="' . $provider['allow'] . '"';
        }
        if ($provider['fullscreen']) {
            $iframe .= ' allowfullscreen';
        }
        $iframe .= ' style="' . $frameStyle . '"></iframe>';

        return '<div' . $dataAttr . $styleAttr . '>' . $iframe . '</div>';
    }

    /**
     * Match a URL against the provider allowlist and rebuild its embed URL.
     *
     * Every value that reaches the returned src is either a literal from this
     * method or a token that matched a strict charset — ids are [A-Za-z0-9_-],
     * coordinates are floats re-formatted through sprintf. A hostile string
     * cannot survive that round trip, which is why the caller can treat the
     * result as trusted and why an unknown host must return null rather than
     * fall back to "use the URL as-is".
     *
     * @return array{provider: string, src: string}|null
     */
    private function resolveEmbed(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        // Only the www. prefix is folded away. Any other subdomain is a
        // different host and has to be listed explicitly, so an
        // "evil.youtube.com.attacker.net" never matches.
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = null;
            if ($host === 'youtu.be') {
                $id = trim($path, '/');
            } elseif (preg_match('~^/(?:embed|shorts|live|v)/([^/?#]+)~', $path, $m) === 1) {
                $id = $m[1];
            } elseif ($path === '/watch' && isset($query['v']) && is_string($query['v'])) {
                $id = $query['v'];
            }

            if ($id === null || preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id) !== 1) {
                return null;
            }

            // The nocookie host is the same player without the tracking cookie
            // dropped before the visitor has pressed play.
            return ['provider' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $id];
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('~/(\d{6,12})(?:$|[/?#])~', $path, $m) !== 1) {
                return null;
            }

            return ['provider' => 'vimeo', 'src' => 'https://player.vimeo.com/video/' . $m[1]];
        }

        if ($host === 'openstreetmap.org') {
            $src = $this->openStreetMapEmbed($parts, $query);
            return $src === null ? null : ['provider' => 'openstreetmap', 'src' => $src];
        }

        if (in_array($host, ['google.com', 'maps.google.com'], true) && $path === '/maps/embed') {
            // Only the officially generated embed URL is accepted — the opaque
            // `pb` blob from Google's own "Embed a map" dialog. Its charset is
            // checked rather than interpreted.
            $pb = $query['pb'] ?? null;
            if (!is_string($pb) || preg_match("/^[A-Za-z0-9!._~*'()-]{8,2000}$/", $pb) !== 1) {
                return null;
            }

            return ['provider' => 'googlemaps', 'src' => 'https://www.google.com/maps/embed?pb=' . $pb];
        }

        return null;
    }

    /**
     * Build an OpenStreetMap embed from either an explicit bbox or the
     * `#map=zoom/lat/lon` fragment a shared OSM link carries.
     *
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $query
     */
    private function openStreetMapEmbed(array $parts, array $query): ?string
    {
        $bbox = $query['bbox'] ?? null;
        if (is_string($bbox)) {
            $values = explode(',', $bbox);
            if (count($values) === 4) {
                foreach ($values as $value) {
                    if (!is_numeric($value)) {
                        return null;
                    }
                }
                return 'https://www.openstreetmap.org/export/embed.html?bbox='
                    . implode(',', array_map(static fn ($v) => sprintf('%.5f', (float) $v), $values))
                    . '&layer=mapnik';
            }
        }

        $fragment = (string)($parts['fragment'] ?? '');
        if (preg_match('/^map=(\d{1,2})\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/', $fragment, $m) !== 1) {
            return null;
        }

        $zoom = (int) $m[1];
        $lat = (float) $m[2];
        $lon = (float) $m[3];
        if ($zoom > 20 || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        // OSM's embed takes a bounding box, not a centre point, so the zoom is
        // turned into a span: each level halves the visible width of the world.
        $span = 360 / (2 ** max(1, $zoom));
        $left = max(-180, $lon - ($span / 2));
        $right = min(180, $lon + ($span / 2));
        $bottom = max(-90, $lat - ($span / 4));
        $top = min(90, $lat + ($span / 4));

        return 'https://www.openstreetmap.org/export/embed.html?bbox='
            . sprintf('%.5f,%.5f,%.5f,%.5f', $left, $bottom, $right, $top)
            . '&layer=mapnik&marker=' . sprintf('%.5f,%.5f', $lat, $lon);
    }

    /* ------------------------------------------------------------- text -- */

    private function renderList(array $props, string $styleAttr, string $dataAttr): string
    {
        $items = $props['items'] ?? [];
        if (!is_array($items)) {
            return '';
        }

        $html = '';
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $html .= '<li>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        // An empty <ul> is announced as a list of zero items; nothing is better.
        if ($html === '') {
            return '';
        }

        $tag = !empty($props['ordered']) ? 'ol' : 'ul';
        return '<' . $tag . $dataAttr . $styleAttr . '>' . $html . '</' . $tag . '>';
    }

    private function renderQuote(array $props, string $styleAttr, string $dataAttr): string
    {
        $text = trim((string)($props['text'] ?? ''));
        if ($text === '') {
            return '';
        }

        // figure/blockquote/figcaption rather than a blockquote with the
        // attribution inside it: the attribution is *about* the quote, not part
        // of what was said, and HTML has a element for exactly that distinction.
        $cite = $this->safeUrl((string)($props['cite'] ?? ''));
        $citeAttr = $cite !== '' ? ' cite="' . htmlspecialchars($cite, ENT_QUOTES, 'UTF-8') . '"' : '';

        $html = '<figure' . $dataAttr . $styleAttr . '><blockquote' . $citeAttr . '><p>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></blockquote>';

        $attribution = trim((string)($props['attribution'] ?? ''));
        if ($attribution !== '') {
            $source = trim((string)($props['source'] ?? ''));
            $html .= '<figcaption>' . htmlspecialchars($attribution, ENT_QUOTES, 'UTF-8');
            if ($source !== '') {
                // <cite> names the work, which is not the same thing as the
                // person being quoted — hence the two separate props.
                $html .= ' <cite>' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '</cite>';
            }
            $html .= '</figcaption>';
        }

        return $html . '</figure>';
    }

    /**
     * Fold a divider's props into the styles that draw its rule.
     *
     * The line is drawn with border-top rather than the browser default so the
     * three options behave identically everywhere; author styles still win,
     * which is what lets width/margin be set from the ordinary style panel.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $styles
     * @return array<string, mixed>
     */
    private function dividerStyles(array $props, array $styles): array
    {
        $lineStyle = (string)($props['lineStyle'] ?? 'solid');
        if (!in_array($lineStyle, ['solid', 'dashed', 'dotted', 'double'], true)) {
            $lineStyle = 'solid';
        }

        $thickness = (int)($props['thickness'] ?? 1);
        $thickness = max(1, min(20, $thickness));

        $color = trim((string)($props['color'] ?? ''));
        // A colour is a narrow grammar; anything outside it is dropped rather
        // than passed through, so the generated declaration cannot be steered
        // into something else.
        if (preg_match('/^(#[0-9A-Fa-f]{3,8}|[A-Za-z]{3,20}|(rgb|hsl)a?\([0-9.,%\s\/]+\))$/', $color) !== 1) {
            $color = 'currentColor';
        }

        $styles['border'] = $styles['border'] ?? '0';
        $styles['borderTop'] = $styles['borderTop'] ?? ($thickness . 'px ' . $lineStyle . ' ' . $color);

        return $styles;
    }

    /**
     * Reduce a URL to one this renderer is willing to emit, or ''.
     *
     * The check runs against a copy with whitespace and control characters
     * removed because browsers strip those before parsing a scheme: a naive
     * prefix test does not see "java\tscript:alert(1)", but a browser does. The
     * stripped form is what gets returned for the same reason.
     *
     * @param list<string> $schemes
     */
    private function safeUrl(string $url, array $schemes = ['http', 'https']): string
    {
        $clean = preg_replace('/[\x00-\x20\x7f]/', '', $url);
        if (!is_string($clean) || $clean === '') {
            return '';
        }

        if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*):~', $clean, $m) === 1) {
            return in_array(strtolower($m[1]), $schemes, true) ? $clean : '';
        }

        // No scheme at all: a site-relative path, a query or a fragment. Those
        // resolve against this page's own origin and carry no scheme risk.
        return $clean;
    }

    private function styleString(array $styles): string
    {
        if (empty($styles)) {
            return '';
        }

        $pairs = [];
        foreach ($styles as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $cssKey = $this->toCssProperty((string) $key);
            // No legitimate declaration value contains a semicolon or a brace;
            // one that does is trying to append declarations of its own to the
            // attribute this builds.
            $pairs[] = $cssKey . ':' . str_replace([';', '{', '}'], '', (string) $value);
        }

        if (empty($pairs)) {
            return '';
        }

        return ' style="' . htmlspecialchars(implode(';', $pairs), ENT_QUOTES, 'UTF-8') . '"';
    }

    private function toCssProperty(string $key): string
    {
        if (str_contains($key, '-')) {
            return $key;
        }

        $kebab = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $key);
        if ($kebab === null) {
            return $key;
        }

        return strtolower($kebab);
    }

    private function renderChart(array $props, string $styleAttr, string $dataAttr): string
    {
        $chartType = (string)($props['chartType'] ?? 'bar');
        $title = trim((string)($props['title'] ?? ''));
        $color = (string)($props['color'] ?? '#0ea5a4');
        $width = max(240, (int)($props['width'] ?? 640));
        $height = max(160, (int)($props['height'] ?? 280));

        $data = $props['data'] ?? [];
        $points = [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = (string)($item['label'] ?? '');
                $value = (float)($item['value'] ?? 0);
                $points[] = ['label' => $label, 'value' => $value];
            }
        }

        $titleHtml = '';
        if ($title !== '') {
            $titleHtml = '<div style="margin-bottom:12px;font-weight:600;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $svg = $this->renderChartSvg($chartType, $points, $width, $height, $color, $title);
        return '<div' . $dataAttr . $styleAttr . '>' . $titleHtml . $svg . '</div>';
    }

    private function buildResponsiveStyles(array $nodes, array $breakpoints): string
    {
        // Note there is no early return on an empty breakpoint list any more: a
        // `columns` node generates its own un-stacking query and must still get
        // one in a document that never declared breakpoints.
        if (empty($nodes)) {
            return '';
        }

        $rulesByBreakpoint = [];
        foreach ($breakpoints as $breakpoint) {
            $id = $breakpoint['id'] ?? null;
            $minWidth = $breakpoint['minWidth'] ?? null;
            if (!is_string($id) || $id === 'base' || !is_numeric($minWidth)) {
                continue;
            }
            $rulesByBreakpoint[$id] = ['minWidth' => (int) $minWidth, 'rules' => []];
        }

        foreach ($nodes as $nodeId => $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach ($rulesByBreakpoint as $breakpointId => $entry) {
                $responsive = $node['responsive'][$breakpointId] ?? null;
                if (!is_array($responsive)) {
                    continue;
                }

                $styles = $responsive['styles'] ?? [];
                $props = $responsive['props'] ?? [];
                if (($node['type'] ?? '') === 'grid' && isset($props['columns'])) {
                    $styles['display'] = $styles['display'] ?? 'grid';
                    $styles['gridTemplateColumns'] = 'repeat(' . (int) $props['columns'] . ', minmax(0, 1fr))';
                }

                if (!is_array($styles) || empty($styles)) {
                    continue;
                }

                $pairs = [];
                foreach ($styles as $key => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $pairs[] = $this->toCssProperty((string) $key) . ':' . $value;
                }

                if (empty($pairs)) {
                    continue;
                }

                $rulesByBreakpoint[$breakpointId]['rules'][] = '[data-node-id="' . addslashes((string) $nodeId) . '"]{' . implode(';', $pairs) . '}';
            }
        }

        $this->addColumnRules($nodes, $rulesByBreakpoint);

        // Ascending min-width order is what makes the cascade work: two queries
        // matching the same wide viewport must resolve to the wider one's rule,
        // and that is decided by source order, not specificity.
        uasort($rulesByBreakpoint, static fn ($a, $b) => $a['minWidth'] <=> $b['minWidth']);

        $css = '';
        foreach ($rulesByBreakpoint as $entry) {
            if (empty($entry['rules'])) {
                continue;
            }
            $css .= '@media (min-width:' . $entry['minWidth'] . 'px){' . implode('', $entry['rules']) . '}';
        }

        return $css;
    }

    /**
     * Emit the query that un-stacks each `columns` node.
     *
     * Generated rather than stored: an author picks "3 columns" once and gets a
     * layout that stacks on a phone without having to discover, and hand-author,
     * a responsive override for every columns node on the page. An explicit
     * override still wins — if the author has set gridTemplateColumns for that
     * breakpoint themselves, nothing is added.
     *
     * @param array<string, mixed> $nodes
     * @param array<string, array{minWidth: int, rules: list<string>}> $rulesByBreakpoint
     */
    private function addColumnRules(array $nodes, array &$rulesByBreakpoint): void
    {
        foreach ($nodes as $nodeId => $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'columns') {
                continue;
            }

            $props = $node['props'] ?? [];
            $baseOverride = $node['responsive']['base']['props'] ?? null;
            if (is_array($baseOverride)) {
                $props = array_merge($props, $baseOverride);
            }

            $count = (int)($props['count'] ?? count($node['children'] ?? []));
            $count = max(1, min(6, $count));
            if ($count < 2) {
                continue;
            }

            $stackAt = (string)($props['stackAt'] ?? 'sm');
            if (isset($node['responsive'][$stackAt]['styles']['gridTemplateColumns'])) {
                continue;
            }

            $key = $stackAt;
            if (!isset($rulesByBreakpoint[$key])) {
                // The document does not declare the breakpoint this node names,
                // so the node gets a bucket of its own at the fallback width
                // rather than silently never un-stacking.
                $key = 'columns:' . self::COLUMNS_FALLBACK_MIN_WIDTH;
                $rulesByBreakpoint[$key] = $rulesByBreakpoint[$key]
                    ?? ['minWidth' => self::COLUMNS_FALLBACK_MIN_WIDTH, 'rules' => []];
            }

            $rulesByBreakpoint[$key]['rules'][] = '[data-node-id="' . addslashes((string) $nodeId)
                . '"]{grid-template-columns:repeat(' . $count . ', minmax(0, 1fr))}';
        }
    }

    private function renderChartSvg(string $chartType, array $points, int $width, int $height, string $color, string $title): string
    {
        $margin = ['top' => 24, 'right' => 20, 'bottom' => 32, 'left' => 36];
        $innerWidth = max(1, $width - $margin['left'] - $margin['right']);
        $innerHeight = max(1, $height - $margin['top'] - $margin['bottom']);
        $maxValue = 1;
        foreach ($points as $point) {
            $maxValue = max($maxValue, (float)($point['value'] ?? 0));
        }

        $aria = $title !== '' ? htmlspecialchars($title, ENT_QUOTES, 'UTF-8') : 'Chart';
        $svg = '<svg width="100%" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . $aria . '">';
        $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="transparent" />';

        if (empty($points)) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($height / 2) . '" text-anchor="middle" fill="#94a3b8" font-size="12">Add chart data</text>';
            $svg .= '</svg>';
            return $svg;
        }

        if ($chartType === 'line') {
            $step = count($points) > 1 ? $innerWidth / (count($points) - 1) : 0;
            $path = '';
            $circles = '';
            $labels = '';
            foreach ($points as $index => $point) {
                $x = $margin['left'] + ($step * $index);
                $value = (float)($point['value'] ?? 0);
                $y = $margin['top'] + ($innerHeight - ($value / $maxValue) * $innerHeight);
                $path .= ($index === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
                $circles .= '<circle cx="' . $x . '" cy="' . $y . '" r="3.5" fill="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" />';
                $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($label !== '') {
                    $labels .= '<text x="' . $x . '" y="' . ($margin['top'] + $innerHeight + 18) . '" text-anchor="middle" fill="#475569" font-size="11">' . $label . '</text>';
                }
            }
            $svg .= '<path d="' . trim($path) . '" fill="none" stroke="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" stroke-width="2" />';
            $svg .= $circles;
            $svg .= '<line x1="' . $margin['left'] . '" y1="' . ($margin['top'] + $innerHeight) . '" x2="' . ($margin['left'] + $innerWidth) . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
            $svg .= '<line x1="' . $margin['left'] . '" y1="' . $margin['top'] . '" x2="' . $margin['left'] . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
            $svg .= $labels;
            $svg .= '</svg>';
            return $svg;
        }

        $count = count($points);
        $slot = $count > 0 ? $innerWidth / $count : $innerWidth;
        $barWidth = max(6, $slot * 0.7);
        $gap = $slot - $barWidth;
        $bars = '';
        $labels = '';
        foreach ($points as $index => $point) {
            $value = (float)($point['value'] ?? 0);
            $barHeight = ($value / $maxValue) * $innerHeight;
            $x = $margin['left'] + ($slot * $index) + ($gap / 2);
            $y = $margin['top'] + ($innerHeight - $barHeight);
            $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barWidth . '" height="' . $barHeight . '" rx="6" fill="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" />';
            $label = htmlspecialchars((string)($point['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($label !== '') {
                $labels .= '<text x="' . ($x + ($barWidth / 2)) . '" y="' . ($margin['top'] + $innerHeight + 18) . '" text-anchor="middle" fill="#475569" font-size="11">' . $label . '</text>';
            }
        }

        $svg .= $bars;
        $svg .= '<line x1="' . $margin['left'] . '" y1="' . ($margin['top'] + $innerHeight) . '" x2="' . ($margin['left'] + $innerWidth) . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
        $svg .= '<line x1="' . $margin['left'] . '" y1="' . $margin['top'] . '" x2="' . $margin['left'] . '" y2="' . ($margin['top'] + $innerHeight) . '" stroke="#cbd5e1" />';
        $svg .= $labels;
        $svg .= '</svg>';

        return $svg;
    }
}
