<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** A deliberately small, versioned ProseMirror subset. Never render this JSON as HTML. */
class RichNoteContent
{
    public const MAX_BYTES = 131072;

    public const MAX_NODES = 2000;

    public const MAX_DEPTH = 16;

    public const MAX_LIST_DEPTH = 6;

    public const MAX_TEXT = 20000;

    private int $nodes = 0;

    private int $characters = 0;

    private array $lines = [];

    private array $references = [];

    /** @return array{body:string,references:array<string,bool>} */
    public function validate(array $document): array
    {
        $encoded = json_encode($document);
        $this->require($encoded !== false && strlen($encoded) <= self::MAX_BYTES);
        $this->nodes = $this->characters = 0;
        $this->lines = $this->references = [];
        $this->walk($document, 'root', 1, 0);
        $body = trim(implode("\n", $this->lines));
        $this->require($body !== '' && mb_strlen($body) <= self::MAX_TEXT);

        return ['body' => $body, 'references' => $this->references];
    }

    private function walk(mixed $node, string $parent, int $depth, int $listDepth): string
    {
        $this->require(is_array($node) && ! array_is_list($node) && $depth <= self::MAX_DEPTH);
        $this->require(++$this->nodes <= self::MAX_NODES);
        $type = $node['type'] ?? null;
        $allowed = [
            'root' => ['doc'],
            'doc' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'taskList', 'blockquote', 'codeBlock', 'horizontalRule', 'callout'],
            'paragraph' => ['text', 'citationRef'], 'heading' => ['text', 'citationRef'],
            'bulletList' => ['listItem'], 'orderedList' => ['listItem'], 'taskList' => ['taskItem'],
            'listItem' => ['paragraph', 'bulletList', 'orderedList', 'taskList'],
            'taskItem' => ['paragraph', 'bulletList', 'orderedList', 'taskList'],
            'blockquote' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'taskList'],
            'callout' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'taskList'],
            'codeBlock' => ['text'],
        ];
        $this->require(is_string($type) && in_array($type, $allowed[$parent] ?? [], true));
        $keys = match ($type) {
            'text' => ['type', 'text', 'marks'],
            'citationRef', 'heading', 'paragraph', 'orderedList', 'taskItem', 'callout' => ['type', 'attrs', 'content'],
            default => ['type', 'content'],
        };
        $this->onlyKeys($node, $keys);
        $attrs = $node['attrs'] ?? [];
        $this->require(is_array($attrs) && ($attrs === [] || ! array_is_list($attrs)));
        $this->attributes($type, $attrs);

        if ($type === 'text') {
            $this->require(isset($node['text']) && is_string($node['text']) && $node['text'] !== '' && ! array_key_exists('content', $node));
            $this->marks($node['marks'] ?? [], $parent === 'codeBlock');
            $this->characters += mb_strlen($node['text']);
            $this->require($this->characters <= self::MAX_TEXT);

            return $node['text'];
        }
        if ($type === 'citationRef') {
            $this->require(! array_key_exists('content', $node));
            $this->references[$attrs['citationId']] = true;

            return '[citation]';
        }
        if ($type === 'horizontalRule') {
            $this->require(! array_key_exists('content', $node));

            return '';
        }
        $children = $node['content'] ?? null;
        $this->require(is_array($children) && array_is_list($children));
        $this->require($children !== [] || in_array($type, ['paragraph', 'codeBlock'], true));
        $nextListDepth = $listDepth + (in_array($type, ['bulletList', 'orderedList', 'taskList'], true) ? 1 : 0);
        $this->require($nextListDepth <= self::MAX_LIST_DEPTH);
        $parts = [];
        foreach ($children as $child) {
            $parts[] = $this->walk($child, $type, $depth + 1, $nextListDepth);
        }
        $value = implode(in_array($type, ['paragraph', 'heading', 'codeBlock'], true) ? '' : "\n", $parts);
        if (in_array($type, ['paragraph', 'heading', 'codeBlock'], true)) {
            $this->lines[] = $value;
        }

        return $value;
    }

    private function attributes(string $type, array $attrs): void
    {
        $allowed = match ($type) {
            'paragraph' => ['textAlign'], 'heading' => ['level', 'textAlign'],
            'orderedList' => ['start'], 'taskItem' => ['checked'],
            'callout' => ['kind'], 'citationRef' => ['citationId'], default => [],
        };
        $this->onlyKeys($attrs, $allowed);
        $this->require(match ($type) {
            'heading' => isset($attrs['level']) && is_int($attrs['level']) && in_array($attrs['level'], [1, 2, 3], true),
            'orderedList' => ! isset($attrs['start']) || (is_int($attrs['start']) && $attrs['start'] >= 1 && $attrs['start'] <= 100000),
            'taskItem' => isset($attrs['checked']) && is_bool($attrs['checked']),
            'callout' => isset($attrs['kind']) && in_array($attrs['kind'], ['info', 'tip', 'warning'], true),
            'citationRef' => isset($attrs['citationId']) && is_string($attrs['citationId'])
                && (Str::isUuid($attrs['citationId'])
                    || (str_starts_with($attrs['citationId'], 'draft:') && Str::isUuid(substr($attrs['citationId'], 6)))),
            default => true,
        });
        if (isset($attrs['textAlign'])) {
            $this->require(in_array($attrs['textAlign'], ['left', 'center', 'right', 'justify'], true));
        }
    }

    private function marks(mixed $marks, bool $inCode): void
    {
        $this->require(is_array($marks) && array_is_list($marks) && count($marks) <= 8 && (! $inCode || $marks === []));
        $seen = [];
        foreach ($marks as $mark) {
            $this->require(is_array($mark) && ! array_is_list($mark));
            $type = $mark['type'] ?? null;
            $this->require(is_string($type) && in_array($type, ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight'], true) && ! isset($seen[$type]));
            $seen[$type] = true;
            $this->onlyKeys($mark, ['type', 'attrs']);
            $attrs = $mark['attrs'] ?? [];
            $this->require(is_array($attrs) && ($attrs === [] || ! array_is_list($attrs)));
            $this->onlyKeys($attrs, match ($type) {
                'link' => ['href', 'title'], 'highlight' => ['color'], default => []
            });
            if ($type === 'link') {
                $url = $attrs['href'] ?? null;
                $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
                $this->require(is_string($url) && strlen($url) <= 2048 && ! preg_match('/[\x00-\x20\x7f]/', $url)
                    && in_array($scheme, ['https', 'http', 'mailto'], true)
                    && ($scheme === 'mailto'
                        ? filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false
                        : filter_var($url, FILTER_VALIDATE_URL) !== false));
                if (isset($attrs['title'])) {
                    $this->require(is_string($attrs['title']) && mb_strlen($attrs['title']) <= 200);
                }
            }
            if ($type === 'highlight') {
                $this->require(isset($attrs['color']) && in_array($attrs['color'], ['yellow', 'green', 'blue', 'pink'], true));
            }
        }
    }

    private function onlyKeys(array $value, array $allowed): void
    {
        $this->require(array_diff(array_keys($value), $allowed) === []);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('Invalid rich note content.');
        }
    }
}
