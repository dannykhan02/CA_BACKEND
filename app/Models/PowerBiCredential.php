<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PowerBiCredential extends Model
{
    protected $table = 'powerbi_credentials';
    protected $fillable = ['db_role', 'workspace_id', 'label', 'revoked_at'];
    protected $casts = [
        'revoked_at' => 'datetime',
    ];
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}