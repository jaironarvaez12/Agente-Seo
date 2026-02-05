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
        Schema::create('ajustes_ia', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();          // ej: deepseek_prompt_global
            $table->longText('valor')->nullable();      // aquí guardas el prompt
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
    }
};
