<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacklinksRun extends Model
{
    protected $table = 'backlinks_runs';
    protected $primaryKey = 'id_backlink_run';

    protected $fillable = [
        'id_dominio',
        'id_dominio_contenido_detalle',
        'estatus',
        'respuesta',
        'error',
    ];

    protected $casts = [
        'respuesta' => 'array',
    ];

    public function detalle()
    {
        return $this->belongsTo(Dominios_Contenido_DetallesModel::class, 'id_dominio_contenido_detalle', 'id_dominio_contenido_detalle');
    }
}
