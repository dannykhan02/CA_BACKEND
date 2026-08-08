<?php

namespace App\Services\AI;

use App\Models\AiPrompt;

class PromptManager
{
    /**
     * Resolve the currently active prompt for a given name. Thin wrapper
     * around AiPrompt::active() — kept here rather than calling the model
     * directly from AnthropicClient so callers depend on this service's
     * interface, not on Eloquent specifics.
     */
    public function resolve(string $name): AiPrompt
    {
        return AiPrompt::active($name);
    }

    /**
     * Renders a prompt template, replacing {{key}} placeholders with the
     * given $vars (keys should include the braces, e.g. '{{document_text}}').
     *
     * Validates in both directions:
     *  1. Every placeholder the template requires must be present in $vars —
     *     throws before rendering if not, since a missing var means we'd
     *     otherwise send Claude a prompt with a literal unresolved token.
     *  2. After rendering, no {{...}}-shaped token may remain — catches the
     *     case where the template references a placeholder that isn't in
     *     the known/expected set at all (a typo in the template itself).
     */
    public function render(AiPrompt $prompt, array $vars): string
    {
        preg_match_all('/\{\{(\w+)\}\}/', $prompt->template, $matches);
        $required = array_unique($matches[1]);

        foreach ($required as $placeholder) {
            if (! array_key_exists('{{' . $placeholder . '}}', $vars)) {
                throw new \RuntimeException(
                    "Prompt '{$prompt->name}' v{$prompt->version} missing required variable: {$placeholder}"
                );
            }
        }

        $rendered = strtr($prompt->template, $vars);

        if (preg_match('/\{\{\w+\}\}/', $rendered)) {
            throw new \RuntimeException(
                "Prompt '{$prompt->name}' v{$prompt->version} rendered with unresolved placeholder(s) — check for a typo in the template or \$vars."
            );
        }

        return $rendered;
    }
}