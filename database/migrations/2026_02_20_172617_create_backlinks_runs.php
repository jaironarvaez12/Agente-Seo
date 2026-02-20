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
        Schema::create('backlinks_runs', function (Blueprint $table) {
            $table->bigIncrements('id_backlink_run'); // autoincrement y primary
            $table->integer('id_dominio');
            $table->unsignedBigInteger('id_dominio_contenido_detalle');
            $table->string('estatus')->default('listo'); // listo/parcial/error
            $table->json('respuesta')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['id_dominio_contenido_detalle']);
            $table->index(['id_dominio']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backlinks_runs');
    }
};
