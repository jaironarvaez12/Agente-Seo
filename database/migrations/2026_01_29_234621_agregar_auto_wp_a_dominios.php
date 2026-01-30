<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            // modo de envío a WP: manual|publicar|programar
            $table->string('wp_auto_modo')->default('manual');

            // si programa: cada cuántos minutos entre publicaciones
            $table->unsignedInteger('wp_programar_cada_minutos')->default(60);

            // siguiente fecha programada (en zona horaria app)
            $table->timestamp('wp_siguiente_programacion')->nullable();

            // cola automática hacia WP activa/inactiva (opcional)
            $table->boolean('wp_auto_activo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dominios', function (Blueprint $table) {
            $table->dropColumn([
                'wp_auto_modo',
                'wp_programar_cada_minutos',
                'wp_siguiente_programacion',
                'wp_auto_activo',
            ]);
        });
    }
};
