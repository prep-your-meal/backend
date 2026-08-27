<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    use HasFactory;

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['slug', 'name', 'unit', 'category'];

    protected $casts = [
        'name' => 'array',
    ];

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'ingredient_recipe', 'ingredient_slug', 'recipe_slug')
            ->withPivot('amount')
            ->withTimestamps();
    }
}
