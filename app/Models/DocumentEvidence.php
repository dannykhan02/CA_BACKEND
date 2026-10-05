<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentEvidence extends Model
{
    use HasUuids;

    protected $table = 'document_evidence';

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'sources' => 'array'];
}
