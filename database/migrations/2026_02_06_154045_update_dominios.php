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

            if (!Schema::hasColumn('dominios', 'wp_hora_del_dia')) {
                $table->time('wp_hora_del_dia')->nullable()->after('wp_cada_minutos');
            }

            if (!Schema::hasColumn('dominios', 'wp_dias_semana')) {
                $table->json('wp_dias_semana')->nullable()->after('wp_hora_del_dia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            if (Schema::hasColumn('dominios', 'wp_dias_semana')) {
                $table->dropColumn('wp_dias_semana');
            }
            if (Schema::hasColumn('dominios', 'wp_hora_del_dia')) {
                $table->dropColumn('wp_hora_del_dia');
            }
        });
    }
};
