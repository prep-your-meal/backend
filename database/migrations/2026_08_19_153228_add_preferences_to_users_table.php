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
        Schema::table('users', function (Blueprint $table) {
            // How many meals the user wants generated per week
            $table->integer('target_meals_per_week')->default(3)->after('provider_id');

            // JSON arrays to store the specific wizard selections
            // e.g., ["vegan", "dairy-free"]
            $table->json('dietary_preferences')->nullable()->after('target_meals_per_week');

            // e.g., ["high-protein", "cutting"]
            $table->json('fitness_goals')->nullable()->after('dietary_preferences');

            // e.g., ["meal-prep-friendly", "one-pot"]
            $table->json('logistics_preferences')->nullable()->after('fitness_goals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'target_meals_per_week',
                'dietary_preferences',
                'fitness_goals',
                'logistics_preferences',
            ]);
        });
    }
};
