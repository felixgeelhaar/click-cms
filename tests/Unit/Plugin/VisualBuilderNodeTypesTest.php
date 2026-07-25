<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The node types the builder can put on a public page.
 *
 * Two things are being defended here. The first is semantics: a quote has to be
 * a figure/blockquote, a list an ol/ul, a divider an hr, because the published
 * page is what assistive technology reads and a div soup is unreadable.
 *
 * The second, and the reason most of these tests exist, is that this renderer
 * turns editor-supplied data into public HTML. Every value that reaches the
 * output is either escaped or reconstructed from an allowlist; an unescaped prop
 * is a stored cross-site-scripting hole that fires for every visitor. The embed
 * type is the sharp edge — it exists to emit an iframe, so the question "which
 * URLs can produce one" is the whole security boundary.
 */
final class VisualBuilderNodeTypesTest extends TestCase
{
    private function plugin(): object
    {
        require_once dirname(__DIR__, 3) . '/plugins/visual-builder/bootstrap.php';

        return new \Plugin_visual_builder(new PluginManager(dirname(__DIR__, 3) . '/plugins'));
    }

    /**
     * Render a single node as the only child of a root section.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $extraNodes
     */
    private function render(array $node, array $extraNodes = [], array $breakpoints = []): string
    {
        $page = Content::create(ContentKey::page('landing'), [
            'title' => 'Landing',
            'builder' => [
                'root' => 'r',
                'breakpoints' => $breakpoints,
                'nodes' => array_merge([
                    'r' => ['type' => 'section', 'children' => ['n']],
                    'n' => $node,
                ], $extraNodes),
            ],
        ]);

        return (string) $this->plugin()->hook_web_render(['page' => $page, 'preview' => false]);
    }

    /* ------------------------------------------------------------ columns -- */

    public function testColumnsRendersEachColumnAndItsContents(): void
    {
        $html = $this->render(
            ['type' => 'columns', 'children' => ['c1', 'c2'], 'props' => ['count' => 2]],
            [
                'c1' => ['type' => 'column', 'children' => ['t1']],
                'c2' => ['type' => 'column', 'children' => ['t2']],
                't1' => ['type' => 'text', 'props' => ['text' => 'Left copy']],
                't2' => ['type' => 'text', 'props' => ['text' => 'Right copy']],
            ]
        );

        $this->assertStringContainsString('data-node-id="c1"', $html);
        $this->assertStringContainsString('data-node-id="c2"', $html);
        $this->assertStringContainsString('Left copy', $html);
        $this->assertStringContainsString('Right copy', $html);
        // Each column's content sits inside its own column, in order.
        $this->assertLessThan(strpos($html, 'Right copy'), strpos($html, 'Left copy'));
    }

    public function testColumnsStackAtTheBaseWidthAndWidenAtABreakpoint(): void
    {
        $html = $this->render(
            ['type' => 'columns', 'children' => ['c1'], 'props' => ['count' => 3, 'stackAt' => 'md']],
            ['c1' => ['type' => 'column', 'children' => []]],
            [['id' => 'md', 'minWidth' => 768]]
        );

        // Narrow viewports get the single-column base rule inline...
        $this->assertMatchesRegularExpression('/data-node-id="n"[^>]*grid-template-columns:1fr/', $html);
        // ...and the three-column layout only arrives with the media query.
        $this->assertStringContainsString(
            '@media (min-width:768px){[data-node-id="n"]{grid-template-columns:repeat(3, minmax(0, 1fr))}}',
            $html
        );
    }

    public function testColumnsStillUnstackWhenTheDocumentDeclaresNoBreakpoints(): void
    {
        // A document saved before breakpoints existed must not stack forever.
        $html = $this->render(['type' => 'columns', 'children' => [], 'props' => ['count' => 2]]);

        $this->assertStringContainsString('@media (min-width:640px)', $html);
        $this->assertStringContainsString('grid-template-columns:repeat(2, minmax(0, 1fr))', $html);
    }

    public function testASingleColumnNeedsNoMediaQuery(): void
    {
        $html = $this->render(['type' => 'columns', 'children' => [], 'props' => ['count' => 1]]);

        $this->assertStringNotContainsString('@media', $html);
    }

    /* -------------------------------------------------------------- video -- */

    public function testVideoRendersWithConservativeDefaults(): void
    {
        $html = $this->render([
            'type' => 'video',
            'props' => ['src' => '/media/clip.mp4', 'poster' => '/media/poster.jpg'],
        ]);

        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('src="/media/clip.mp4"', $html);
        $this->assertStringContainsString('poster="/media/poster.jpg"', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertStringContainsString('playsinline', $html);
        $this->assertStringContainsString('controls', $html);
        $this->assertStringContainsString('data-node-id="n"', $html);
        // Nothing plays on its own unless the author asked for it.
        $this->assertStringNotContainsString('autoplay', $html);
    }

    public function testAnAutoplayingVideoIsForcedMutedAndLooping(): void
    {
        $html = $this->render([
            'type' => 'video',
            'props' => ['src' => 'https://cdn.example.com/loop.webm', 'autoplay' => true],
        ]);

        $this->assertStringContainsString('autoplay', $html);
        // Browsers refuse to start an audible video, so an unmuted autoplay
        // would simply never play.
        $this->assertStringContainsString('muted', $html);
        $this->assertStringContainsString('loop', $html);
    }

    public function testVideoCaptionsBecomeADefaultTrack(): void
    {
        $html = $this->render([
            'type' => 'video',
            'props' => ['src' => '/clip.mp4', 'captions' => '/clip.vtt', 'captionsLang' => 'de'],
        ]);

        $this->assertStringContainsString('<track kind="captions" src="/clip.vtt"', $html);
        $this->assertStringContainsString('srclang="de"', $html);
        $this->assertStringContainsString('default', $html);
    }

    public function testVideoWithoutASourceRendersNothing(): void
    {
        $html = $this->render(['type' => 'video', 'props' => ['poster' => '/poster.jpg']]);

        $this->assertStringNotContainsString('<video', $html);
    }

    public function testAHostileVideoSourceIsDropped(): void
    {
        $html = $this->render([
            'type' => 'video',
            'props' => ['src' => 'javascript:alert(1)', 'poster' => 'javascript:alert(2)'],
        ]);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    /* -------------------------------------------------------------- embed -- */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function youtubeUrls(): array
    {
        return [
            'watch url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short url' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed url' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts url' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'mobile url' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=42', 'dQw4w9WgXcQ'],
        ];
    }

    /**
     */
    #[DataProvider('youtubeUrls')]
    public function testAYouTubeUrlBecomesAPlayerIframeBuiltFromItsId(string $url, string $id): void
    {
        $html = $this->render(['type' => 'embed', 'props' => ['url' => $url]]);

        // The iframe src is reconstructed from the extracted id, not from the
        // URL the author typed.
        $this->assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/' . $id . '"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('title="YouTube video player"', $html);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"', $html);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $html);
    }

    public function testAVimeoUrlBecomesAPlayerIframe(): void
    {
        $html = $this->render(['type' => 'embed', 'props' => ['url' => 'https://vimeo.com/123456789']]);

        $this->assertStringContainsString('<iframe src="https://player.vimeo.com/video/123456789"', $html);
        $this->assertStringContainsString('title="Vimeo video player"', $html);
    }

    public function testAnOpenStreetMapLinkBecomesAMapIframe(): void
    {
        $html = $this->render([
            'type' => 'embed',
            'props' => ['url' => 'https://www.openstreetmap.org/#map=15/52.5200/13.4050'],
        ]);

        $this->assertStringContainsString('openstreetmap.org/export/embed.html?bbox=', $html);
        // Every coordinate in the generated src came back out of sprintf, so it
        // is numeric by construction.
        $this->assertMatchesRegularExpression('/bbox=(-?\d+\.\d{5},){3}-?\d+\.\d{5}/', $html);
        $this->assertStringContainsString('marker=52.52000,13.40500', $html);
    }

    public function testAGoogleMapsEmbedUrlIsAccepted(): void
    {
        $html = $this->render([
            'type' => 'embed',
            'props' => ['url' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2427.5'],
        ]);

        $this->assertStringContainsString('<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2427.5"', $html);
    }

    public function testAnUnknownHostProducesNoIframe(): void
    {
        $html = $this->render([
            'type' => 'embed',
            'props' => ['url' => 'https://evil.example.com/player'],
        ]);

        $this->assertStringNotContainsString('<iframe', $html);
        // It degrades to a link this renderer built, not to author markup.
        $this->assertStringContainsString('<a href="https://evil.example.com/player" rel="noopener noreferrer">', $html);
    }

    public function testALookalikeHostDoesNotMatchAProvider(): void
    {
        foreach ([
            'https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ',
            'https://evil.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://notvimeo.com/123456789',
            'https://openstreetmap.org.evil.test/#map=15/52.52/13.40',
        ] as $url) {
            $html = $this->render(['type' => 'embed', 'props' => ['url' => $url]]);
            $this->assertStringNotContainsString('<iframe', $html, $url . ' must not produce an iframe');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileEmbedUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(document.cookie)'],
            'obfuscated javascript scheme' => ["java\tscript:alert(1)"],
            'newline-split scheme' => ["java\nscript:alert(1)"],
            'leading whitespace' => ['  javascript:alert(1)'],
            'uppercase scheme' => ['JaVaScRiPt:alert(1)'],
            'data url' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'raw markup' => ['"><script>alert(1)</script>'],
            'file scheme' => ['file:///etc/passwd'],
        ];
    }

    /**
     * The single most important test in this file: no hostile URL may ever
     * become markup, an iframe, or a clickable link.
     *
     */
    #[DataProvider('hostileEmbedUrls')]
    public function testAHostileEmbedUrlProducesNoMarkupAtAll(string $url): void
    {
        $html = $this->render(['type' => 'embed', 'props' => ['url' => $url]]);

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('vbscript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testAProviderUrlCarryingAHostileIdIsRefused(): void
    {
        // The id charset is what stands between a provider match and an
        // attacker-chosen src, so a payload smuggled into the id must not match.
        foreach ([
            'https://www.youtube.com/embed/"><script>alert(1)</script>',
            'https://www.youtube.com/watch?v="onload="alert(1)',
            'https://www.youtube.com/embed/../../evil',
            'https://player.vimeo.com/video/abc',
            'https://www.google.com/maps/embed?pb="><script>alert(1)</script>',
        ] as $url) {
            $html = $this->render(['type' => 'embed', 'props' => ['url' => $url]]);
            $this->assertStringNotContainsString('<iframe', $html, $url . ' must not produce an iframe');
            $this->assertStringNotContainsString('<script>alert', $html, $url . ' must not emit markup');
        }
    }

    public function testAnEmbedTitleIsEscaped(): void
    {
        $html = $this->render([
            'type' => 'embed',
            'props' => [
                'url' => 'https://vimeo.com/123456789',
                'title' => '"><script>alert(1)</script>',
            ],
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /* --------------------------------------------------------------- list -- */

    public function testAnUnorderedListRendersItsItems(): void
    {
        $html = $this->render(['type' => 'list', 'props' => ['items' => ['One', 'Two']]]);

        $this->assertStringContainsString('<ul data-node-id="n"><li>One</li><li>Two</li></ul>', $html);
    }

    public function testAnOrderedListUsesOl(): void
    {
        $html = $this->render(['type' => 'list', 'props' => ['ordered' => true, 'items' => ['First']]]);

        $this->assertStringContainsString('<ol', $html);
        $this->assertStringContainsString('<li>First</li>', $html);
    }

    public function testAListWithNoUsableItemsRendersNothing(): void
    {
        $this->assertStringNotContainsString('<ul', $this->render(['type' => 'list', 'props' => ['items' => ['', '  ']]]));
        $this->assertStringNotContainsString('<ul', $this->render(['type' => 'list', 'props' => ['items' => 'not-an-array']]));
    }

    /* -------------------------------------------------------------- quote -- */

    public function testAQuoteRendersAsAFigureWithItsAttribution(): void
    {
        $html = $this->render([
            'type' => 'quote',
            'props' => [
                'text' => 'The best way out is always through.',
                'attribution' => 'Robert Frost',
                'source' => 'A Servant to Servants',
                'cite' => 'https://example.com/poem',
            ],
        ]);

        $this->assertStringContainsString('<figure data-node-id="n">', $html);
        $this->assertStringContainsString('<blockquote cite="https://example.com/poem"><p>The best way out is always through.</p></blockquote>', $html);
        $this->assertStringContainsString('<figcaption>Robert Frost <cite>A Servant to Servants</cite></figcaption>', $html);
    }

    public function testAQuoteWithNoTextRendersNothing(): void
    {
        $this->assertStringNotContainsString('<figure', $this->render(['type' => 'quote', 'props' => ['attribution' => 'Nobody']]));
    }

    public function testAQuotesCiteUrlIsSchemeChecked(): void
    {
        $html = $this->render([
            'type' => 'quote',
            'props' => ['text' => 'Quoted', 'cite' => 'javascript:alert(1)'],
        ]);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /* ------------------------------------------------------------ divider -- */

    public function testADividerRendersAnHrWithItsLineOptions(): void
    {
        $html = $this->render([
            'type' => 'divider',
            'props' => ['lineStyle' => 'dashed', 'thickness' => 3, 'color' => '#ff0000'],
        ]);

        $this->assertStringContainsString('<hr data-node-id="n"', $html);
        $this->assertStringContainsString('border-top:3px dashed #ff0000', $html);
    }

    public function testADividerRefusesAnUnknownLineStyleOrColour(): void
    {
        $html = $this->render([
            'type' => 'divider',
            'props' => ['lineStyle' => 'url(javascript:alert(1))', 'color' => 'red;background:url(//evil)'],
        ]);

        $this->assertStringContainsString('border-top:1px solid currentColor', $html);
        $this->assertStringNotContainsString('evil', $html);
    }

    /* ----------------------------------------------------------- escaping -- */

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function nodesCarryingAPayload(): array
    {
        $payload = '"><script>alert(1)</script>';

        return [
            'text' => [['type' => 'text', 'props' => ['text' => $payload]]],
            'list item' => [['type' => 'list', 'props' => ['items' => [$payload]]]],
            'quote body' => [['type' => 'quote', 'props' => ['text' => $payload]]],
            'quote attribution' => [['type' => 'quote', 'props' => ['text' => 'x', 'attribution' => $payload]]],
            'quote source' => [['type' => 'quote', 'props' => ['text' => 'x', 'attribution' => 'y', 'source' => $payload]]],
            'button label' => [['type' => 'button', 'props' => ['label' => $payload]]],
            'image alt' => [['type' => 'image', 'props' => ['src' => '/a.png', 'alt' => $payload]]],
            'video label' => [['type' => 'video', 'props' => ['src' => '/a.mp4', 'label' => $payload]]],
            'video captions label' => [['type' => 'video', 'props' => ['src' => '/a.mp4', 'captions' => '/a.vtt', 'captionsLabel' => $payload]]],
            'style value' => [['type' => 'section', 'props' => [], 'styles' => ['color' => $payload]]],
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('nodesCarryingAPayload')]
    public function testAPropCarryingMarkupIsEscaped(array $node): void
    {
        $html = $this->render($node);

        $this->assertStringNotContainsString('<script>', $html);
        // The quote is escaped too, so it cannot close an attribute early and
        // start one of its own.
        $this->assertStringNotContainsString('"><script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAHostileButtonHrefIsNeutralised(): void
    {
        $html = $this->render(['type' => 'button', 'props' => ['label' => 'Click', 'href' => 'javascript:alert(1)']]);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="#"', $html);
    }

    public function testAStyleValueCannotAppendItsOwnDeclarations(): void
    {
        $html = $this->render(['type' => 'section', 'styles' => ['color' => 'red;position:fixed;inset:0']]);

        $this->assertStringContainsString('color:redposition:fixedinset:0', $html);
    }

    /* -------------------------------------------------------- unknown type -- */

    public function testAnUnknownNodeTypeIsSkippedWithoutBreakingThePage(): void
    {
        $page = Content::create(ContentKey::page('landing'), [
            'title' => 'Landing',
            'builder' => [
                'root' => 'r',
                'nodes' => [
                    'r' => ['type' => 'section', 'children' => ['before', 'weird', 'after']],
                    'before' => ['type' => 'text', 'props' => ['text' => 'Before']],
                    'weird' => ['type' => 'carousel-3000', 'children' => [], 'props' => ['text' => 'Unknown']],
                    'after' => ['type' => 'text', 'props' => ['text' => 'After']],
                ],
            ],
        ]);

        $html = (string) $this->plugin()->hook_web_render(['page' => $page, 'preview' => false]);

        // The neighbours still publish; only the unrecognised node is dropped.
        $this->assertStringContainsString('Before', $html);
        $this->assertStringContainsString('After', $html);
        $this->assertStringNotContainsString('data-node-id="weird"', $html);
    }

    public function testAMissingChildIsSkipped(): void
    {
        $page = Content::create(ContentKey::page('landing'), [
            'title' => 'Landing',
            'builder' => [
                'root' => 'r',
                'nodes' => [
                    'r' => ['type' => 'section', 'children' => ['ghost', 'real']],
                    'real' => ['type' => 'text', 'props' => ['text' => 'Still here']],
                ],
            ],
        ]);

        $html = (string) $this->plugin()->hook_web_render(['page' => $page, 'preview' => false]);

        $this->assertStringContainsString('Still here', $html);
    }

    /* --------------------------------------------------- node id retention -- */

    public function testEveryNewTypeKeepsItsNodeIdForTheResponsiveCss(): void
    {
        $cases = [
            ['type' => 'columns', 'children' => [], 'props' => ['count' => 2]],
            ['type' => 'column', 'children' => []],
            ['type' => 'video', 'props' => ['src' => '/a.mp4']],
            ['type' => 'embed', 'props' => ['url' => 'https://vimeo.com/123456789']],
            ['type' => 'embed', 'props' => ['url' => 'https://unknown.example/x']],
            ['type' => 'list', 'props' => ['items' => ['a']]],
            ['type' => 'quote', 'props' => ['text' => 'a']],
            ['type' => 'divider'],
        ];

        foreach ($cases as $node) {
            // Without data-node-id the per-breakpoint rules key on nothing and
            // the whole responsive mechanism silently stops applying.
            $this->assertStringContainsString(
                'data-node-id="n"',
                $this->render($node),
                $node['type'] . ' must carry its node id'
            );
        }
    }
}
