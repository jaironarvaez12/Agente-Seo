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
}
