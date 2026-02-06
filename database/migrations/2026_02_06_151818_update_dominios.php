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
        Schema::table('dominios', function (Blueprint $table) {

            // Cuántos contenidos enviar por corrida a WP (publicar o programar)
            if (!Schema::hasColumn('dominios', 'wp_tareas_por_ejecucion')) {
                $table->unsignedInteger('wp_tareas_por_ejecucion')
                      ->default(5)
                      ->after('wp_programar_cada_minutos');
            }

            // Próxima ejecución de WP (independiente de auto-generación)
            // Si YA tienes wp_siguiente_programacion y quieres reutilizarlo, puedes omitir este campo.
            if (!Schema::hasColumn('dominios', 'wp_siguiente_ejecucion')) {
                $table->timestamp('wp_siguiente_ejecucion')
                      ->nullable()
                      ->after('wp_tareas_por_ejecucion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            if (Schema::hasColumn('dominios', 'wp_siguiente_ejecucion')) {
                $table->dropColumn('wp_siguiente_ejecucion');
            }
            if (Schema::hasColumn('dominios', 'wp_tareas_por_ejecucion')) {
                $table->dropColumn('wp_tareas_por_ejecucion');
            }
        });
    }
};
