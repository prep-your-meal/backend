<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlan extends Model
{
    // Added portions to fillable array
    protected $fillable = [
        'user_id',
        'recipe_slug',
        'scheduled_for',
        'portions',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'portions' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        // Parameters: Related Model, Foreign Key, Local Key
        return $this->belongsTo(Recipe::class, 'recipe_slug', 'slug');
    }
}
