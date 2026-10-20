<?php

namespace App\Services\Intelligence;

use DomainException;

/**
 * Resolves one cited character span to the structural nodes it covers.
 *
 * A model quotes what it was shown, and what it was shown is the extractor's
 * flattened projection: XLSX joins a row's cells with " | " and its rows with
 * "\n" (`SpreadsheetTextExtractor.php:159,180`), DOCX ends each cell with
 * " | " and each row with "\n" (`DocumentTextExtractor.php:512,531,533`). A
 * quote of "one cell" therefore routinely carries the separators of the empty
 * cells beside it, and a quote of "one row" carries the separators between its
 * cells. Those characters belong to the rendering, not to the document, so a
 * span is still the same evidence as the nodes under it.
 *
 * The rule, and nothing wider than it: every node the span touches must be
 * covered **whole**, and everything the span covers outside those nodes must
 * be separator or whitespace characters. One real character of document text
 * outside a node, or one node cut in half, and the span is not those nodes —
 * the caller must then refuse rather than guess.
 */
class StructuralSpanOverlap
{
    /**
     * Separator and whitespace characters only: the " | " joins, the row and
     * page breaks ("\n", "\f"), and the spaces around them. Never a letter,
     * a digit or punctuation, so no document text can pass as a separator.
     */
    private const SEPARATORS = '/^[\s|\x{000C}]*$/u';

    /** A citation covering more cells than this is reported, not silently clipped. */
    public const MAX_NODES = 64;

    /**
     * @param  list<array<string, mixed>>  $candidates  nodes of one comparable kind, each with int start/end and string text
     * @return list<array<string, mixed>>|null the covered nodes in document order, or null when the span is not those nodes
     *
     * @throws DomainException SOURCE_TOO_COMPLEX past MAX_NODES; SOURCE_UNAVAILABLE if a node's own projection has moved
     */
    public function cover(array $candidates, int $start, int $end, string $text): ?array
    {
        if ($end <= $start) {
            return null;
        }
        $covered = [];
        foreach ($candidates as $node) {
            $from = $node['start'] ?? null;
            $to = $node['end'] ?? null;
            if (! is_int($from) || ! is_int($to) || $to <= $from || $to <= $start || $from >= $end) {
                continue;
            }
            if ($from < $start || $to > $end) {
                return null; // The span cuts this node: it is not a citation of it.
            }
            $covered[] = $node;
        }
        if ($covered === []) {
            return null;
        }
        if (count($covered) > self::MAX_NODES) {
            throw new DomainException('SOURCE_TOO_COMPLEX');
        }
        usort($covered, static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);

        $cursor = $start;
        foreach ($covered as $node) {
            if ($node['start'] < $cursor) {
                return null; // Overlapping candidates cannot both own the same characters.
            }
            if (! $this->separatorsOnly(mb_substr($text, $cursor, $node['start'] - $cursor))) {
                return null;
            }
            if (! is_string($node['text'] ?? null)
                || mb_substr($text, $node['start'], $node['end'] - $node['start']) !== $node['text']) {
                throw new DomainException('SOURCE_UNAVAILABLE');
            }
            $cursor = $node['end'];
        }

        return $this->separatorsOnly(mb_substr($text, $cursor, $end - $cursor)) ? $covered : null;
    }

    private function separatorsOnly(string $between): bool
    {
        return $between === '' || preg_match(self::SEPARATORS, $between) === 1;
    }
}
