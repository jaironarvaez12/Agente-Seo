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
      Schema::create('moz_domain_snapshots', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('id_dominio')->index();
    $table->string('target')->index();
    $table->timestamp('pulled_at')->index();
    $table->json('payload')->nullable();
    $table->string('status')->default('ok');
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
