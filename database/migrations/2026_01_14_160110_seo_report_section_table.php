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
        Schema::create('seo_report_sections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('seo_report_id')->index();
            $table->string('section')->index(); // moz|tech|pagespeed
            $table->string('status')->default('ok'); // ok|error
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
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
