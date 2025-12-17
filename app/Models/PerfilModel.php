<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerfilModel extends Model
{
    protected $table = 'perfiles';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'nombre',
        'descripcion',
        'fb_page_id',
        'fb_page_name',
        'fb_page_token',
        'fb_system_user_token',
        'fb_page_token_expires_at',
        'status',
        'ig_business_id', // ✅ asegúrate de incluir este campo
    ];
    // Encripta automáticamente el token en BD
    protected $casts = [
        'fb_page_token' => 'encrypted',
        'fb_system_user_token' => 'encrypted',
        'fb_page_token_expires_at' => 'datetime',
    ];
    protected $dateFormat = 'Y-m-d H:i:s'; //funcioon para formateo de la fecha
}