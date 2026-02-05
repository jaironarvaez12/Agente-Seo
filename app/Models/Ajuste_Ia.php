<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Ajuste_Ia extends Model
{
    protected $table = 'ajustes_ia';
    protected $fillable = ['clave', 'valor'];
}