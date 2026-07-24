<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlan extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'recipe_slug',
        'scheduled_for',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_for' => 'date',
    ];

    /**
     * Get the recipe associated with the meal plan.
     */
    public function recipe(): BelongsTo
    {
        // Parameter: Related Model, Foreign Key, Local Key
        return $this->belongsTo(Recipe::class, 'recipe_slug', 'slug');
    }
}
