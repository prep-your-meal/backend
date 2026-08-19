<?php

namespace Database\Factories;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Recipe>
     */
    protected $model = Recipe::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a random recipe title
        $title = $this->faker->unique()->words(3, true);

        return [
            // Create a matching slug for the primary key
            'slug' => Str::slug($title),
            'title' => ucfirst($title),
            'image' => null,
            'prep_time' => $this->faker->numberBetween(10, 30),
            'cook_time' => $this->faker->numberBetween(15, 60),
            'default_portions' => 2,

            // Default categories as a valid JSON array
            'categories' => ['balanced', 'quick'],

            // Dummy macros
            'calories' => $this->faker->numberBetween(300, 800),
            'protein_g' => $this->faker->numberBetween(15, 60),
            'carbs_g' => $this->faker->numberBetween(20, 80),
            'fat_g' => $this->faker->numberBetween(10, 40),
        ];
    }
}
