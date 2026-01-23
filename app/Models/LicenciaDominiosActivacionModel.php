<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenciaDominiosActivacionModel extends Model
{
   protected $table = 'licencia_dominios_activacion';

    protected $fillable = [
        'user_id',
        'license_key',
        'dominio',
        'estatus',
        'activo_at',
        'desactivado_at',
    ];
}
