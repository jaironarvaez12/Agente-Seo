<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('perfiles', function (Blueprint $table) {
            $table->id();

            // Información general del perfil o negocio
            $table->string('nombre')->nullable();
            $table->string('descripcion')->nullable();

            // Datos de la Página de Facebook
            $table->string('fb_page_id')->nullable()->index();      // ID numérico de la Página
            $table->string('fb_page_name')->nullable();             // Nombre visible de la Página

            // Token de acceso (System User o Page Token)
            $table->text('fb_page_token')->nullable();              // se puede encriptar en el modelo
            $table->timestamp('fb_page_token_expires_at')->nullable();

            // Control
            $table->enum('status', ['activo','inactivo'])->default('activo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles');
    }
};