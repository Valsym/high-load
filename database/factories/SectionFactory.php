<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;


class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Генерируем уникальное число от 1 до 1000 (максимум нужных категорий)
        $uniqueNumber = $this->faker->unique()->numberBetween(1, 1000);

        return [
            'name' => 'Category ' . $uniqueNumber,
        ];
//        return [
//            'name' => fake()->unique()->word(),
//        ];
    }

}
