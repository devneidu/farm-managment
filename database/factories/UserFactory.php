<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now()]);
    }

    /** Verified user with an initial farm, owner membership and onboarded_at set. */
    public function onboarded(string $farmName = 'Test Farm'): static
    {
        return $this->state(fn () => ['onboarded_at' => now()])
            ->afterCreating(function (User $user) use ($farmName) {
                $farm = Farm::create(['name' => $farmName]);
                FarmMembership::create(['farm_id' => $farm->id, 'user_id' => $user->id, 'role' => FarmMembership::ROLE_OWNER]);
            });
    }
}
