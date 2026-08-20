<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Generate a unique slug for the primary key
            'slug' => $this->faker->unique()->slug(),

            // Generate a random ingredient name
            'name' => $this->faker->word(),

            // Pick a random common measurement unit
            'unit' => $this->faker->randomElement(['g', 'ml', 'piece', 'tbsp', 'tsp']),

            // Pick a random category to satisfy the database column
            'category' => $this->faker->randomElement(['vegetables', 'meat', 'dairy', 'spices', 'pantry']),
        ];
    }
}
