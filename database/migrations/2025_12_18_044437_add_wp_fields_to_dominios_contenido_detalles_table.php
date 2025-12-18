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
            $table->unsignedBigInteger('wp_id')->nullable()->after('error');
            $table->string('wp_link', 500)->nullable()->after('wp_id');
        });
    }

    public function down(): void
    {
        Schema::table('dominios_contenido_detalles', function (Blueprint $table) {
            $table->dropColumn(['wp_id', 'wp_link']);
        });
    }
};
