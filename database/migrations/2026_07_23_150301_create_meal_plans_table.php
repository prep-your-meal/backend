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
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            // Falls später FA-01 (Personen/Accounts) hinzukommt, bereiten wir einen user_id vor
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('recipe_slug');
            $table->foreign('recipe_slug')->references('slug')->on('recipes')->cascadeOnDelete();

            $table->date('scheduled_for'); // Wann wird/wurde es gegessen?
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
