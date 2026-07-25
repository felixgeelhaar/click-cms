<?php

declare(strict_types=1);

namespace Click\Cms\Application\Seed;

use Click\Cms\Domain\Menu\Menu;
use Click\Cms\Domain\Menu\MenuItem;

/**
 * The example site: content, and nothing else.
 *
 * A fresh install has an admin account and no content at all, which is the
 * worst possible first impression — every screen is an empty list, and nothing
 * demonstrates what a section, a collection or a menu actually is. This is the
 * site that fills those screens.
 *
 * It is deliberately a *whole* small site rather than a scattering of samples:
 * every shipped section type appears at least once, both shipped collection
 * types have entries, the posts reference the team members as authors, and the
 * menu links the pages together. Someone deleting it all afterwards has still
 * seen how the pieces fit.
 *
 * ## Why this is data and not code that writes
 *
 * Nothing here touches storage. The seeder takes this definition and puts it
 * through the same {@see \Click\Cms\Application\Content\PageService} and
 * {@see \Click\Cms\Application\Collection\CollectionService} the admin UI posts
 * to, so seeded content is validated by the rules real content is validated by.
 * That makes this file a standing check on the shipped schemas: if a section
 * schema changes in a way that breaks its own example, the seeder's test fails.
 *
 * ## Media references
 *
 * Image fields hold a media id, which is generated at upload time and therefore
 * cannot be written here. Instead they hold a token — `@media/workshop` — which
 * the seeder swaps for the real id once the picture exists. The tokens are
 * matched whole, so a token can never be confused with an id a site already has.
 */
final class ExampleSite
{
    /** The prefix marking a value as "resolve this to a media id". */
    public const MEDIA_TOKEN_PREFIX = '@media/';

    /**
     * The pictures, as SVG source.
     *
     * SVG rather than a raster format because it costs a few hundred bytes
     * instead of a few hundred kilobytes, needs no image extension to produce,
     * and is text — so the repository carries no binaries and a reviewer can
     * read what is being seeded. They pass through the same sanitiser every
     * uploaded SVG does; seeding is therefore also a live check that legitimate
     * SVG survives it.
     *
     * @return array<string, array{name: string, alt: string, svg: string}>
     */
    public static function media(): array
    {
        return [
            'workshop' => [
                'name' => 'workshop.svg',
                'alt' => 'The workshop floor, with benches under high windows.',
                'svg' => self::panel('#2f3d34', '#8fae94', 'Workshop', 1200, 800),
            ],
            'tables' => [
                'name' => 'tables.svg',
                'alt' => 'A finished oak dining table.',
                'svg' => self::panel('#4a3b2a', '#c8a97e', 'Tables', 720, 540),
            ],
            'seating' => [
                'name' => 'seating.svg',
                'alt' => 'A spindle-backed chair in ash.',
                'svg' => self::panel('#3a3630', '#b9a88f', 'Seating', 720, 540),
            ],
            'shelving' => [
                'name' => 'shelving.svg',
                'alt' => 'Wall-mounted shelving in walnut.',
                'svg' => self::panel('#33302c', '#a89275', 'Shelving', 720, 540),
            ],
            'portrait-mara' => [
                'name' => 'mara.svg',
                'alt' => 'Mara Ellis in the workshop.',
                'svg' => self::panel('#2b3a44', '#8ba7b5', 'ME', 480, 480),
            ],
            'portrait-tobias' => [
                'name' => 'tobias.svg',
                'alt' => 'Tobias Lind at the bench.',
                'svg' => self::panel('#3d2f38', '#ab8fa2', 'TL', 480, 480),
            ],
            'portrait-jun' => [
                'name' => 'jun.svg',
                'alt' => 'Jun Park sharpening a plane iron.',
                'svg' => self::panel('#2f3a2b', '#9bb08c', 'JP', 480, 480),
            ],
        ];
    }

    /**
     * Team members, seeded before the posts because a post's author field is a
     * reference to one of them and a reference to nothing is not much of a
     * demonstration.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function teamMembers(): array
    {
        return [
            'mara-ellis' => [
                'name' => 'Mara Ellis',
                'role' => 'Founder and lead maker',
                'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-mara',
                'bio' => 'Started the workshop in 2009 after a decade restoring '
                    . 'church furniture. Works mostly in oak and elm.',
            ],
            'tobias-lind' => [
                'name' => 'Tobias Lind',
                'role' => 'Cabinetmaker',
                'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-tobias',
                'bio' => 'Joined in 2014. Responsible for most of the casework '
                    . 'and every drawer that has ever closed properly.',
            ],
            'jun-park' => [
                'name' => 'Jun Park',
                'role' => 'Finisher',
                'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-jun',
                'bio' => 'Mixes the shop\'s oil finishes by hand and keeps a '
                    . 'notebook of every batch going back six years.',
            ],
        ];
    }

    /**
     * Blog posts. `author` and `relatedPosts` are reference fields, so these
     * show a reference resolving to an entry of another type and to entries of
     * the same type — the two shapes an editor will meet.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function posts(): array
    {
        return [
            'why-we-stopped-staining' => [
                'title' => 'Why we stopped staining',
                'author' => 'jun-park',
                'date' => '2025-03-18',
                'excerpt' => 'Stain hides the grain it is meant to flatter. '
                    . 'Here is what we use instead, and why it costs us more.',
                'coverImage' => self::MEDIA_TOKEN_PREFIX . 'workshop',
                'body' => '<p>A stain sits on the surface and colours it evenly, '
                    . 'which is precisely the problem: an even colour is the one '
                    . 'thing a piece of oak is not.</p>'
                    . '<p>We now finish everything with a hard-wax oil we mix in '
                    . 'the shop. It takes three days longer and costs roughly '
                    . 'four times as much per square metre. The grain stays '
                    . 'legible, and a scratch can be repaired in place rather '
                    . 'than sent back to us.</p>',
            ],
            'a-table-that-outlives-you' => [
                'title' => 'A table that outlives you',
                'author' => 'mara-ellis',
                'date' => '2025-05-02',
                'excerpt' => 'Solid tops move with the seasons. Designing for '
                    . 'that movement is most of the job.',
                'coverImage' => self::MEDIA_TOKEN_PREFIX . 'tables',
                'body' => '<p>A one-metre oak top can change width by five '
                    . 'millimetres between February and August. Screw it rigidly '
                    . 'to a frame and it will split — not immediately, but '
                    . 'certainly.</p>'
                    . '<p>Buttons, slotted cleats and figure-eight fasteners all '
                    . 'solve it. We use buttons, cut from offcuts of the same '
                    . 'board, because they are the only method that can be '
                    . 'repaired with a chisel by someone who has never met '
                    . 'us.</p>',
                'relatedPosts' => ['why-we-stopped-staining'],
            ],
        ];
    }

    /**
     * The pages, in menu order. Between them they use every section type in
     * `config/sections`.
     *
     * Sections carry their fields under `values`, beside the `type`, because
     * that is the shape `PageService` validates and the admin UI posts. Keeping
     * the fixture in the wire shape is what lets it double as a worked example
     * of the page API.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        return [
            'home' => [
                'title' => 'Rivet & Oak',
                'sections' => [
                    [
                        'type' => 'media-text',
                        'values' => [
                            'heading' => 'Furniture made to be repaired',
                            'body' => '<p>We are a workshop of twelve on the edge of '
                                . 'the harbour. Everything we make is solid timber, '
                                . 'jointed rather than glued flat, and drawn so that '
                                . 'the parts that wear can be replaced by someone '
                                . 'other than us.</p>',
                            'image' => self::MEDIA_TOKEN_PREFIX . 'workshop',
                            'alt' => 'The workshop floor, with benches under high windows.',
                            'imagePosition' => 'left',
                        ],
                    ],
                    [
                        'type' => 'facts',
                        'values' => [
                            'heading' => 'The shop in numbers',
                            'items' => [
                                ['value' => '2009', 'caption' => 'Founded'],
                                ['value' => '12', 'caption' => 'Makers'],
                                ['value' => '6 weeks', 'caption' => 'Typical lead time'],
                                ['value' => '25 years', 'caption' => 'Structural guarantee'],
                            ],
                        ],
                    ],
                    [
                        'type' => 'card-grid',
                        'values' => [
                            'heading' => 'What we make',
                            'intro' => '<p>Three lines, each available in oak, ash, '
                                . 'elm or walnut.</p>',
                            'columns' => '3',
                            'cards' => [
                                [
                                    'title' => 'Tables',
                                    'body' => 'Dining, writing and worktables from '
                                        . '1.2 to 3.6 metres. Solid tops on buttoned '
                                        . 'frames.',
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'tables',
                                ],
                                [
                                    'title' => 'Seating',
                                    'body' => 'Spindle-backed chairs and benches, '
                                        . 'steam-bent in the shop.',
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'seating',
                                ],
                                [
                                    'title' => 'Shelving',
                                    'body' => 'Wall-mounted and freestanding, cut to '
                                        . 'the wall you actually have.',
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'shelving',
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'quote',
                        'values' => [
                            'quote' => 'They took the measurements of a room that is '
                                . 'not square and did not once suggest we change the '
                                . 'room. The table has been in daily use for four years.',
                            'attribution' => 'Amara Ndiaye',
                            'role' => 'Head of Facilities, Northgate Practice',
                        ],
                    ],
                    [
                        'type' => 'logos',
                        'values' => [
                            'heading' => 'Accredited by',
                            'columns' => '4',
                            'logos' => [
                                [
                                    'logo' => self::MEDIA_TOKEN_PREFIX . 'shelving',
                                    'title' => 'Guild of Master Craftsmen',
                                ],
                                [
                                    'logo' => self::MEDIA_TOKEN_PREFIX . 'tables',
                                    'title' => 'FSC Chain of Custody',
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'call-to-action',
                        'values' => [
                            'heading' => 'Come and see the timber',
                            'body' => 'The rack is open on Saturdays. Bring the '
                                . 'measurements of the room.',
                            'buttonLabel' => 'Book a visit',
                            'buttonUrl' => '/contact',
                        ],
                    ],
                ],
            ],
            'about' => [
                'title' => 'About the workshop',
                'sections' => [
                    [
                        'type' => 'rich-text',
                        'values' => [
                            'heading' => 'Fifteen years on the same floor',
                            'body' => '<p>Rivet &amp; Oak began in 2009 as one bench '
                                . 'in a shared unit. We took the whole building in '
                                . '2016 and have not moved since, which is the only '
                                . 'reason we can still get to a piece we made in our '
                                . 'first year.</p>'
                                . '<p>We do not run a showroom. What we have is a '
                                . 'timber rack, a bench you can sit on, and whatever '
                                . 'happens to be in the finishing room that week.</p>',
                            'width' => 'narrow',
                        ],
                    ],
                    [
                        'type' => 'media-text',
                        'values' => [
                            'heading' => 'How a commission runs',
                            'body' => '<p>A drawing and a fixed price before anything '
                                . 'is cut. Half on acceptance, half on delivery. If '
                                . 'the timber we planned to use turns out wrong when '
                                . 'we open the board, we tell you before we '
                                . 'continue.</p>',
                            'image' => self::MEDIA_TOKEN_PREFIX . 'shelving',
                            'alt' => 'Wall-mounted shelving in walnut.',
                            'imagePosition' => 'right',
                        ],
                    ],
                    [
                        'type' => 'section-heading',
                        'values' => [
                            'heading' => 'The people at the benches',
                            'intro' => '<p>Twelve of us. These three answer most of '
                                . 'what arrives by email.</p>',
                        ],
                    ],
                    [
                        'type' => 'people',
                        'values' => [
                            'columns' => '3',
                            'people' => [
                                [
                                    'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-mara',
                                    'title' => 'Mara Ellis',
                                    'role' => 'Founder and lead maker',
                                    'bio' => 'Works mostly in oak and elm.',
                                ],
                                [
                                    'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-tobias',
                                    'title' => 'Tobias Lind',
                                    'role' => 'Cabinetmaker',
                                    'bio' => 'Every drawer that has ever closed properly.',
                                ],
                                [
                                    'photo' => self::MEDIA_TOKEN_PREFIX . 'portrait-jun',
                                    'title' => 'Jun Park',
                                    'role' => 'Finisher',
                                    'bio' => 'Mixes the shop oil finishes by hand.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'gallery',
                        'values' => [
                            'heading' => 'Work leaving the shop',
                            'columns' => '3',
                            'images' => [
                                [
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'tables',
                                    'caption' => 'Oak dining table, 2.4 metres.',
                                ],
                                [
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'seating',
                                    'caption' => 'Spindle-backed chair in ash.',
                                ],
                                [
                                    'image' => self::MEDIA_TOKEN_PREFIX . 'shelving',
                                    'caption' => 'Wall-mounted shelving in walnut.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'journal' => [
                'title' => 'Journal',
                'sections' => [
                    [
                        'type' => 'rich-text',
                        'values' => [
                            'heading' => 'Notes from the bench',
                            'body' => '<p>Occasional writing about materials, '
                                . 'methods and the things we got wrong. The entries '
                                . 'themselves are blog post entries — edit them under '
                                . '<em>Blog posts</em> rather than here.</p>',
                            'width' => 'narrow',
                        ],
                    ],
                    [
                        // The section that closes the oldest hole in the product:
                        // before this existed, publishing a post put it nowhere a
                        // visitor could reach it.
                        'type' => 'collection-list',
                        'values' => [
                            'heading' => 'Entries',
                            'collection' => 'post',
                            'limit' => 6,
                            'sort' => 'newest',
                        ],
                    ],
                ],
            ],
            'contact' => [
                'title' => 'Contact',
                'sections' => [
                    [
                        'type' => 'rich-text',
                        'values' => [
                            'heading' => 'Where to find us',
                            'body' => '<p>Unit 4, North Quay. Open Tuesday to '
                                . 'Saturday, 9 to 5. Parking is on the quay '
                                . 'itself.</p>',
                            'width' => 'narrow',
                        ],
                    ],
                    [
                        'type' => 'form',
                        'values' => [
                            'heading' => 'Tell us about the piece',
                            'intro' => 'Rough dimensions and the room it is going in '
                                . 'are enough to start with.',
                            'nameLabel' => 'Your name',
                            'emailLabel' => 'Email address',
                            'messageLabel' => 'What are you after?',
                            'submitLabel' => 'Send enquiry',
                            'confirmation' => 'Thank you — we answer enquiries within '
                                . 'two working days.',
                            'destinationNote' => 'Submissions appear under Form '
                                . 'submissions in the admin.',
                        ],
                    ],
                    [
                        'type' => 'details',
                        'values' => [
                            'heading' => 'Opening hours',
                            'rows' => [
                                ['label' => 'Monday', 'value' => 'Closed'],
                                ['label' => 'Tuesday to Friday', 'value' => '9:00 – 17:00'],
                                ['label' => 'Saturday', 'value' => '10:00 – 16:00'],
                            ],
                        ],
                    ],
                ],
            ],
            // A fifth page, added with the pricing and question designs: they
            // belong together and neither reads well bolted onto an existing page.
            'prices' => [
                'title' => 'Prices',
                'sections' => [
                    [
                        'type' => 'section-heading',
                        'values' => [
                            'heading' => 'What it costs, and how long it takes',
                            'intro' => '<p>Every figure below is a price we have '
                                . 'actually charged. None of them is an estimate '
                                . 'that grows.</p>',
                        ],
                    ],
                    [
                        'type' => 'pricing',
                        'values' => [
                            'heading' => 'Ways to work with us',
                            'columns' => '3',
                            'plans' => [
                                [
                                    'title' => 'Repair',
                                    'price' => 'from £180',
                                    'summary' => 'For a piece with one thing wrong with it.',
                                    'features' => "Collection and return\nA written report before we start\nTwo-year guarantee on the repair",
                                ],
                                [
                                    'title' => 'From the range',
                                    'price' => 'from £900',
                                    'summary' => 'One of our own designs, in the timber you choose.',
                                    'features' => "Six-week lead time\nFour timbers to choose from\n25-year structural guarantee",
                                ],
                                [
                                    'title' => 'Commission',
                                    'price' => 'from £2,400',
                                    'summary' => 'Drawn for your room, and only your room.',
                                    'features' => "A drawing and a fixed price first\nHalf on acceptance, half on delivery\n25-year structural guarantee",
                                ],
                            ],
                            'buttonLabel' => 'Ask for a quote',
                            'buttonUrl' => '/contact',
                        ],
                    ],
                    [
                        'type' => 'faq',
                        'values' => [
                            'heading' => 'Questions we are asked most',
                            'items' => [
                                [
                                    'title' => 'How long does a commission take?',
                                    'answer' => '<p>Six weeks from the day you accept '
                                        . 'the drawing, and we tell you in week one '
                                        . 'if that is going to slip.</p>',
                                ],
                                [
                                    'title' => 'Can you match an existing piece?',
                                    'answer' => '<p>Usually. Send a photograph and '
                                        . 'the measurements of the piece you already '
                                        . 'have before anything else.</p>',
                                ],
                                [
                                    'title' => 'Do you deliver?',
                                    'answer' => '<p>Within fifty miles, included in '
                                        . 'the price. Beyond that we quote it '
                                        . 'separately and it is never a surprise.</p>',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The main menu, as the domain model — not as a stored array.
     *
     * The first version of this hand-wrote the document, and used `href` for
     * each item's destination because that is what the menus *API* returns. What
     * is *stored* is `target`; `href` is the resolved form the controller
     * computes on the way out. The seeded menu was therefore a menu with no
     * destinations, and every page 500'd the moment the header tried to render
     * it — which no unit test noticed, because none of them rendered a header.
     *
     * Building it through `Menu` is what makes that class of mistake impossible:
     * an item with no target now throws here, at seed time, rather than on a
     * visitor's request.
     */
    public static function menu(): Menu
    {
        // Bare slugs, not paths: an internal target is a slug (optionally with a
        // locale prefix), and the controller turns it into an href on the way
        // out. `/about` is rejected outright, which is how this was found.
        return Menu::create('main', 'Main menu', [
            MenuItem::create('Home', 'home'),
            MenuItem::create('About', 'about'),
            MenuItem::create('Prices', 'prices'),
            MenuItem::create('Journal', 'journal'),
            MenuItem::create('Contact', 'contact'),
        ]);
    }

    /**
     * A flat two-tone panel with a caption.
     *
     * Deliberately abstract. A seeded picture that tried to look like a real
     * photograph would be mistaken for one, and someone would ship it.
     */
    private static function panel(
        string $dark,
        string $light,
        string $caption,
        int $width,
        int $height
    ): string {
        $fontSize = (int) round(min($width, $height) / 7);

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}">
              <title>{$caption}</title>
              <defs>
                <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="{$dark}"/>
                  <stop offset="1" stop-color="{$light}"/>
                </linearGradient>
              </defs>
              <rect width="{$width}" height="{$height}" fill="url(#g)"/>
              <text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle"
                    font-family="Georgia, serif" font-size="{$fontSize}" fill="#ffffff"
                    fill-opacity="0.85">{$caption}</text>
            </svg>
            SVG;
    }
}
