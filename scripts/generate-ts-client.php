<?php

declare(strict_types=1);

namespace ClickCms\Tools\TypeScript;

use JsonException;
use RuntimeException;

/**
 * Turns a site's content schemas into TypeScript types for its front end.
 *
 * A headless front end reading the delivery API otherwise hand-writes the shape
 * of every section and every collection entry, and those hand-written types
 * drift the moment an editor's schema changes — silently, because JSON has no
 * opinion about what it is missing. Generating them from `config/sections/*.json`
 * and `config/collections/*.json` makes the schema the single source of truth:
 * add a required field and the front end stops compiling until it renders it.
 *
 * Deliberately standalone. It parses the schema JSON itself rather than booting
 * the CMS, so it runs from a checkout with no autoloader, no Composer packages
 * and no application state — a front-end developer can generate types for a site
 * they only have the config of.
 *
 * The emitted file is types only, with no imports and nothing that survives to
 * runtime, so a bundler erases it entirely.
 */
final class TypeScriptClientGenerator
{
    /** The name of the emitted file, which the hand-written client imports. */
    public const OUTPUT_FILE = 'types.ts';

    /**
     * Interface names already emitted in the current run. Two schemas can
     * reasonably want the same name ("card-grid" and "card_grid"); colliding
     * declarations would be a TypeScript error, so the second is suffixed.
     *
     * @var array<string, true>
     */
    private array $taken = [];

    /**
     * @param string $command How the file says it was produced, so a reader who
     *                        finds it in a diff knows how to reproduce it.
     */
    public function __construct(
        private readonly string $command = 'php scripts/generate-ts-client.php',
    ) {}

    /**
     * Write the type surface for the site configured at $configDir.
     *
     * @return list<string> The paths written — empty when there was no config
     *                      directory to read, which is a normal state (a caller
     *                      pointed at the wrong place, or a fresh checkout) and
     *                      not something to fail a build over.
     */
    public function generate(string $configDir, string $outDir): array
    {
        $source = $this->render($configDir);
        if ($source === null) {
            return [];
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Could not create output directory \"{$outDir}\".");
        }

        $path = rtrim($outDir, '/') . '/' . self::OUTPUT_FILE;
        if (@file_put_contents($path, $source) === false) {
            throw new RuntimeException("Could not write \"{$path}\".");
        }

        return [$path];
    }

    /**
     * The whole `types.ts` source, or null when $configDir does not exist.
     *
     * Separate from writing so the output can be asserted on without a
     * filesystem, and so a caller can diff before overwriting.
     */
    public function render(string $configDir): ?string
    {
        if (!is_dir($configDir)) {
            return null;
        }

        $this->taken = [];

        $sections = $this->loadSchemas($configDir . '/sections');
        $collections = $this->loadSchemas($configDir . '/collections');

        $parts = [
            $this->header(),
            $this->sharedTypes(),
            $this->sectionsBlock($sections),
            $this->collectionsBlock($collections),
        ];

        return implode("\n", array_filter($parts, static fn (string $p): bool => $p !== '')) . "\n";
    }

    /* ------------------------------------------------------------- loading -- */

    /**
     * Every `*.json` in a schema directory, keyed by the id the CMS derives from
     * the filename, in a stable order so regenerating an unchanged site produces
     * a byte-identical file and an empty diff.
     *
     * A file that is not valid JSON is skipped rather than fatal: one broken
     * schema must not cost a front end every other type it had.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadSchemas(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        sort($files, SORT_STRING);

        $schemas = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            try {
                $spec = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($spec)) {
                continue;
            }

            $id = basename($file, '.json');
            if ($id === '') {
                continue;
            }

            $schemas[$id] = $spec;
        }

        return $schemas;
    }

    /* ------------------------------------------------------------ emitting -- */

    private function header(): string
    {
        // No timestamp on purpose: a generated file that changes on every run
        // shows up in every diff and teaches reviewers to ignore it.
        return <<<TS
        /* eslint-disable */
        // ---------------------------------------------------------------------------
        // GENERATED FILE — do not edit.
        //
        // Produced by `{$this->command}` from this site's config/sections/*.json and
        // config/collections/*.json. Any edit here is lost the next time it runs; to
        // change a type, change the schema it was generated from.
        //
        // Types only: no imports, no runtime code, nothing left after compilation.
        // ---------------------------------------------------------------------------


        TS;
    }

    /**
     * The response shapes the delivery API always has, whatever a site declares.
     * Hand-written but emitted here rather than shipped as a second file, so the
     * generated types have nothing to import and one file is the whole contract.
     */
    private function sharedTypes(): string
    {
        return <<<'TS'
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

        TS;
    }

    /**
     * An interface per section type, plus the discriminated union over them.
     *
     * @param array<string, array<string, mixed>> $sections
     */
    private function sectionsBlock(array $sections): string
    {
        $out = "\n/* ---------------------------------------------------------------- sections -- */\n";

        if ($sections === []) {
            // A site with no section types still has to compile. A single open
            // shape is honest about knowing nothing, and does not pretend to be
            // a union anything can be narrowed out of.
            return $out . <<<'TS'

            /** This site declares no section types, so a section is only known to be one. */
            export interface Section {
              type: string;
              values: Record<string, unknown>;
            }

            export type SectionTypeId = string;

            TS;
        }

        $extra = [];
        $blocks = [];
        $union = [];
        $ids = [];

        foreach ($sections as $id => $spec) {
            $name = $this->reserve($this->pascal($id) . 'Section');
            $base = $this->pascal($id);
            $fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

            $body = $this->fieldLines($fields, $base, $extra, '    ');

            $blocks[] = $this->docComment($spec['label'] ?? null, $spec['description'] ?? null, '')
                . "export interface {$name} {\n"
                . '  type: ' . $this->literal($id) . ";\n"
                . "  values: {\n"
                . ($body === '' ? "    [field: string]: never;\n" : $body)
                . "  };\n"
                . "}\n";

            $union[] = $name;
            $ids[] = $this->literal($id);
        }

        // Nested repeater shapes first: they read as the detail behind the
        // section interfaces that use them, and TypeScript does not care.
        $out .= "\n" . implode("\n", $extra);
        $out .= ($extra === [] ? "\n" : '') . implode("\n", $blocks);

        $out .= "\n/**\n"
            . " * Every section a page can hold, discriminated on `type` — so a\n"
            . " * `switch (section.type)` narrows `section.values` to that design's fields.\n"
            . " */\n"
            . 'export type Section =' . $this->unionBody($union) . "\n";

        $out .= "\n/** The id of every section design this site declares. */\n"
            . 'export type SectionTypeId =' . $this->unionBody($ids) . "\n";

        return $out;
    }

    /**
     * An interface per collection type, plus the map that lets the client type a
     * call by the collection name it was given.
     *
     * @param array<string, array<string, mixed>> $collections
     */
    private function collectionsBlock(array $collections): string
    {
        $out = "\n/* ------------------------------------------------------------- collections -- */\n";

        if ($collections === []) {
            // An index signature keeps `client.listEntries('anything')` compiling
            // on a site that declares no collections, rather than making the name
            // parameter `never` and the whole method uncallable.
            return $out . <<<'TS'

            /** This site declares no collection types. */
            export interface CollectionData {
              [name: string]: Record<string, unknown>;
            }

            export type CollectionName = Extract<keyof CollectionData, string>;

            TS;
        }

        $extra = [];
        $blocks = [];
        $entries = [];
        $map = [];

        foreach ($collections as $id => $spec) {
            $base = $this->pascal($id);
            $name = $this->reserve($base . 'Fields');
            $fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

            $body = $this->fieldLines($fields, $base, $extra, '  ');

            $blocks[] = $this->docComment($spec['label'] ?? null, $spec['description'] ?? null, '')
                . "export interface {$name} {\n"
                . ($body === '' ? "  [field: string]: never;\n" : $body)
                . "}\n";

            $entryName = $this->reserve($base . 'Entry');
            $entries[] = "export type {$entryName} = CollectionEntry<{$name}>;\n";
            $map[] = '  ' . $this->key($id) . ": {$name};";
        }

        $out .= "\n" . implode("\n", $extra);
        $out .= ($extra === [] ? "\n" : '') . implode("\n", $blocks);
        $out .= "\n" . implode('', $entries);

        $out .= "\n/** Collection id -> the shape of one entry's `data`, for typing a call by name. */\n"
            . "export interface CollectionData {\n"
            . implode("\n", $map) . "\n"
            . "}\n\n"
            . "export type CollectionName = Extract<keyof CollectionData, string>;\n";

        return $out;
    }

    /* -------------------------------------------------------------- fields -- */

    /**
     * The property lines for a list of field definitions.
     *
     * @param array<mixed>  $fields
     * @param string        $prefix Names the interfaces a repeater produces.
     * @param list<string>  $extra  Collects those nested interfaces.
     */
    private function fieldLines(array $fields, string $prefix, array &$extra, string $indent): string
    {
        $out = '';

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $name = trim($name);

            $type = $this->tsType($field, $prefix . $this->pascal($name), $extra);

            // A field the editor may leave blank is absent from the JSON, not
            // null in it — so optional, which is what makes `strictNullChecks`
            // catch a front end reading it without checking.
            $optional = ($field['required'] ?? false) === true ? '' : '?';

            $out .= $this->docComment($field['label'] ?? null, $field['help'] ?? null, $indent);
            $out .= $indent . $this->key($name) . $optional . ': ' . $type . ";\n";
        }

        return $out;
    }

    /**
     * The TypeScript type for one declared field.
     *
     * An unrecognised type widens to `unknown` rather than guessing: a plugin can
     * introduce a field type this generator has never heard of, and emitting a
     * wrong-but-plausible `string` for it would hand a front end a type that
     * compiles and then fails at runtime. `unknown` forces the one narrowing
     * check that makes it safe.
     *
     * @param array<string, mixed> $field
     * @param list<string>         $extra
     */
    private function tsType(array $field, string $nestedPrefix, array &$extra): string
    {
        $type = strtolower(trim((string) ($field['type'] ?? '')));

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'richtext':
            case 'url':
            case 'email':
            case 'file':
            // A date is stored and delivered as a string; parsing it is the front
            // end's decision, and typing it as Date would be a lie about the JSON.
            case 'date':
                return 'string';

            case 'number':
                return 'number';

            case 'boolean':
                return 'boolean';

            case 'select':
                $options = [];
                foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                    if (is_string($option) || is_int($option)) {
                        $options[] = $this->literal((string) $option);
                    }
                }

                // Options are what a select is; without them there is nothing to
                // narrow to and a bare string is the honest answer.
                return $options === [] ? 'string' : implode(' | ', array_unique($options));

            case 'image':
                return 'MediaRef';

            case 'reference':
                // Stored as the target's slug — one, or a list when the field
                // links to many.
                return ($field['multiple'] ?? false) === true ? 'string[]' : 'string';

            case 'repeater':
                $sub = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                if ($sub === []) {
                    return 'Array<Record<string, unknown>>';
                }

                $name = $this->reserve($nestedPrefix . 'Item');
                $body = $this->fieldLines($sub, $nestedPrefix, $extra, '  ');
                $extra[] = "export interface {$name} {\n"
                    . ($body === '' ? "  [field: string]: never;\n" : $body)
                    . "}\n";

                return "{$name}[]";

            default:
                return 'unknown';
        }
    }

    /* -------------------------------------------------------------- naming -- */

    /**
     * `card-grid` -> `CardGrid`. Anything that cannot begin a TypeScript
     * identifier is prefixed, so a schema named `2020-review` still produces a
     * declarable name instead of a syntax error.
     */
    private function pascal(string $id): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $id, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = implode('', array_map(static fn (string $p): string => ucfirst($p), $parts));

        if ($name === '') {
            return 'Unnamed';
        }

        return preg_match('/^[A-Za-z]/', $name) === 1 ? $name : 'T' . $name;
    }

    /** Claim an interface name, suffixing it if this run already emitted one. */
    private function reserve(string $name): string
    {
        $candidate = $name;
        $n = 2;
        while (isset($this->taken[$candidate])) {
            $candidate = $name . $n++;
        }

        $this->taken[$candidate] = true;

        return $candidate;
    }

    /**
     * A property key, quoted when it is not a bare identifier. Field names are
     * validated upstream, but a schema is a file anyone can write and an unquoted
     * `my-field` would not parse.
     */
    private function key(string $name): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) === 1
            ? $name
            : $this->literal($name);
    }

    /** A single-quoted TypeScript string literal. */
    private function literal(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    /**
     * A JSDoc block from a schema's own wording, so the site's vocabulary reaches
     * the front end developer's editor. `*​/` is neutralised — a description
     * containing one would otherwise close the comment and take the rest of the
     * file with it.
     */
    private function docComment(mixed $label, mixed $detail, string $indent): string
    {
        $lines = [];
        foreach ([$label, $detail] as $line) {
            if (is_string($line) && trim($line) !== '') {
                $lines[] = str_replace('*/', '*\\/', trim($line));
            }
        }

        if ($lines === []) {
            return '';
        }

        if (count($lines) === 1) {
            return $indent . '/** ' . $lines[0] . " */\n";
        }

        $out = $indent . "/**\n";
        foreach ($lines as $line) {
            $out .= $indent . ' * ' . $line . "\n";
        }

        return $out . $indent . " */\n";
    }

    /**
     * A union laid out one member per line, which keeps a diff to the member that
     * actually changed when a site adds a section type.
     *
     * @param list<string> $members
     */
    private function unionBody(array $members): string
    {
        return "\n  | " . implode("\n  | ", $members) . ';';
    }
}

/**
 * Parse `--flag=value` arguments. Deliberately tiny: a generator that needs an
 * option parser as a dependency is a generator nobody can run from a checkout.
 *
 * @param list<string>          $argv
 * @param array<string, string> $defaults
 * @return array<string, string>
 */
function parseArguments(array $argv, array $defaults): array
{
    $options = $defaults;

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $m) === 1 && isset($options[$m[1]])) {
            $options[$m[1]] = $m[2];
        }
    }

    return $options;
}

// Run only when invoked directly. Required into a test, this file must define
// the generator and do nothing else.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    $argv = $_SERVER['argv'];

    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        fwrite(STDOUT, <<<TXT
        Generate a typed TypeScript client surface for this site's delivery API.

          php scripts/generate-ts-client.php [--config=config] [--out=sdk/typescript/src]

          --config  Directory holding sections/ and collections/ schemas.
          --out     Directory the generated types.ts is written to.

        TXT);
        exit(0);
    }

    $options = parseArguments($argv, [
        'config' => 'config',
        'out' => 'sdk/typescript/src',
    ]);

    $generator = new TypeScriptClientGenerator();
    $written = $generator->generate($options['config'], $options['out']);

    if ($written === []) {
        fwrite(STDERR, "No schemas found: \"{$options['config']}\" is not a directory. Nothing written.\n");
        exit(1);
    }

    foreach ($written as $path) {
        fwrite(STDOUT, "Wrote {$path}\n");
    }

    exit(0);
}
