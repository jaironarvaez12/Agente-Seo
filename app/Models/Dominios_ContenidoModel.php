<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Dominios_ContenidoModel extends Model
{
    use HasFactory;
    protected $table = 'dominios_contenido';
    protected $primaryKey = 'id_dominio_contenido';

    protected $fillable = [
        'id_dominio_contenido',
        'id_dominio',
        'tipo',
    	'palabras_claves',
    	'estatus',
    

    ];
 protected $dateFormat = 'Y-m-d H:i:s'; //funcion para formateo de la fecha



 public static function Dominios($IdDominio)
    {
        return DB::table('dominios_contenido as dc')
            ->join('dominios as d', 'd.id_dominio', '=', 'dc.id_dominio')
                ->select(
                   
                    'dc.id_dominio',
                    'dc.id_dominio_contenido',
                    'dc.tipo',
                    'dc.estatus',
                    'dc.palabras_claves',
                  
                    'd.nombre',
                    'd.estatus',
                    DB::raw("
                        CASE 
                            WHEN dc.palabras_claves IS NULL OR TRIM(dc.palabras_claves) = '' THEN 0
                            ELSE (LENGTH(dc.palabras_claves) - LENGTH(REPLACE(dc.palabras_claves, ',', '')) + 1)
                        END as total_palabras_clave
                    ")

                    )
                ->where('dc.id_dominio', '=', $IdDominio)
                ->get();
    }
}
