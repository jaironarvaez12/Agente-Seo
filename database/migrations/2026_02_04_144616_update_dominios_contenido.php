<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dominios_contenido', function (Blueprint $table) {
            $table->text('palabras_claves')->change();
        });
    }

    public function down(): void
    {
        Schema::table('dominios_contenido', function (Blueprint $table) {
            $table->string('palabras_claves', 255)->change();
        });
    }
};