<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->firstName(), 'status' => 'active'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            if (! $user->wa_id_enc) {
                $user->setWaId('9199'.fake()->unique()->numerify('########'));
            }
        });
    }
}
