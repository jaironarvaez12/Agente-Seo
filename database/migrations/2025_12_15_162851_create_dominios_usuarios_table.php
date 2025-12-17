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
        Schema::create('dominios_usuarios', function (Blueprint $table) {
            $table->integer('id_dominio_usuario')->primary();
            $table->integer('id_dominio');
            $table->unsignedBigInteger('id_usuario');
            $table->timestamps();
            $table->dateTime('fecha_creacion');
            $table->string('creado_por', 100);

            $table->foreign('id_dominio')->references('id_dominio')->on('dominios');
            $table->foreign('id_usuario')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dominios_usuarios');
    }
};
