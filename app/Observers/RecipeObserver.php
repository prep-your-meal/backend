<?php

namespace App\Observers;

use App\Models\Recipe;
use Illuminate\Support\Facades\Cache;

class RecipeObserver
{
    /**
     * Handle the Recipe "saved" event (covers both created and updated).
     */
    public function saved(Recipe $recipe): void
    {
        // Clear the cache for the current slug
        Cache::forget("recipe_{$recipe->slug}");

        // If the slug was changed during an update, also clear the cache for the old slug
        if ($recipe->isDirty('slug')) {
            Cache::forget("recipe_{$recipe->getOriginal('slug')}");
        }
    }

    /**
     * Handle the Recipe "deleted" event.
     */
    public function deleted(Recipe $recipe): void
    {
        Cache::forget("recipe_{$recipe->slug}");
    }
}
