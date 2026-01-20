<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoReportSection extends Model
{
    protected $table = 'seo_report_sections';

    protected $fillable = [
        'seo_report_id','section','status','error_message','payload'
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}