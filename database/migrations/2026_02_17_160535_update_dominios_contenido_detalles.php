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
        //
        Schema::table('dominios_contenido_detalles', function (Blueprint $table) {
            $table->dateTime('fecha_publicado')->nullable()->after('scheduled_at');
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
