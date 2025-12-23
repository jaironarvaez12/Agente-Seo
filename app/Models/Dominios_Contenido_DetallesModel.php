<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Dominios_Contenido_DetallesModel extends Model
{
    use HasFactory;

      protected $table = 'dominios_contenido_detalles';
    protected $primaryKey = 'id_dominio_contenido_detalle';

    public $timestamps = true;

    protected $fillable = [
        'job_uuid',
        'id_dominio_contenido',
        'id_dominio',
        'tipo',
        'keyword',
        'estatus',
        'modelo',
        'title',
        'slug',
        'draft_html',
        'contenido_html',
        'error',
    ];
}