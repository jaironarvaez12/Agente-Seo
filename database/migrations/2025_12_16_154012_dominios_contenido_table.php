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
         Schema::create('dominios_contenido', function (Blueprint $table) {
            $table->integer('id_dominio_contenido')->primary();
            $table->integer('id_dominio');
            $table->string('tipo')->nullable();
            $table->string('palabras_claves')->nullable();
            $table->string('estatus',2)->nullable();
            $table->timestamps();
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
