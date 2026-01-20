<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MozDomainSnapshot extends Model
{
    protected $table = 'moz_domain_snapshots';

    protected $fillable = [
        'id_dominio','target','pulled_at','payload','status','error_message'
    ];

    protected $casts = [
        'pulled_at' => 'datetime',
        'payload' => 'array',
    ];
}