<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_credentials_and_legacy_grants_cannot_open_either_report_url(): void
    {
        $customer = User::factory()->create(['is_operator' => true]);
        foreach (['/admin/report', '/operator/report'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
            $this->getJson($url)->assertUnauthorized();
        }
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->missing('isOperator'));
        foreach (['/admin/report', '/operator/report'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/login', ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
        Http::assertNothingSent();
    }

    public function test_admin_login_has_an_independent_identity_and_logout_ends_report_access(): void
    {
        $credentials = ['email' => 'admin@example.test', 'password' => 'test-admin-password'];
        $admin = Admin::create($credentials);
        $customer = User::factory()->create();
        $this->post('/login', $credentials)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/admin/login', $credentials)->assertRedirect('/admin/report');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/operator/report?start=2026-11-26&end=2026-11-26')->assertRedirect('/admin/report?start=2026-11-26&end=2026-11-26');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->get('/admin/report')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    public function test_signing_into_or_registering_a_customer_ends_an_admin_session(): void
    {
        $admin = ['email' => 'admin@example.test', 'password' => 'test-admin-password'];
        Admin::create($admin);
        $user = User::factory()->create();
        $this->post('/admin/login', $admin);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get('/admin/report')->assertForbidden();
        $this->post('/admin/login', $admin);
        $this->post('/register', ['name' => 'Reviewer', 'email' => 'new@example.test', 'password' => 'reviewer-password', 'password_confirmation' => 'reviewer-password', 'is_operator' => true]);
        $this->assertGuest('admin');
        $this->get('/admin/report')->assertForbidden();
        $this->assertDatabaseCount('admins', 1);
    }

    public function test_admin_creation_is_private_and_cannot_overwrite_an_existing_login(): void
    {
        $this->artisan('admin:create')->expectsQuestion('Admin email', 'one@example.test')->expectsQuestion('Admin password', 'admin-only-password')->assertSuccessful();
        $this->artisan('admin:create')->expectsQuestion('Admin email', 'one@example.test')->expectsQuestion('Admin password', 'replacement-secret')->assertFailed();
        $this->assertDatabaseCount('admins', 1);
        $this->assertDatabaseCount('users', 0);
        $this->post('/admin/login', ['email' => 'one@example.test', 'password' => 'admin-only-password'])->assertRedirect('/admin/report');
    }
}
