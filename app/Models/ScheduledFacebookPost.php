<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledFacebookPost extends Model
{
    protected $fillable = [
        'perfil_id',
        'message',
        'image_sources',
        'scheduled_at',
        'scheduled_epoch',
        'client_tz',
        'status',
        'fb_post_id',
        'last_error',
    ];

    protected $casts = [
        'image_sources' => 'array',
        'scheduled_at'  => 'datetime',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(\App\Models\PerfilModel::class, 'perfil_id');
    }
}