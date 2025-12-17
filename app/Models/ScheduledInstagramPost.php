<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledInstagramPost extends Model
{
    protected $fillable = [
        'perfil_id','message','image_urls','scheduled_at','status','error',
    ];
    protected $casts = [
        'image_urls' => 'array',
        'scheduled_at' => 'datetime',
    ];

    public function perfil() {
        return $this->belongsTo(PerfilModel::class, 'perfil_id');
    }
}
