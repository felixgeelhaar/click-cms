<?php

declare(strict_types=1);

namespace Click\Cms\Application\Migration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use libxml;

/** Read-only inventory of a WordPress eXtended RSS (WXR) export. */
final class WordPressWxrInspector
{
    /**
     * @return array{
     *   itemCount:int, typeCounts:array<string,int>, statuses:array<string,int>,
     *   items:list<array{type:string,status:string,slug:string,title:string,content:string,excerpt:string,id:?string,supported:bool,attachmentUrl:string,redirectCandidates:list<string>}>,
     *   duplicateSlugs:list<string>, unsupportedTypes:list<string>, warnings:list<array<string,string>>,
     *   writesPerformed:false
     * }
     */
    public function inspect(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            if (!$loaded || $document->documentElement?->localName !== 'rss') {
                throw new InvalidArgumentException('The file is not a valid WordPress WXR export.');
            }
            $xpath = new DOMXPath($document);
            $itemNodes = $xpath->query('//*[local-name()="channel"]/*[local-name()="item"]');
            if ($itemNodes === false || $itemNodes->length === 0) {
                throw new InvalidArgumentException('The WXR export contains no channel items.');
            }

            $items = [];
            $types = [];
            $statuses = [];
            $slugUses = [];
            $warnings = [];
            foreach ($itemNodes as $node) {
                if (!$node instanceof DOMElement) continue;
                $type = $this->childText($node, 'post_type') ?: 'post';
                $status = $this->childText($node, 'post_status') ?: 'publish';
                $slug = $this->childText($node, 'post_name');
                $title = $this->childText($node, 'title');
                // WXR places body and excerpt in distinct namespaces. Match on
                // local name and namespace prefix-independent values below.
                $content = $this->childTextByNamespace($node, 'content', 'encoded');
                $excerpt = $this->childTextByNamespace($node, 'excerpt', 'encoded');
                $id = $this->childText($node, 'post_id');
                $attachmentUrl = $this->childText($node, 'attachment_url');
                $redirectCandidates = $this->oldSlugCandidates($node);
                $supported = in_array($type, ['page', 'post'], true);
                $types[$type] = ($types[$type] ?? 0) + 1;
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                if ($supported && $slug !== '') $slugUses[$slug] = ($slugUses[$slug] ?? 0) + 1;
                $items[] = [
                    'type' => $type,
                    'status' => $status,
                    'slug' => $slug,
                    'title' => $title,
                    'content' => $content,
                    'excerpt' => $excerpt,
                    'id' => $id !== '' ? $id : null,
                    'supported' => $supported,
                    'attachmentUrl' => $attachmentUrl,
                    'redirectCandidates' => $redirectCandidates,
                ];
                if (!$supported) {
                    $warnings[] = ['type' => 'unsupported_type', 'message' => "WordPress content type '{$type}' needs a custom mapping."];
                } elseif (!in_array($status, ['publish', 'draft', 'private', 'pending', 'future'], true)) {
                    $warnings[] = ['type' => 'unsupported_status', 'message' => "Status '{$status}' on '{$slug}' needs a publication decision."];
                }
                if ($type === 'attachment' && $attachmentUrl !== '') {
                    $warnings[] = ['type' => 'attachment_not_imported', 'message' => "Attachment '{$slug}' was not downloaded; source URL: {$attachmentUrl}"];
                }
                if ($redirectCandidates !== []) {
                    $warnings[] = ['type' => 'redirect_candidate', 'message' => "Legacy paths for '{$slug}' need manual redirect setup: " . implode(', ', $redirectCandidates)];
                }
            }

            $duplicates = array_keys(array_filter($slugUses, static fn (int $count): bool => $count > 1));
            foreach ($duplicates as $slug) {
                $warnings[] = ['type' => 'duplicate_slug', 'message' => "More than one page or post uses slug '{$slug}'."];
            }
            ksort($types);
            ksort($statuses);
            $unsupportedTypes = array_values(array_filter(array_keys($types), static fn (string $type): bool => !in_array($type, ['page', 'post'], true)));

            return [
                'itemCount' => count($items),
                'typeCounts' => $types,
                'statuses' => $statuses,
                'items' => $items,
                'duplicateSlugs' => array_values($duplicates),
                'unsupportedTypes' => $unsupportedTypes,
                'warnings' => $warnings,
                'writesPerformed' => false,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function childText(DOMElement $parent, string $localName): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    private function childTextByNamespace(DOMElement $parent, string $namespace, string $localName): string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName
                && $child->namespaceURI === match ($namespace) {
                    'content' => 'http://purl.org/rss/1.0/modules/content/',
                    'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
                    default => '',
                }) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    /** @return list<string> */
    private function oldSlugCandidates(DOMElement $item): array
    {
        $slugs = [];
        foreach ($item->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'postmeta') continue;
            $key = $this->childText($child, 'meta_key');
            if ($key !== '_wp_old_slug') continue;
            $slug = $this->childText($child, 'meta_value');
            if ($slug !== '' && !in_array($slug, $slugs, true)) $slugs[] = $slug;
        }
        return $slugs;
    }
}
