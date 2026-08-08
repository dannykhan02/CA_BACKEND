<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AiPrompt extends Model
{
    protected $fillable = [
        'name',
        'version',
        'provider',
        'model',
        'temperature',
        'system_prompt',
        'template',
        'active',
    ];

    protected $casts = [
        'version' => 'integer',
        'temperature' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * Resolve the currently active prompt for a given name. Throws if none
     * is active — callers (PromptManager) should let this propagate rather
     * than silently falling back, since a missing active prompt means the
     * AI pipeline has nothing to send and must fail loudly, not guess.
     */
    public static function active(string $name): self
    {
        return static::where('name', $name)->where('active', true)->firstOrFail();
    }

    /**
     * The only sanctioned way to change which version is active for a given
     * prompt name. Deactivates every other version inside the same
     * transaction before activating the target — this is belt-and-suspenders
     * against the DB's partial unique index (ai_prompts_one_active_per_name),
     * not a replacement for it. If this method is bypassed (raw update,
     * Tinker, a future migration), the DB constraint is still the backstop.
     */
    public static function activate(string $name, int $version): self
    {
        return DB::transaction(function () use ($name, $version) {
            static::where('name', $name)->update(['active' => false]);

            $prompt = static::where('name', $name)->where('version', $version)->firstOrFail();
            $prompt->update(['active' => true]);

            return $prompt;
        });
    }
}