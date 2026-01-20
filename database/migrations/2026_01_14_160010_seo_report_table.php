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
        Schema::create('seo_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('id_dominio')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status')->default('generando'); // generando|ok|error
            $table->text('error_message')->nullable();
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
