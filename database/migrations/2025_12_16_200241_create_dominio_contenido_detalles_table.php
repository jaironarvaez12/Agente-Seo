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
       Schema::create('dominios_contenido_detalles', function (Blueprint $table) {

        $table->integer('id_dominio_contenido_detalle')->primary();

        // Relación con la configuración
        $table->integer('id_dominio_contenido')->index(); // FK a dominios_contenido
        $table->integer('id_dominio')->index();           // redundante útil para filtrar rápido

        // Datos de entrada
        $table->string('tipo')->nullable();               // post/page (copiado)
        $table->string('keyword')->nullable();            // keyword usada
        $table->string('enfoque')->nullable();            // para variar contenido

        // Resultado
        $table->string('title')->nullable();
        $table->string('slug')->nullable();
        $table->longText('contenido_html')->nullable();   // HTML final

        // Opcional SEO
        $table->string('meta_title', 60)->nullable();
        $table->string('meta_description', 155)->nullable();

        // Publicación WP (para después)
        $table->bigInteger('wp_post_id')->nullable();
        $table->string('wp_url')->nullable();

        // Control del pipeline
        $table->string('estatus', 20)->default('pendiente'); // pendiente|en_proceso|generado|publicado|error
        $table->text('error')->nullable();

        // Depuración (opcional)
        $table->longText('draft_html')->nullable();       // borrador antes del auditor
        $table->string('modelo')->nullable();             // gpt-5-mini, etc.

        $table->timestamps();

        // Evitar duplicados: misma config + keyword
  

        // Si quieres FK real (solo si tus tablas usan InnoDB y tipos iguales):
        // $table->foreign('id_dominio_contenido')
        //     ->references('id_dominio_contenido')
        //     ->on('dominios_contenido')
        //     ->onDelete('cascade');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dominio_contenido_detalles');
    }
};
