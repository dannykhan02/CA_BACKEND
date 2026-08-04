<?php

namespace App\Support;

trait EscapesLikeWildcards
{
    /**
     * Escapes SQL LIKE metacharacters (%, _) and the escape character itself
     * in user-supplied search input. The underlying whereRaw() calls were
     * already parameterized (not SQL-injectable), so this is a correctness
     * fix, not a security one — without it, a search term containing % or _
     * matches more/less than the user intended (audit F-Low-1).
     *
     * Portable across MySQL/Postgres/SQLite: all three support an explicit
     * ESCAPE clause on LIKE, so we don't rely on any driver's default.
     */
    protected function likeEscapeChar(): string
    {
        return chr(92); // one literal backslash — built via chr() to avoid
                         // PHP string-literal escaping ambiguity (\\ vs \\\\)
    }

    protected function escapeLike(string $value): string
    {
        $esc = $this->likeEscapeChar();
        return str_replace([$esc, '%', '_'], [$esc . $esc, $esc . '%', $esc . '_'], $value);
    }
}
