<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'is_active' => true,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Assign an application role, creating it when the seeder has not run.
     */
    public function withRole(UserRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->syncRoles([Role::findOrCreate($role->value, 'web')]);
        });
    }

    public function superAdmin(): static
    {
        return $this->withRole(UserRole::SuperAdmin);
    }

    public function adminPusat(): static
    {
        return $this->withRole(UserRole::AdminPusat);
    }

    /**
     * A branch admin, bound to the given branch or to a new one.
     */
    public function adminCabang(Branch|int|null $branch = null): static
    {
        return $this->forBranch($branch)->withRole(UserRole::AdminCabang);
    }

    public function staffGudang(Branch|int|null $branch = null): static
    {
        return $this->forBranch($branch)->withRole(UserRole::StaffGudang);
    }

    public function kasir(Branch|int|null $branch = null): static
    {
        return $this->forBranch($branch)->withRole(UserRole::Kasir);
    }

    public function customer(): static
    {
        return $this->withRole(UserRole::Customer);
    }

    public function forBranch(Branch|int|null $branch = null): static
    {
        return $this->state([
            'branch_id' => match (true) {
                $branch instanceof Branch => $branch->id,
                is_int($branch) => $branch,
                default => Branch::factory(),
            },
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
