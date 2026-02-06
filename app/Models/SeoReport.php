<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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





    public static function ReportesGenerados($IdUsuario)
    {   
        return DB::table('dominios_usuarios as du')
            ->join('reportes as r', 'r.id_dominio', '=', 'du.id_dominio')
            ->where('du.id_usuario', '=', $IdUsuario)
            ->where('r.status', '=', 'ok')
            ->count();
    }
}