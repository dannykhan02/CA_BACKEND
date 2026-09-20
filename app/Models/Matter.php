<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Matter extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'created_by', 'name', 'description', 'type'];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
