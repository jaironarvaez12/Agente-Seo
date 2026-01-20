<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MozDailyMetric extends Model
{
    protected $table = 'moz_daily_metrics';

    protected $fillable = [
        'id_dominio',
        'date',
        'target',
        'backlinks_total',
        'ref_domains_total',
        'domain_authority',
        'page_authority',
        'spam_score',
        'raw',
    ];

    protected $casts = [
        'raw' => 'array',
        'date' => 'date',
    ];
}
