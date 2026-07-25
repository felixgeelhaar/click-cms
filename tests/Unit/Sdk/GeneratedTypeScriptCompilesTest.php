<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Sdk;

use ClickCms\Tools\TypeScript\TypeScriptClientGenerator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/generate-ts-client.php';

/**
 * The check every code generator needs and most do not have: that what it emits
 * actually compiles.
 *
 * Assertions on the emitted text prove the right words are present. They cannot
 * prove the file parses, that a nested interface was declared before it was
 * referenced, or that the hand-written client still agrees with the generated
 * types after either side changed. A generator whose output does not compile
 * fails silently — nobody reads generated code, so it surfaces days later as an
 * unexplained broken build in somebody else's project.
 *
 * So this compiles the real thing: schemas -> generated types -> the shipped
 * client -> a fixture front end that uses both the way a front end would. The
 * fixture deliberately includes `@ts-expect-error` lines, which fail the compile
 * if they *stop* erroring — that is what catches a generator quietly widening
 * everything to `any`, which would compile beautifully and check nothing.
 *
 * TypeScript is a development dependency of the SDK package, not of this PHP
 * project, so the test skips cleanly when it is not installed rather than making
 * an offline `phpunit` run fail.
 */
final class GeneratedTypeScriptCompilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-tsc-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config/sections', 0o775, true);
        mkdir($this->dir . '/config/collections', 0o775, true);
        mkdir($this->dir . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    public function testTheGeneratedTypesAndTheClientCompileTogether(): void
    {
        $tsc = $this->typescriptCompiler();
        if ($tsc === null) {
            $this->markTestSkipped(
                'No TypeScript compiler available. Run `npm install` in sdk/typescript to enable this check.'
            );
        }

        $this->writeSchemas();
        $this->writeProject();

        (new TypeScriptClientGenerator())->generate($this->dir . '/config', $this->dir . '/src');

        $output = [];
        $status = 0;
        exec(
            'cd ' . escapeshellarg($this->dir) . ' && ' . $tsc . ' --noEmit -p tsconfig.json 2>&1',
            $output,
            $status
        );

        $this->assertSame(
            0,
            $status,
            "The generated TypeScript does not compile:\n" . implode("\n", $output)
        );
    }

    /**
     * The compiler to use, or null when there is none.
     *
     * Resolved by path first, because macOS ships an unrelated `/usr/bin/tsc`
     * (part of TeX) that `npx` will happily run — it exits 0 and compiles
     * nothing, which would turn this test into one that always passes.
     */
    private function typescriptCompiler(): ?string
    {
        $root = dirname(__DIR__, 3);

        $candidates = [
            escapeshellarg($root . '/sdk/typescript/node_modules/.bin/tsc'),
            escapeshellarg($root . '/node_modules/.bin/tsc'),
            'npx --no-install tsc',
        ];

        foreach ($candidates as $candidate) {
            $output = [];
            $status = 0;
            exec($candidate . ' --version 2>/dev/null', $output, $status);

            // Only the real compiler answers "Version 5.4.5". Anything else is
            // something wearing the same name.
            if ($status === 0 && preg_match('/Version \d+\.\d+/', implode(' ', $output)) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A synthetic site rather than the repository's own config, so the check is
     * deterministic and exercises every mapping the generator makes — including
     * a field type it has never heard of.
     */
    private function writeSchemas(): void
    {
        $this->write('config/sections/rich-text.json', [
            'label' => 'Rich text',
            'fields' => [
                ['name' => 'heading', 'type' => 'text'],
                ['name' => 'body', 'type' => 'richtext', 'required' => true],
                ['name' => 'width', 'type' => 'select', 'options' => ['narrow', 'wide', 'full']],
            ],
        ]);

        $this->write('config/sections/gallery.json', [
            'label' => 'Gallery',
            'fields' => [
                ['name' => 'cover', 'type' => 'image', 'required' => true],
                ['name' => 'items', 'type' => 'repeater', 'fields' => [
                    ['name' => 'caption', 'type' => 'text', 'required' => true],
                    ['name' => 'photo', 'type' => 'image'],
                ]],
            ],
        ]);

        $this->write('config/collections/post.json', [
            'label' => 'Blog posts',
            'titleField' => 'title',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'required' => true],
                ['name' => 'author', 'type' => 'reference', 'references' => 'team-member'],
                ['name' => 'related', 'type' => 'reference', 'references' => 'post', 'multiple' => true],
                ['name' => 'rating', 'type' => 'number'],
                ['name' => 'featured', 'type' => 'boolean'],
                ['name' => 'publishedOn', 'type' => 'date'],
                // Nothing the generator knows: it must widen this without
                // breaking the interface around it.
                ['name' => 'palette', 'type' => 'colour-picker'],
            ],
        ]);

        $this->write('config/collections/team-member.json', [
            'label' => 'Team members',
            'titleField' => 'name',
            'fields' => [['name' => 'name', 'type' => 'text', 'required' => true]],
        ]);
    }

    /** @param array<string, mixed> $spec */
    private function write(string $relative, array $spec): void
    {
        file_put_contents($this->dir . '/' . $relative, json_encode($spec, JSON_THROW_ON_ERROR));
    }

    /**
     * The shipped client, copied verbatim, plus a fixture that uses it. Copying
     * rather than re-implementing is the point: this fails if the client and the
     * generator ever disagree about a name.
     */
    private function writeProject(): void
    {
        $sdk = dirname(__DIR__, 3) . '/sdk/typescript';

        copy($sdk . '/src/client.ts', $this->dir . '/src/client.ts');
        copy($sdk . '/src/index.ts', $this->dir . '/src/index.ts');

        // NodeNext resolution reads this to decide the module system, and the
        // package it is standing in for is ESM.
        file_put_contents($this->dir . '/package.json', json_encode([
            'name' => 'click-cms-typecheck-fixture',
            'private' => true,
            'type' => 'module',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        // The SDK's own compiler settings, so passing here means passing there.
        copy($sdk . '/tsconfig.json', $this->dir . '/tsconfig.json');

        file_put_contents($this->dir . '/src/usage.ts', $this->fixture());
    }

    /**
     * A front end, in miniature: render a page's sections through the union,
     * resolve an image, read a typed collection entry, handle an error.
     */
    private function fixture(): string
    {
        return <<<'TS'
        // A stand-in for a real front end, compiled to prove the generated types
        // are usable and not merely syntactically valid.
        import { ClickCmsError, createClient } from './client.js';
        import type { MediaMap, PostFields, Section } from './types.js';

        const client = createClient({ baseUrl: 'https://cms.example', locale: 'en' });

        // The whole promise of the discriminated union: `section.values` narrows to
        // the fields of the design being rendered, and nothing else.
        function renderSection(section: Section, media: MediaMap | undefined): string {
          switch (section.type) {
            case 'rich-text': {
              const body: string = section.values.body;
              const heading: string | undefined = section.values.heading;
              const width: 'narrow' | 'wide' | 'full' | undefined = section.values.width;
              // @ts-expect-error a section has only the fields its schema declares
              section.values.notDeclared;
              return `${heading ?? ''}${body}${width ?? 'narrow'}`;
            }
            case 'gallery': {
              const src = client.mediaUrl(media, section.values.cover, { width: 640 });
              const srcset = client.srcset(media, section.values.cover);
              const captions = (section.values.items ?? []).map((item) => item.caption);
              // @ts-expect-error `cover` is required here, so it is not on rich-text
              const wrong: string = section.values.body;
              return [src ?? '', srcset ?? '', wrong, ...captions].join(' ');
            }
            default: {
              // Exhaustive: adding a section type to the CMS breaks this line until
              // the front end handles it, which is the point of generating at all.
              const unreachable: never = section;
              return String(unreachable);
            }
          }
        }

        export async function renderPage(slug: string): Promise<string> {
          const page = await client.getPage(slug);
          if (page === null) {
            return 'not found';
          }

          const alt: string | null | undefined = client.media(page.media, 'anything-12345678')?.alt;

          return page.data.data.sections
            .map((section) => renderSection(section, page.media))
            .join('')
            .concat(alt ?? '');
        }

        export async function renderPosts(): Promise<string[]> {
          const posts = await client.listEntries('post', {
            limit: 10,
            offset: 0,
            filter: { featured: true },
          });

          if (posts === null) {
            return [];
          }

          const total: number = posts.meta.total;

          return posts.data.map((entry) => {
            const fields: PostFields = entry.data;
            const rating: number | undefined = fields.rating;
            const related: string[] = fields.related ?? [];
            // An unknown field type widens rather than lying, so reading it needs
            // a check — which is exactly the safety that buys.
            const palette: unknown = fields.palette;

            return [entry.title, fields.title, String(rating ?? total), related.join(','), typeof palette].join(' ');
          });
        }

        export async function readOneEntry(): Promise<string> {
          const entry = await client.getEntry('team-member', 'ada');

          // @ts-expect-error this site declares no collection called "ghost"
          await client.getEntry('ghost', 'nobody');

          return entry?.data.name ?? '';
        }

        export async function countPages(): Promise<number> {
          try {
            const pages = await client.listPages({ limit: 5 });
            return pages?.meta.total ?? 0;
          } catch (error) {
            if (error instanceof ClickCmsError) {
              const status: number | undefined = error.status;
              return status ?? -1;
            }
            throw error;
          }
        }

        TS;
    }
}
