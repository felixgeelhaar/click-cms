<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Migration;

use Click\Cms\Application\Migration\WordPressWxrInspector;
use PHPUnit\Framework\TestCase;

final class WordPressWxrInspectorTest extends TestCase
{
    public function testReportsSupported_pages_and_posts_without_modifying_source(): void
    {
        $xml = $this->fixture();
        $before = hash('sha256', $xml);
        $report = (new WordPressWxrInspector())->inspect($xml);

        self::assertSame(2, $report['itemCount']);
        self::assertSame(['page' => 1, 'post' => 1], $report['typeCounts']);
        self::assertSame(['draft' => 1, 'publish' => 1], $report['statuses']);
        self::assertSame('hello-world', $report['items'][0]['slug']);
        self::assertSame('<p>Welcome</p>', $report['items'][0]['content']);
        self::assertSame('A short intro', $report['items'][0]['excerpt']);
        self::assertSame(['old-home'], $report['items'][0]['redirectCandidates']);
        self::assertSame('Example post', $report['items'][1]['title']);
        self::assertSame($before, hash('sha256', $xml));
        self::assertFalse($report['writesPerformed']);
    }

    public function test_flags_unsupported_records_and_duplicate_slugs_for_operator_review(): void
    {
        $report = (new WordPressWxrInspector())->inspect($this->fixture(true));

        self::assertSame(['attachment' => 1, 'page' => 2, 'post' => 1], $report['typeCounts']);
        self::assertSame(['hello-world'], $report['duplicateSlugs']);
        self::assertSame(['attachment'], $report['unsupportedTypes']);
        self::assertContains('unsupported_type', array_column($report['warnings'], 'type'));
        self::assertContains('redirect_candidate', array_column($report['warnings'], 'type'));
        self::assertSame('https://old.example/uploads/photo.jpg', $report['items'][2]['attachmentUrl']);
        self::assertSame(['old-home'], $report['items'][0]['redirectCandidates']);
    }

    public function test_rejects_malformed_or_non_wordpress_xml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WordPressWxrInspector())->inspect('<not-wxr/>');
    }

    private function fixture(bool $withDuplicates = false): string
    {
        $page = '<item><title>Example page</title><wp:post_type>page</wp:post_type><wp:post_status>publish</wp:post_status><wp:post_name>hello-world</wp:post_name><wp:post_id>2</wp:post_id><wp:postmeta><wp:meta_key>_wp_old_slug</wp:meta_key><wp:meta_value>old-home</wp:meta_value></wp:postmeta></item>';
        $post = '<item><title>Example post</title><wp:post_type>post</wp:post_type><wp:post_status>draft</wp:post_status><wp:post_name>story</wp:post_name><wp:post_id>3</wp:post_id></item>';
        $extra = $withDuplicates
            ? '<item><title>Media</title><wp:post_type>attachment</wp:post_type><wp:post_status>inherit</wp:post_status><wp:post_name>photo</wp:post_name><wp:attachment_url>https://old.example/uploads/photo.jpg</wp:attachment_url></item>'
                . '<item><title>Another page</title><wp:post_type>page</wp:post_type><wp:post_status>publish</wp:post_status><wp:post_name>hello-world</wp:post_name></item>'
            : '';

        return '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"><channel>'
            . str_replace('</item>', '<content:encoded><![CDATA[<p>Welcome</p>]]></content:encoded><excerpt:encoded><![CDATA[A short intro]]></excerpt:encoded></item>', $page)
            . $post . $extra . '</channel></rss>';
    }
}
