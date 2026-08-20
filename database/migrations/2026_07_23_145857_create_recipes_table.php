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
        Schema::create('recipes', function (Blueprint $table) {
            $table->string('slug')->primary();

            $table->string('title');
            $table->string('image')->nullable();

            // Times in minutes
            $table->integer('prep_time')->nullable();
            $table->integer('cook_time')->nullable();

            // The default number of portions from the Markdown file
            $table->integer('default_portions')->default(1);

            // Storing the categories array as JSON
            $table->json('categories')->nullable();

            // Macros (per portion)
            $table->integer('calories')->nullable();
            $table->integer('protein_g')->nullable();
            $table->integer('carbs_g')->nullable();
            $table->integer('fat_g')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
