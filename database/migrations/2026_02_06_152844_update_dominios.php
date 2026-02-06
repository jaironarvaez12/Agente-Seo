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

      // Tipo de regla WP (además del modo: publicar/programar)
      if (!Schema::hasColumn('dominios', 'wp_regla_tipo')) {
        $table->enum('wp_regla_tipo', ['manual','cada_n_dias','cada_x_minutos','diario','semanal'])
          ->default('manual')
          ->after('wp_auto_modo');
      }

      // Para cada_n_dias
      if (!Schema::hasColumn('dominios', 'wp_cada_dias')) {
        $table->unsignedInteger('wp_cada_dias')->nullable()->after('wp_regla_tipo');
      }

      // Para cada_x_minutos (si quieres separar del wp_programar_cada_minutos)
      if (!Schema::hasColumn('dominios', 'wp_cada_minutos')) {
        $table->unsignedInteger('wp_cada_minutos')->nullable()->after('wp_cada_dias');
      }

      // Excluir fines de semana (clave para tu caso)
      if (!Schema::hasColumn('dominios', 'wp_excluir_fines_semana')) {
        $table->boolean('wp_excluir_fines_semana')->default(false)->after('wp_cada_minutos');
      }

      // Cantidad por ejecución (si no lo tienes aún)
      if (!Schema::hasColumn('dominios', 'wp_tareas_por_ejecucion')) {
        $table->unsignedInteger('wp_tareas_por_ejecucion')->default(5)->after('wp_excluir_fines_semana');
      }

      // Próxima ejecución WP (si no lo tienes aún)
      if (!Schema::hasColumn('dominios', 'wp_siguiente_ejecucion')) {
        $table->timestamp('wp_siguiente_ejecucion')->nullable()->after('wp_tareas_por_ejecucion');
      }
    });
  }

  public function down(): void
  {
    Schema::table('dominios', function (Blueprint $table) {
      foreach ([
        'wp_siguiente_ejecucion',
        'wp_tareas_por_ejecucion',
        'wp_excluir_fines_semana',
        'wp_cada_minutos',
        'wp_cada_dias',
        'wp_regla_tipo',
      ] as $col) {
        if (Schema::hasColumn('dominios', $col)) $table->dropColumn($col);
      }
    });
  }
};
