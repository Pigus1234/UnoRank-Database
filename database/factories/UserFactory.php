<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return ['username' => fake()->unique()->userName(), 'password' => 'test-password-123', 'is_active' => true];
    }
}
