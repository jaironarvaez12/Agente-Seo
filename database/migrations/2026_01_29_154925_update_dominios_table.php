<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            $table->boolean('auto_generacion_activa')->default(false);

            // daily|hourly|weekly|custom
            $table->string('auto_frecuencia')->default('daily');

            // solo si auto_frecuencia = custom
            $table->unsignedInteger('auto_cada_minutos')->nullable();

            // cuántas tareas disparar por ejecución automática (sin saltarse licencias)
            $table->unsignedInteger('auto_tareas_por_ejecucion')->default(5);

            // próxima ejecución
            $table->timestamp('auto_siguiente_ejecucion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            $table->dropColumn([
                'auto_generacion_activa',
                'auto_frecuencia',
                'auto_cada_minutos',
                'auto_tareas_por_ejecucion',
                'auto_siguiente_ejecucion',
            ]);
        });
    }
};
