<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Version 1 is a flat document of paragraphs/lists and bold/italic text only. */
class AnnouncementContent
{
    public static function plainText(array $document): string
    {
        $invalid = static function (): never {
            throw ValidationException::withMessages(['description_document' => 'Use paragraphs, bold, italic, and simple lists only (maximum 10,000 characters).']);
        };
        $keys = static fn (array $value, array $allowed): bool => array_diff(array_keys($value), $allowed) === [];
        if (! $keys($document, ['version', 'blocks']) || ($document['version'] ?? null) !== 1
            || ! is_array($document['blocks'] ?? null) || ! array_is_list($document['blocks'])
            || count($document['blocks']) > 100 || strlen(json_encode($document)) > 200000) {
            $invalid();
        }
        $runs = 0;
        $contentText = '';
        $inline = static function (mixed $content) use (&$runs, &$contentText, $keys, $invalid): string {
            if (! is_array($content) || ! array_is_list($content)) {
                $invalid();
            }
            $text = '';
            foreach ($content as $run) {
                if (++$runs > 1000 || ! is_array($run) || ! $keys($run, ['text', 'bold', 'italic']) || ! is_string($run['text'] ?? null)) {
                    $invalid();
                }
                foreach (['bold', 'italic'] as $mark) {
                    if (array_key_exists($mark, $run) && ! is_bool($run[$mark])) {
                        $invalid();
                    }
                }
                $text .= $run['text'];
                $contentText .= $run['text'];
            }

            return $text;
        };
        $blocks = [];
        $items = 0;
        foreach ($document['blocks'] as $block) {
            if (! is_array($block)) {
                $invalid();
            }
            if (($block['type'] ?? null) === 'paragraph' && $keys($block, ['type', 'content'])) {
                $blocks[] = $inline($block['content'] ?? null);
            } elseif (in_array($block['type'] ?? null, ['bullet_list', 'ordered_list'], true) && $keys($block, ['type', 'items'])
                && is_array($block['items'] ?? null) && array_is_list($block['items']) && count($block['items']) > 0) {
                $lines = [];
                foreach ($block['items'] as $index => $content) {
                    if (++$items > 100) {
                        $invalid();
                    }
                    $lines[] = ($block['type'] === 'ordered_list' ? ($index + 1).'. ' : '• ').$inline($content);
                }
                $blocks[] = implode("\n", $lines);
            } else {
                $invalid();
            }
        }
        $text = implode("\n\n", $blocks);
        $visible = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $contentText);
        if (mb_strlen($text) > 10000 || $visible === '') {
            $invalid();
        }

        return $text;
    }
}
