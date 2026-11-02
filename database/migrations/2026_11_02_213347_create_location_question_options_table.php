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
        Schema::create('location_question_options', function (Blueprint $table) {
            $table->id();
            $table->string('location');
            $table->string('type', 20);
            $table->string('value');
            $table->timestamps();
            $table->unique(['location', 'type', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_question_options');
    }
};
