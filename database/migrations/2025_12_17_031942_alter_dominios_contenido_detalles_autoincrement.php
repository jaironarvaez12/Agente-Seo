<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cambia PK a BIGINT AUTO_INCREMENT
        DB::statement("
            ALTER TABLE dominios_contenido_detalles
            MODIFY id_dominio_contenido_detalle BIGINT NOT NULL AUTO_INCREMENT
        ");
    }

    public function down(): void
    {
        // Revierte (sin autoincrement)
        DB::statement("
            ALTER TABLE dominios_contenido_detalles
            MODIFY id_dominio_contenido_detalle BIGINT NOT NULL
        ");
    }
};