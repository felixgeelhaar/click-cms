<?php

declare(strict_types=1);

namespace Click\Cms\Sdk;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

class ContentBuilder
{
    private array $data = [];
    private ?ContentKey $key = null;

    public function key(string $type, string $slug): self
    {
        $this->key = ContentKey::fromString("{$type}:{$slug}");
        return $this;
    }

    public function page(string $slug): self
    {
        return $this->key('page', $slug);
    }

    public function user(string $slug): self
    {
        return $this->key('user', $slug);
    }

    public function media(string $slug): self
    {
        return $this->key('media', $slug);
    }

    public function title(string $title): self
    {
        $this->data['title'] = $title;
        return $this;
    }

    public function content(string $content): self
    {
        $this->data['content'] = $content;
        return $this;
    }

    public function status(string $status): self
    {
        $this->data['status'] = $status;
        return $this;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function build(): Content
    {
        if ($this->key === null) {
            throw new \RuntimeException('Content key is required');
        }

        return Content::create($this->key, $this->data);
    }
}
