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
        'provider',     // Für Social Login
        'provider_id',  // Für Social Login
        'target_meals_per_week',
        'dietary_preferences',
        'fitness_goals',
        'logistics_preferences',
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
            // Cast JSON columns to PHP arrays automatically
            'dietary_preferences' => 'array',
            'fitness_goals' => 'array',
            'logistics_preferences' => 'array',
            'minimize_food_waste' => 'boolean',
        ];
    }

    public function favoriteRecipes()
    {
        // Since we are not using the default ID convention, we must specify the custom keys explicitly
        return $this->belongsToMany(\App\Models\Recipe::class, 'recipe_user', 'user_id', 'recipe_slug')
                    ->withTimestamps();
    }
}
