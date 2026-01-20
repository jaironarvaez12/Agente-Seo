<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoReport extends Model
{
    protected $table = 'seo_reports';

    protected $fillable = [
        'id','id_dominio','period_start','period_end','status','error_message'
    ];

    public function sections()
    {
        return $this->hasMany(SeoReportSection::class, 'seo_report_id');
    }
}