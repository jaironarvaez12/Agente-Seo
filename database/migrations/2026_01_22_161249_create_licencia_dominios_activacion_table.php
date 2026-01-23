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
         Schema::create('licencia_dominios_activacion', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Guardar SHA1 de la licencia (40 chars). NO guardes la key en texto plano.
            $table->string('license_key', 40);

            // Dominio host limpio: ejemplo.com (sin https, sin rutas)
            $table->string('dominio', 255);

            // Estatus local del registro
            $table->string('estatus', 20)->default('activo'); // activo/inactivo

            $table->timestamp('activo_at')->nullable();
            $table->timestamp('desactivado_at')->nullable();

            $table->timestamps();

            // Índices con nombres cortos (evita error 1059)
            $table->unique(['license_key', 'dominio'], 'lda_key_dom_uq');
            $table->index(['user_id', 'license_key', 'estatus'], 'lda_user_key_est_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licencia_dominios_activacion');
    }
};
