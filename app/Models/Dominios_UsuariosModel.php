<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Dominios_UsuariosModel extends Model
{
     use HasFactory;
    protected $table = 'dominios_usuarios';
    protected $primaryKey = 'id_dominio_usuario';

    protected $fillable = [
        'id_dominio_usuario',
        'id_dominio',
        'id_usuario',
    	'fecha_creacion',
    	'creado_por',
    

    ];
 protected $dateFormat = 'Y-m-d H:i:s'; //funcion para formateo de la fecha


  public static function Dominios($IdUsuario)
    {
        return DB::table('dominios_usuarios as du')
            ->join('dominios as d', 'd.id_dominio', '=', 'du.id_dominio')
                ->select(
                   
                    'du.id_dominio',
                    'du.id_dominio_usuario',
                    'd.url',
                    'd.nombre',
                    'd.color',
                    'd.imagen',
                    'd.direccion',
                    'd.estatus'
                    )
                ->where('du.id_usuario', '=', $IdUsuario)
                ->get();
    }
}



 