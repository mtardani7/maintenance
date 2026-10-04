<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_list_and_create_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($operator)->postJson('/api/admin/users', [])->assertForbidden();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Maintenance Demo', 'email' => 'maintenance@example.com', 'phone' => '08123456789',
            'nik' => 'EMP-100', 'role' => 'technician', 'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('phone', '08123456789')->assertJsonPath('nik', 'EMP-100')->assertJsonPath('is_active', true);

        $this->actingAs($admin)->getJson('/api/admin/users?search=EMP-100')->assertOk()
            ->assertJsonPath('data.0.email', 'maintenance@example.com');
    }

    public function test_admin_can_edit_and_deactivate_user_without_deleting_history_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}", [
            'name' => 'Updated User', 'email' => $user->email, 'phone' => '08111111111', 'nik' => 'EMP-200',
            'role' => 'technician', 'is_active' => false,
        ])->assertOk()->assertJsonPath('name', 'Updated User')->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated User', 'role' => 'technician', 'is_active' => false]);
    }

    public function test_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'operator', 'is_active' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com', 'password' => Hash::make('password123'), 'is_active' => false,
        ]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->assertUnprocessable()->assertJsonMissingPath('token');
    }
}
