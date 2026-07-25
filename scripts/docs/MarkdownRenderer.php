<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * A Markdown renderer for this repository's documentation, and nothing more.
 *
 * It exists because `composer.json` requires PHP and nothing else, and that rule
 * does not get an exception for build tooling: a Markdown package pulled in for
 * the docs site is still a dependency somebody has to audit and upgrade. So this
 * covers exactly the constructs the docs actually use — surveyed, not guessed —
 * and refuses to grow a long tail of CommonMark corner cases nobody writes.
 *
 * ## The escaping rule, which is the whole reason to read this file
 *
 * The input is trusted repository prose, but it is prose *about HTML*: these
 * docs contain `<section>`, `-->`, `&`, shell redirects and regexes in almost
 * every paragraph. So the order is fixed and not negotiable:
 *
 *   1. `htmlspecialchars()` runs over the raw text **once, up front**.
 *   2. Every parser below then operates on the already-escaped string.
 *
 * `` ` ``, `[`, `]`, `(`, `)`, `*`, `_`, `~` and `\` all survive escaping
 * unchanged, so the structural parse is unaffected; `<`, `>`, `&` and `"` do
 * not, so it is *impossible* for this renderer to emit a tag it did not write
 * itself. The one construct that notices is the autolink `<https://…>`, which
 * arrives as `&lt;https://…&gt;` and is matched in that form.
 *
 * Inline formatting is a recursive descent over that escaped string, so it can
 * never run inside a code span or a fence: those consume their content and emit
 * it verbatim. A code span containing `**bold**` renders as four asterisks and
 * six letters, which is the only correct answer.
 *
 * @see \Click\Cms\Tests\Unit\Docs\MarkdownRendererTest
 */
final class MarkdownRenderer
{
    /**
     * A list item opener. The trailing group is deliberately greedy over the
     * rest of the line; the indentation groups are what the nesting logic reads.
     */
    private const ITEM_PATTERN = '/^([ \t]*)(?:([-*+])|(\d{1,9})([.)]))([ \t]+|$)(.*)$/';

    /** Callback that turns a Markdown link destination into a site URL. */
    private \Closure $rewriteLink;

    private Slugger $slugger;

    /** @var list<array{level: int, text: string, id: string}> */
    private array $headings = [];

    /**
     * @param (callable(string): string)|null $rewriteLink Receives the raw (but
     *        HTML-escaped) destination of a link or image and returns the href
     *        to emit. Defaults to the identity, which is what the unit tests for
     *        pure Markdown behaviour want.
     */
    public function __construct(?callable $rewriteLink = null)
    {
        $this->rewriteLink = $rewriteLink !== null
            ? \Closure::fromCallable($rewriteLink)
            : static fn (string $destination): string => $destination;
        $this->slugger = new Slugger();
    }

    public function render(string $markdown): RenderedDocument
    {
        $this->slugger = new Slugger();
        $this->headings = [];

        $lines = $this->splitLines($markdown);
        $html = $this->parseBlocks($lines);

        $title = null;
        foreach ($this->headings as $heading) {
            if ($heading['level'] === 1) {
                $title = $heading['text'];
                break;
            }
        }

        return new RenderedDocument($html, $title, $this->headings);
    }

    /** @return list<string> */
    private function splitLines(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        // Tabs in leading position would make every indent calculation a lie.
        $markdown = preg_replace_callback(
            '/^[ \t]+/m',
            static fn (array $m): string => str_replace("\t", '    ', $m[0]),
            $markdown,
        ) ?? $markdown;

        return explode("\n", $markdown);
    }

    // ------------------------------------------------------------------
    // Block level
    // ------------------------------------------------------------------

    /** @param list<string> $lines */
    private function parseBlocks(array $lines): string
    {
        $blocks = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if ($this->fenceOpener($line) !== null) {
                $blocks[] = $this->parseFence($lines, $i);
                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/', $line, $m) === 1) {
                $blocks[] = $this->renderHeading(strlen($m[1]), $m[2]);
                $i++;
                continue;
            }

            if ($this->isThematicBreak($line)) {
                $blocks[] = '<hr>';
                $i++;
                continue;
            }

            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $blocks[] = $this->parseBlockquote($lines, $i);
                continue;
            }

            if ($this->isTableStart($lines, $i)) {
                $blocks[] = $this->parseTable($lines, $i);
                continue;
            }

            if ($this->isListItem($line)) {
                $blocks[] = $this->parseList($lines, $i);
                continue;
            }

            $blocks[] = $this->parseParagraph($lines, $i);
        }

        return implode("\n", array_filter($blocks, static fn (string $b): bool => $b !== ''));
    }

    /** Returns the fence marker (a run of ` or ~) and info string, or null. */
    private function fenceOpener(string $line): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*([^`]*)$/', $line, $m) !== 1) {
            return null;
        }

        return ['marker' => $m[1], 'info' => trim($m[2])];
    }

    /** @param list<string> $lines */
    private function parseFence(array $lines, int &$i): string
    {
        $opener = $this->fenceOpener($lines[$i]);
        assert($opener !== null);
        $marker = $opener['marker'];
        $fenceChar = $marker[0];
        $minLength = strlen($marker);
        $indent = strlen($lines[$i]) - strlen(ltrim($lines[$i], ' '));
        $i++;

        $body = [];
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            if (preg_match('/^ {0,3}(' . preg_quote($fenceChar, '/') . '{' . $minLength . ',})[ \t]*$/', $line) === 1) {
                $i++;
                break;
            }
            // A fence's own indentation is stripped from its content, up to the
            // opener's depth, so a fence inside a list item is not shifted right.
            $body[] = $this->stripIndent($line, $indent);
            $i++;
        }

        $language = $opener['info'] === '' ? null : preg_split('/\s+/', $opener['info'])[0];
        $code = $this->escape(implode("\n", $body));
        $classAttribute = $language !== null
            ? ' class="language-' . $this->escape($this->slugger->safeToken($language)) . '"'
            : '';

        $caption = $language !== null
            ? '<figcaption>' . $this->escape($language) . "</figcaption>\n"
            : '';

        return "<figure class=\"code\">\n" . $caption
            . '<pre><code' . $classAttribute . '>' . $code . "</code></pre>\n</figure>";
    }

    private function stripIndent(string $line, int $indent): string
    {
        $strip = 0;
        while ($strip < $indent && isset($line[$strip]) && $line[$strip] === ' ') {
            $strip++;
        }

        return substr($line, $strip);
    }

    private function renderHeading(int $level, string $rawText): string
    {
        $inline = $this->renderInline($rawText);
        $plain = $this->plainText($inline);
        $id = $this->slugger->slug($plain);

        $this->headings[] = ['level' => $level, 'text' => $plain, 'id' => $id];

        $label = $this->escape('Link to this section: ' . $plain);

        return sprintf(
            '<h%1$d id="%2$s">%3$s <a class="anchor" href="#%2$s" aria-label="%4$s">#</a></h%1$d>',
            $level,
            $this->escape($id),
            $inline,
            $label,
        );
    }

    private function isThematicBreak(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])[ \t]*(?:\1[ \t]*){2,}$/', $line) === 1;
    }

    /** @param list<string> $lines */
    private function parseBlockquote(array $lines, int &$i): string
    {
        $inner = [];
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            if (preg_match('/^ {0,3}>[ ]?(.*)$/', $line, $m) === 1) {
                $inner[] = $m[1];
                $i++;
                continue;
            }
            // Lazy continuation: an unmarked, non-blank line still belongs.
            if (trim($line) !== '' && !$this->startsBlock($lines, $i)) {
                $inner[] = $line;
                $i++;
                continue;
            }
            break;
        }

        return "<blockquote>\n" . $this->parseBlocks($inner) . "\n</blockquote>";
    }

    /** @param list<string> $lines */
    private function isTableStart(array $lines, int $i): bool
    {
        if (preg_match('/^ {0,3}\|/', $lines[$i]) !== 1) {
            return false;
        }
        if (!isset($lines[$i + 1])) {
            return false;
        }

        return $this->isTableDelimiter($lines[$i + 1]);
    }

    private function isTableDelimiter(string $line): bool
    {
        if (trim($line) === '' || !str_contains($line, '-')) {
            return false;
        }

        return preg_match('/^ {0,3}\|?(?:[ \t]*:?-+:?[ \t]*\|)+[ \t]*:?-*:?[ \t]*\|?[ \t]*$/', $line) === 1;
    }

    /** @param list<string> $lines */
    private function parseTable(array $lines, int &$i): string
    {
        $header = $this->tableCells($lines[$i]);
        $alignments = array_map(
            static function (string $cell): ?string {
                $cell = trim($cell);
                $left = str_starts_with($cell, ':');
                $right = str_ends_with($cell, ':');
                if ($left && $right) {
                    return 'center';
                }
                if ($right) {
                    return 'right';
                }
                if ($left) {
                    return 'left';
                }

                return null;
            },
            $this->tableCells($lines[$i + 1]),
        );
        $i += 2;

        $rows = [];
        $count = count($lines);
        while ($i < $count && preg_match('/^ {0,3}\|/', $lines[$i]) === 1) {
            $rows[] = $this->tableCells($lines[$i]);
            $i++;
        }

        $html = "<div class=\"table-wrap\">\n<table>\n<thead>\n<tr>";
        foreach ($header as $index => $cell) {
            $html .= '<th' . $this->alignAttribute($alignments[$index] ?? null) . '>'
                . $this->renderInline($cell) . '</th>';
        }
        $html .= "</tr>\n</thead>\n<tbody>\n";

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $index => $cell) {
                $html .= '<td' . $this->alignAttribute($alignments[$index] ?? null) . '>'
                    . $this->renderInline($cell) . '</td>';
            }
            $html .= "</tr>\n";
        }

        return $html . "</tbody>\n</table>\n</div>";
    }

    private function alignAttribute(?string $alignment): string
    {
        return $alignment === null ? '' : ' class="align-' . $alignment . '"';
    }

    /**
     * Splits a table row on pipes, ignoring pipes inside code spans — these docs
     * put shell and regex fragments in table cells.
     *
     * @return list<string>
     */
    private function tableCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;

        $cells = [];
        $current = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '\\' && isset($line[$i + 1]) && $line[$i + 1] === '|') {
                $current .= '|';
                $i++;
                continue;
            }
            if ($char === '`') {
                $run = strspn($line, '`', $i);
                $closing = strpos($line, str_repeat('`', $run), $i + $run);
                if ($closing !== false) {
                    $current .= substr($line, $i, $closing + $run - $i);
                    $i = $closing + $run - 1;
                    continue;
                }
            }
            if ($char === '|') {
                $cells[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $cells[] = trim($current);

        return $cells;
    }

    private function isListItem(string $line): bool
    {
        if ($this->isThematicBreak($line)) {
            return false;
        }

        return preg_match(self::ITEM_PATTERN, $line, $m) === 1 && $m[5] !== '';
    }

    /**
     * Indentation-based list parsing with loose/tight detection.
     *
     * @param list<string> $lines
     */
    private function parseList(array $lines, int &$i): string
    {
        preg_match(self::ITEM_PATTERN, $lines[$i], $m);
        $baseIndent = strlen($m[1]);
        $ordered = $m[3] !== '';
        $start = $ordered ? (int) $m[3] : 1;

        /** @var list<list<string>> $items */
        $items = [];
        $current = null;
        $contentIndent = $this->contentIndent($m);
        $loose = false;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $j = $i;
                while ($j < $count && trim($lines[$j]) === '') {
                    $j++;
                }
                if ($j >= $count) {
                    $i = $j;
                    break;
                }
                $next = $lines[$j];
                $nextIsItem = $this->isListItem($next)
                    && preg_match(self::ITEM_PATTERN, $next, $nm) === 1
                    && strlen($nm[1]) >= $baseIndent
                    && strlen($nm[1]) < $contentIndent;
                if ($this->indentWidth($next) < $contentIndent && !$nextIsItem) {
                    break;
                }
                $loose = true;
                if ($current !== null) {
                    $current[] = '';
                }
                $i = $j;
                continue;
            }

            if ($this->isListItem($line)) {
                preg_match(self::ITEM_PATTERN, $line, $im);
                $itemIndent = strlen($im[1]);
                if ($itemIndent < $contentIndent) {
                    if ($itemIndent < $baseIndent) {
                        break;
                    }
                    if ($current !== null) {
                        $items[] = $current;
                    }
                    $current = [$im[6]];
                    $contentIndent = $this->contentIndent($im);
                    $i++;
                    continue;
                }
            }

            $indent = $this->indentWidth($line);
            if ($indent < $contentIndent && $indent < $baseIndent) {
                break;
            }
            if ($current === null) {
                $current = [];
            }
            $current[] = $indent >= $contentIndent
                ? $this->stripIndent($line, $contentIndent)
                : ltrim($line);
            $i++;
        }

        if ($current !== null) {
            $items[] = $current;
        }

        $tag = $ordered ? 'ol' : 'ul';
        $open = '<' . $tag . ($ordered && $start !== 1 ? ' start="' . $start . '"' : '') . '>';

        $html = $open . "\n";
        foreach ($items as $itemLines) {
            $inner = $this->parseBlocks($itemLines);
            if (!$loose) {
                $inner = preg_replace('/^<p>(.*?)<\/p>\n?/s', '$1', $inner, 1) ?? $inner;
            }
            $inner = trim($inner);
            $html .= str_contains($inner, "\n")
                ? "<li>\n" . $inner . "\n</li>\n"
                : '<li>' . $inner . "</li>\n";
        }

        return $html . '</' . $tag . '>';
    }

    /** @param array<int, string> $match */
    private function contentIndent(array $match): int
    {
        $marker = $match[2] !== '' ? $match[2] : $match[3] . $match[4];

        return strlen($match[1]) + strlen($marker) + strlen($match[5]);
    }

    private function indentWidth(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /** @param list<string> $lines */
    private function parseParagraph(array $lines, int &$i): string
    {
        $buffer = [];
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '') {
                break;
            }
            if ($buffer !== [] && $this->startsBlock($lines, $i)) {
                break;
            }
            $buffer[] = trim($line);
            $i++;
        }

        if ($buffer === []) {
            return '';
        }

        return '<p>' . $this->renderInline(implode("\n", $buffer)) . '</p>';
    }

    /** @param list<string> $lines */
    private function startsBlock(array $lines, int $i): bool
    {
        $line = $lines[$i];

        return $this->fenceOpener($line) !== null
            || preg_match('/^ {0,3}#{1,6}[ \t]/', $line) === 1
            || $this->isThematicBreak($line)
            || preg_match('/^ {0,3}>/', $line) === 1
            || $this->isListItem($line)
            || $this->isTableStart($lines, $i);
    }

    // ------------------------------------------------------------------
    // Inline level
    // ------------------------------------------------------------------

    /**
     * Escapes once, then walks the escaped string. Everything below this line
     * operates on text that can no longer produce a tag by accident.
     */
    public function renderInline(string $raw): string
    {
        return $this->inline($this->escape($raw));
    }

    private function inline(string $s): string
    {
        $out = '';
        $i = 0;
        $length = strlen($s);

        while ($i < $length) {
            $char = $s[$i];

            if ($char === '\\') {
                $escaped = $this->backslashEscape($s, $i);
                if ($escaped !== null) {
                    $out .= $escaped[0];
                    $i = $escaped[1];
                    continue;
                }
            }

            if ($char === '`') {
                $code = $this->codeSpan($s, $i);
                if ($code !== null) {
                    $out .= $code[0];
                    $i = $code[1];
                    continue;
                }
            }

            if ($char === '!' && ($s[$i + 1] ?? '') === '[') {
                $image = $this->linkOrImage($s, $i, true);
                if ($image !== null) {
                    $out .= $image[0];
                    $i = $image[1];
                    continue;
                }
            }

            if ($char === '[') {
                $link = $this->linkOrImage($s, $i, false);
                if ($link !== null) {
                    $out .= $link[0];
                    $i = $link[1];
                    continue;
                }
            }

            if ($char === '&' && substr($s, $i, 4) === '&lt;') {
                $auto = $this->autolink($s, $i);
                if ($auto !== null) {
                    $out .= $auto[0];
                    $i = $auto[1];
                    continue;
                }
            }

            if ($char === '~' && ($s[$i + 1] ?? '') === '~') {
                $strike = $this->wrapped($s, $i, '~~', 'del');
                if ($strike !== null) {
                    $out .= $strike[0];
                    $i = $strike[1];
                    continue;
                }
            }

            if ($char === '*' || $char === '_') {
                $emphasis = $this->emphasis($s, $i);
                if ($emphasis !== null) {
                    $out .= $emphasis[0];
                    $i = $emphasis[1];
                    continue;
                }
            }

            if ($char === "\n") {
                $out .= "\n";
                $i++;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /** @return array{0: string, 1: int}|null */
    private function backslashEscape(string $s, int $i): ?array
    {
        foreach (['&amp;', '&lt;', '&gt;', '&quot;'] as $entity) {
            if (substr($s, $i + 1, strlen($entity)) === $entity) {
                return [$entity, $i + 1 + strlen($entity)];
            }
        }

        $next = $s[$i + 1] ?? '';
        if ($next !== '' && strpos('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', $next) !== false) {
            return [$next, $i + 2];
        }

        return null;
    }

    /**
     * A code span. Its content is emitted verbatim — it is already escaped, and
     * no inline rule below ever sees it again. That is the property the tests
     * pin down with `` `**not bold**` `` and `` `<section>` ``.
     *
     * @return array{0: string, 1: int}|null
     */
    private function codeSpan(string $s, int $i): ?array
    {
        $run = strspn($s, '`', $i);
        $open = $i + $run;
        $needle = str_repeat('`', $run);
        $cursor = $open;

        while (true) {
            $found = strpos($s, $needle, $cursor);
            if ($found === false) {
                return null;
            }
            $foundRun = strspn($s, '`', $found);
            if ($foundRun === $run) {
                break;
            }
            $cursor = $found + $foundRun;
        }

        $content = substr($s, $open, $found - $open);
        $content = str_replace("\n", ' ', $content);
        if (strlen($content) >= 2
            && str_starts_with($content, ' ')
            && str_ends_with($content, ' ')
            && trim($content) !== ''
        ) {
            $content = substr($content, 1, -1);
        }

        return ['<code>' . $content . '</code>', $found + $run];
    }

    /** @return array{0: string, 1: int}|null */
    private function autolink(string $s, int $i): ?array
    {
        if (preg_match('/^&lt;((?:https?|mailto):[^\s]*?)&gt;/', substr($s, $i), $m) !== 1) {
            return null;
        }

        $href = $m[1];

        return ['<a href="' . $href . '">' . $href . '</a>', $i + strlen($m[0])];
    }

    /**
     * Links and images share bracket-matching, so they share a parser.
     *
     * Images are deliberately rendered as links rather than `<img>` when their
     * source is remote. The built site must make **no external requests of any
     * kind**, and the only images in these docs are shields.io badges in the
     * README; embedding them would put a third-party host in the critical path
     * of a page that is otherwise entirely self-contained. The alt text becomes
     * the link label, so nothing is hidden and nothing is fetched.
     *
     * @return array{0: string, 1: int}|null
     */
    private function linkOrImage(string $s, int $i, bool $isImage): ?array
    {
        $labelStart = $i + ($isImage ? 2 : 1);
        $labelEnd = $this->matchDelimiter($s, $labelStart, '[', ']');
        if ($labelEnd === null || ($s[$labelEnd + 1] ?? '') !== '(') {
            return null;
        }

        $destStart = $labelEnd + 2;
        $destEnd = $this->matchDelimiter($s, $destStart, '(', ')');
        if ($destEnd === null) {
            return null;
        }

        $label = substr($s, $labelStart, $labelEnd - $labelStart);
        $destination = trim(substr($s, $destStart, $destEnd - $destStart));

        $title = null;
        if (preg_match('/^(\S+)\s+&quot;(.*)&quot;$/', $destination, $m) === 1) {
            $destination = $m[1];
            $title = $m[2];
        }
        $destination = trim($destination, '<>');

        $href = ($this->rewriteLink)($destination);
        $titleAttribute = $title !== null && $title !== '' ? ' title="' . $title . '"' : '';

        if ($isImage) {
            $text = $this->plainText($this->inline($label));
            $text = $text === '' ? $href : $text;

            return [
                '<a class="badge" href="' . $href . '"' . $titleAttribute . '>' . $this->escape($text) . '</a>',
                $destEnd + 1,
            ];
        }

        $external = preg_match('#^(?:https?:)?//#i', $href) === 1;
        $class = $external ? ' class="external"' : '';

        return [
            '<a' . $class . ' href="' . $href . '"' . $titleAttribute . '>' . $this->inline($label) . '</a>',
            $destEnd + 1,
        ];
    }

    /** @return array{0: string, 1: int}|null */
    private function emphasis(string $s, int $i): ?array
    {
        $char = $s[$i];
        $run = strspn($s, $char, $i);
        $width = $run >= 2 ? 2 : 1;
        $delimiter = str_repeat($char, $width);
        $tag = $width === 2 ? 'strong' : 'em';

        $start = $i + $width;
        if (!isset($s[$start]) || $this->isSpace($s[$start])) {
            return null;
        }

        // `_` must not fire inside a word: snake_case identifiers are prose here.
        if ($char === '_' && $i > 0 && !$this->isBoundary($s[$i - 1])) {
            return null;
        }

        $close = $this->findDelimiter($s, $start, $delimiter);
        if ($close === null || $close === $start || $this->isSpace($s[$close - 1])) {
            return null;
        }

        if ($char === '_') {
            $after = $s[$close + $width] ?? '';
            if ($after !== '' && !$this->isBoundary($after)) {
                return null;
            }
        }

        $inner = substr($s, $start, $close - $start);

        return ['<' . $tag . '>' . $this->inline($inner) . '</' . $tag . '>', $close + $width];
    }

    /** @return array{0: string, 1: int}|null */
    private function wrapped(string $s, int $i, string $delimiter, string $tag): ?array
    {
        $start = $i + strlen($delimiter);
        if (!isset($s[$start]) || $this->isSpace($s[$start])) {
            return null;
        }

        $close = $this->findDelimiter($s, $start, $delimiter);
        if ($close === null || $close === $start) {
            return null;
        }

        $inner = substr($s, $start, $close - $start);

        return ['<' . $tag . '>' . $this->inline($inner) . '</' . $tag . '>', $close + strlen($delimiter)];
    }

    /** Finds a closing delimiter, stepping over code spans and escapes. */
    private function findDelimiter(string $s, int $from, string $delimiter): ?int
    {
        $needleChar = $delimiter[0];
        $width = strlen($delimiter);
        $i = $from;
        $length = strlen($s);

        while ($i < $length) {
            $char = $s[$i];

            if ($char === '\\') {
                $i += 2;
                continue;
            }

            if ($char === '`') {
                $span = $this->codeSpan($s, $i);
                if ($span !== null) {
                    $i = $span[1];
                    continue;
                }
            }

            if ($char === $needleChar) {
                $run = strspn($s, $needleChar, $i);
                if ($run === $width) {
                    return $i;
                }
                $i += $run;
                continue;
            }

            $i++;
        }

        return null;
    }

    /** Finds the matching closer for a nesting pair, skipping code spans. */
    private function matchDelimiter(string $s, int $from, string $open, string $close): ?int
    {
        $depth = 1;
        $i = $from;
        $length = strlen($s);

        while ($i < $length) {
            $char = $s[$i];

            if ($char === '\\') {
                $i += 2;
                continue;
            }

            if ($char === '`') {
                $span = $this->codeSpan($s, $i);
                if ($span !== null) {
                    $i = $span[1];
                    continue;
                }
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }

            $i++;
        }

        return null;
    }

    private function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n";
    }

    private function isBoundary(string $char): bool
    {
        return $this->isSpace($char)
            || strpos('!"#$%&\'()*+,-./:;<=>?@[\\]^`{|}~', $char) !== false;
    }

    /** Strips tags from already-rendered inline HTML, for ids and alt text. */
    private function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * `ENT_COMPAT` on purpose: `&`, `<`, `>` and `"` are escaped, apostrophes are
     * not. Every attribute this renderer writes is double-quoted, so a bare `'`
     * cannot escape one, and leaving it alone keeps the generated HTML readable
     * for prose that is full of possessives.
     */
    public function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
