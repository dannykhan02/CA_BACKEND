<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceCredit extends Model
{
    protected $fillable = ['workspace_id', 'documents_remaining', 'documents_purchased_total'];

    protected $casts = [
        'documents_remaining' => 'integer',
        'documents_purchased_total' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
