<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_utc_completion_days_and_users_reconcile_against_key_usage(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        foreach ([[$alice, '2026-11-24T23:59:59Z'], [$bob, '2026-11-25T01:00:00+01:00'], [$alice, '2026-11-23T21:00:00Z']] as [$user, $completed]) {
            $g = Generation::create(['user_id' => $user->id, 'submission_key' => (string) Str::uuid(), 'payload' => [], 'status' => 'succeeded', 'completed_at' => CarbonImmutable::parse($completed)->utc()]);
            DB::table('final_bills')->insert(['generation_id' => $g->id, 'provider_cost' => '0.054197500', 'customer_charge' => '0.067746875', 'profit' => '0.013549375', 'created_at' => now()]);
        }
        Http::fake(['*/usage*' => Http::response(['data' => [
            ['date' => '2026-11-24', 'cost_usd' => '0.054197500'], ['date' => '2026-11-25', 'cost_usd' => '0.055197500'], ['date' => '2026-11-26', 'cost_usd' => '0.000000000'],
        ]])]);
        $url = '/admin/report?start=2026-11-24&end=2026-11-26';
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs($bob)->get($url)->assertForbidden();
        Http::assertNothingSent();
        $this->actingAs(Admin::create(['email' => 'admin@example.test', 'password' => 'test-password']), 'admin')->get($url)->assertInertia(fn ($page) => $page->component('OperatorReport')
            ->where('report.reconciled', true)->has('report.checkedAt')->has('report.users', 2)
            ->where('report.users.0.id', $alice->id)->where('report.users.1.customer_charge', '0.067746875000')
            ->where('report.daily.0.provider_cost', '0.054197500000')->where('report.daily.1.difference', '-0.001000000000')
            ->where('report.daily.2.customer_charge', '0.000000000000')->where('report.daily.2.reported', '0.000000000')
            ->where('report.total.customer_charge', '0.135493750000')->where('report.total.profit', '0.027098750000')
            ->where('report.total.difference', '-0.001000000000'));
        Http::assertSent(fn ($request) => $request['start_date'] === '2026-11-24' && $request['end_date'] === '2026-11-26');
    }

    public function test_range_limit_and_failed_or_incomplete_usage_do_not_report_a_false_zero(): void
    {
        $this->actingAs(Admin::create(['email' => 'admin@example.test', 'password' => 'test-password']), 'admin');
        $this->getJson('/admin/report?start=2026-01-01&end=2026-04-03')->assertUnprocessable();
        Http::assertNothingSent();
        Http::fake(['*/usage*' => Http::sequence()->pushStatus(503)->push(['data' => []])]);
        foreach (['2026-04-02', '2026-01-01'] as $end) {
            $this->get('/admin/report?start=2026-01-01&end='.$end)->assertInertia(fn ($p) => $p->where('report.reconciled', false)
                ->where('report.total.difference', null)->where('report.daily.0.reported', null)->has('report.users', 0));
        }
    }
}
