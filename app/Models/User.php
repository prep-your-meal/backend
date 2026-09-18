<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
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
        'is_premium', // Added for premium feature
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
            'is_premium' => 'boolean', // Ensure it is cast to boolean
        ];
    }

    public function favoriteRecipes()
    {
        // Since we are not using the default ID convention, we must specify the custom keys explicitly
        return $this->belongsToMany(Recipe::class, 'recipe_user', 'user_id', 'recipe_slug')
            ->withTimestamps();
    }

    public function customShoppingItems()
    {
        return $this->hasMany(CustomShoppingItem::class);
    }

    /**
     * Check if the user has premium status.
     * Explicitly cast to bool to prevent TypeErrors in tests where the factory might leave it null.
     */
    public function isPremium(): bool
    {
        return (bool) $this->is_premium;
    }
}
