<?php

namespace App\Models;

use App\Enums\BudgetCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Recipe extends Model
{
    use HasFactory;

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'slug',
        'title',
        'image',
        'prep_time',
        'cook_time',
        'default_portions',
        'instructions',
        'categories',
        'calories',
        'protein_g',
        'carbs_g',
        'fat_g',
        'budget',
    ];

    protected $casts = [
        'budget' => BudgetCategory::class,
        'title' => 'array',
        'default_portions' => 'integer',
        'categories' => 'array',
        'instructions' => 'array',
    ];

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_recipe', 'recipe_slug', 'ingredient_slug')
            ->withPivot('amount')
            ->withTimestamps();
    }
}
