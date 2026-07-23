<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * The field types the editor UI knows how to render.
 *
 * Deliberately a small, closed set. Every type here must have a corresponding
 * input in the admin UI, so adding one is a decision about the product rather
 * than a per-site convenience — sites express themselves by composing these,
 * not by inventing new ones.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case RichText = 'richtext';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Image = 'image';
    case File = 'file';
    case Url = 'url';
    case Email = 'email';
    case Date = 'date';
    /** A repeating group of sub-fields, e.g. rows in a table or cards in a grid. */
    case Repeater = 'repeater';

    // A pointer to another content item — a collection entry or a page — stored
    // as that item's slug. It is what lets a post name its author or a page list
    // its featured entries, turning separate collections into a connected model.
    case Reference = 'reference';

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }

    /** Repeaters nest; every other type holds a scalar. */
    public function isContainer(): bool
    {
        return $this === self::Repeater;
    }

    /** Types whose value is free-form prose the site will render as HTML. */
    public function isProse(): bool
    {
        return $this === self::RichText;
    }
}
