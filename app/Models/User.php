<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'target_meals_per_week',
        'default_portions',
        'dietary_preferences',
        'fitness_goals',
        'logistics_preferences',
        'allergies',
        'minimize_food_waste',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dietary_preferences' => 'array',
            'fitness_goals' => 'array',
            'logistics_preferences' => 'array',
            'allergies' => 'array',
            'default_portions' => 'integer',
            'minimize_food_waste' => 'boolean',
        ];
    }

    public function favoriteRecipes()
    {
        // Since we are not using the default ID convention, we must specify the custom keys explicitly
        return $this->belongsToMany(Recipe::class, 'recipe_user', 'user_id', 'recipe_slug')
            ->withTimestamps();
    }
}
