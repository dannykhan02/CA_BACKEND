<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrialGrant extends Model
{
    protected $fillable = ['user_id', 'email', 'ip_address', 'fingerprint', 'workspace_id', 'granted_at'];

    protected $casts = ['granted_at' => 'datetime'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
