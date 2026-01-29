<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dominios_contenido_detalles', function (Blueprint $table) {
            $table->string('estatus_backlinks')->nullable(); // pendiente | en_proceso | listo | error
            $table->json('resultado_backlinks')->nullable(); // respuesta completa
            $table->text('error_backlinks')->nullable();
            $table->timestamp('fecha_backlinks')->nullable();
        }); 
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
