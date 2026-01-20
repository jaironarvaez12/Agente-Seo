<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('moz_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_dominio');
            $table->date('date'); // día del snapshot
            $table->string('target', 255);

            $table->unsignedInteger('backlinks_total')->nullable();     // pages_to_root_domain
            $table->unsignedInteger('ref_domains_total')->nullable();   // root_domains_to_root_domain

            $table->unsignedTinyInteger('domain_authority')->nullable();
            $table->unsignedTinyInteger('page_authority')->nullable();
            $table->integer('spam_score')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['id_dominio', 'date']);
            $table->index(['id_dominio', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moz_daily_metrics');
    }
};