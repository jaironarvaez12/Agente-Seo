<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
class Dominios_Contenido_DetallesModel extends Model
{
    use HasFactory;

    protected $table = 'dominios_contenido_detalles';
    protected $primaryKey = 'id_dominio_contenido_detalle';

    public $incrementing = true;

    // Opción segura para BIGINT en MySQL:
    // si prefieres, déjalo en 'int', pero 'string' evita problemas si el id crece mucho.
    protected $keyType = 'string';

    public $timestamps = true;

    protected $casts = [
        'scheduled_at' => 'datetime',
        'wp_post_id'   => 'integer',
        'wp_id'        => 'integer',
        'id_dominio_contenido' => 'integer',
        'id_dominio'           => 'integer',
        'resultado_backlinks' => 'array',
        'fecha_backlinks' => 'datetime',
        'fecha_publicado' => 'datetime',
        
    ];

    protected $fillable = [
        'job_uuid',

        'id_dominio_contenido',
        'id_dominio',

        'tipo',
        'keyword',
        'enfoque',

        'title',
        'slug',

        'contenido_html',
        'draft_html',

        'meta_title',
        'meta_description',

        'wp_post_id',
        'wp_url',

        'wp_id',
        'wp_link',

        'scheduled_at',
        'fecha_publicado',
        

        'estatus',
        'error',

        'modelo',

        'estatus_backlinks',
        'resultado_backlinks',
        'error_backlinks',
        'fecha_backlinks',
    ];
   
    public function backlinksRuns()
    {
        return $this->hasMany(\App\Models\BacklinksRun::class, 'id_dominio_contenido_detalle', 'id_dominio_contenido_detalle')
            ->orderByDesc('created_at');
    }


     public static function ContenidoGenerado($IdUsuario,$esAdmin)
    {
        $q = DB::table('dominios_contenido_detalles as dcd')
        ->join('dominios_contenido as dc', 'dc.id_dominio_contenido', '=', 'dcd.id_dominio_contenido')
        ->wherein('dcd.estatus', ['generado','publicado','programado']);

        // Si NO es admin, filtra por dominios que el usuario tenga en la pivote
        if (!$esAdmin) {
            $q->join('dominios_usuarios as du', 'du.id_dominio', '=', 'dc.id_dominio')
            ->where('du.id_usuario', $IdUsuario);
        }

    return (int) $q->count('dcd.id_dominio_contenido_detalle');
    }


}
