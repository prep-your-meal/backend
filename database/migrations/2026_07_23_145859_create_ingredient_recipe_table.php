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
        Schema::create('ingredient_recipe', function (Blueprint $table) {
            $table->id();

            $table->string('recipe_slug');
            $table->foreign('recipe_slug')->references('slug')->on('recipes')->cascadeOnDelete();

            $table->string('ingredient_slug');
            $table->foreign('ingredient_slug')->references('slug')->on('ingredients')->cascadeOnDelete();

            $table->decimal('amount', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_recipe');
    }
};
