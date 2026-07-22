<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Menu;

use InvalidArgumentException;

/**
 * A named navigation menu — "main", "footer" — holding an ordered list of items.
 *
 * A menu is stored as an ordinary content document of type `menu`, so it
 * inherits storage, backups, history and everything else content already has.
 * The identity ({@see id()}) is the document's slug, which is why it is held to
 * the same slug shape a filename can safely carry.
 *
 * The value the domain adds over a raw array is the guarantee in {@see MenuItem}:
 * every item's target has been validated, so a menu that exists cannot contain a
 * `javascript:` link. Callers that build a nav from {@see items()} are therefore
 * rendering strings that have already been proven safe to place in an `href`.
 */
final class Menu
{
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @param list<MenuItem> $items
     */
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $items,
    ) {}

    /**
     * @param list<MenuItem> $items
     */
    public static function create(string $id, string $name = '', array $items = []): self
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(
                "Menu id \"{$id}\" must be a slug (lowercase letters, digits and hyphens)."
            );
        }

        // The name is a human label; a menu with none is named after itself
        // rather than left blank.
        $name = trim($name);
        if ($name === '') {
            $name = $id;
        }

        foreach ($items as $item) {
            if (!$item instanceof MenuItem) {
                throw new InvalidArgumentException('Menu items must be MenuItem instances.');
            }
        }

        return new self($id, $name, array_values($items));
    }

    /**
     * Rebuild from stored form: `{ id, name, items: [ { label, target, children } ] }`.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';

        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (is_array($item)) {
                    $items[] = MenuItem::fromArray($item);
                }
            }
        }

        return self::create($id, $name, $items);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<MenuItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'items' => array_map(
                static fn (MenuItem $item): array => $item->toArray(),
                $this->items
            ),
        ];
    }
}
