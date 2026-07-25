<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlan extends Model
{
    protected $fillable = [
        'user_id',
        'recipe_slug',
        'scheduled_for',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
    ];

    public function recipe(): BelongsTo
    {
        // Parameter: Related Model, Foreign Key, Local Key
        return $this->belongsTo(Recipe::class, 'recipe_slug', 'slug');
    }
}
