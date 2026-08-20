<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_user', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to the user
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Foreign key to the recipe (Note: referencing the string 'slug' instead of an ID)
            $table->string('recipe_slug');
            $table->foreign('recipe_slug')->references('slug')->on('recipes')->cascadeOnDelete();
            
            $table->timestamps();

            // Prevent a user from favoriting the exact same recipe multiple times
            $table->unique(['user_id', 'recipe_slug']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_user');
    }
};