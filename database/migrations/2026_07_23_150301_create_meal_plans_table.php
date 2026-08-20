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

            // Prepares a user_id for multi-account structures (e.g., FA-01)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('recipe_slug');
            $table->foreign('recipe_slug')->references('slug')->on('recipes')->cascadeOnDelete();

            // When is/was this meal scheduled to be eaten?
            $table->date('scheduled_for');

            // How many portions are planned for this specific meal on this day
            $table->integer('portions')->default(1);

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
