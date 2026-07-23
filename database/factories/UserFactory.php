<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    /**
     * Default: a plain, active, verified Viewer — the lowest-privilege role,
     * matching frontend/types.ts's UserRole union. Deliberately does not
     * grant any elevated access by default; tests that need a specific role
     * should be explicit about it via the named states below rather than
     * relying on this default staying the same.
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'full_name' => fake()->name(),
            'password' => bcrypt('password'),
            'role' => 'Viewer',
            'active' => true,
            'email_verified_at' => now(),
        ];
    }

    // ---- Account state variants ----

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    // ---- Role variants ----
    // These four match frontend/types.ts UserRole exactly:
    //   'Administrator' | 'Reviewer' | 'Analyst' | 'Viewer'
    // No permission enforcement lives here — that's Day 6's policy layer.
    // This factory only sets the column; it doesn't imply what the role can do.

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function administrator(): static
    {
        return $this->state(fn () => ['role' => 'Administrator']);
    }

    public function reviewer(): static
    {
        return $this->state(fn () => ['role' => 'Reviewer']);
    }

    public function analyst(): static
    {
        return $this->state(fn () => ['role' => 'Analyst']);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => 'Viewer']);
    }
}