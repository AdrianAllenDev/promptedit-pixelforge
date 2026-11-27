<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use App\Pixelforge\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $pricing;

    private array $input;

    private string $mode = 'queued';

    private int $providerSubmissions = 0;

    private string $reportedCost = '0.000000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        config(['services.pixelforge.wallets_enabled' => true, 'services.pixelforge.secret' => 'wallet-test']);
        $this->pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
        $this->input = ['prompt' => str_repeat('a', 400), 'quality' => 'high', 'size' => '1536x1024', 'reference_images' => ['https://example.org/a', 'https://example.org/b']];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if (str_contains($request->url(), '/usage?')) {
                return Http::response(['data' => [['date' => $request['start_date'], 'cost_usd' => $this->reportedCost]]]);
            }
            if ($request->method() === 'POST') {
                $this->providerSubmissions++;
            }
            if ($this->mode === 'timeout') {
                throw new ConnectionException('Lost response');
            }
            if ($this->mode === 'rejected') {
                return Http::response(['error' => ['code' => 'content_policy_violation']], 400);
            }
            $local = $request['metadata']['local_id'];

            return Http::response(['id' => 'gen_'.$local, 'metadata' => ['local_id' => $local], 'status' => 'queued']);
        });
    }

    private function submission(): array
    {
        $quote = $this->postJson('/quotes', $this->input)->assertOk()->json();

        return $this->input + ['quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()];
    }

    private function submit(): string
    {
        return $this->postJson('/generations', $this->submission())->assertAccepted()->json('id');
    }

    private function event(string $id, string $status, string $rate = '40'): void
    {
        $object = ['id' => 'gen_'.$id, 'metadata' => ['local_id' => $id], 'status' => $status, 'completed_at' => now()->toIso8601String(),
            'usage' => array_combine(Pricing::CATEGORIES, [115, 1530, 6450, 8000]), 'rates' => ['output_tokens' => $rate] + $this->pricing['rates']];
        $body = json_encode(['id' => 'evt_'.$id.'_'.$status, 'type' => 'generation.'.$status, 'data' => ['object' => $object]]);
        $t = now()->timestamp;
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => "t=$t,v1=".hash_hmac('sha256', $t.'.'.$body, 'wallet-test')], $body)->assertNoContent();
    }

    private function assertBalance(string $available, string $held, string $due, string $spent): void
    {
        $this->assertSame(compact('available', 'due', 'held', 'spent'), app(Wallet::class)->balance($this->user->id));
    }

    public function test_overage_preserves_another_hold_and_replays_do_not_change_the_balance(): void
    {
        DB::table('wallet_topups')->insert(['user_id' => $this->user->id, 'purchase_key' => (string) Str::uuid(), 'amount' => '0.667343750', 'created_at' => now()]);
        $pending = Generation::create(['user_id' => $this->user->id, 'submission_key' => (string) Str::uuid(), 'payload' => []]);
        DB::transaction(function () use ($pending) {
            User::whereKey($this->user->id)->lockForUpdate()->firstOrFail();
            app(Wallet::class)->reserve($pending, '0.200000000');
        });
        $id = $this->submit();
        $this->event($id, 'succeeded', '80');
        $this->assertBalance('0.000000000000', '0.200000000000', '0.322500000000', '0.789843750000');
        $this->assertDatabaseHas('accepted_quotes', ['generation_id' => $id, 'maximum' => '0.467343750']);
        $this->assertDatabaseHas('final_bills', ['generation_id' => $id, 'customer_charge' => '0.789843750']);
        $this->postJson('/generations', $this->submission())->assertStatus(402);
        $purchase = ['amount' => '1.00', 'purchase_key' => (string) Str::uuid()];
        $this->postJson('/wallet/top-ups', $purchase)->assertOk();
        $this->event($id, 'succeeded', '80');
        $this->postJson('/wallet/top-ups', $purchase)->assertOk();
        $this->assertBalance('0.677500000000', '0.200000000000', '0.000000000000', '0.789843750000');
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertDatabaseCount('wallet_topups', 2);
        $this->reportedCost = '0.631875000';
        $this->actingAs(Admin::create(['email' => 'admin@example.test', 'password' => 'test-password']), 'admin');
        $date = now('UTC')->toDateString();
        $this->get("/admin/report?start=$date&end=$date")->assertInertia(fn ($p) => $p
            ->where('report.total.customer_charge', '0.789843750000')->where('report.total.profit', '0.157968750000')
            ->where('report.total.difference', '0.000000000000'));
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_an_unsettled_hold_expires_at_24_hours_even_if_recovery_is_offline(): void
    {
        $this->freezeSecond();
        $this->mode = 'timeout';
        $this->postJson('/wallet/top-ups', ['amount' => '1.00', 'purchase_key' => (string) Str::uuid()])->assertOk();
        $id = $this->submit();
        Generation::whereKey($id)->update(['recover_at' => null]);
        $this->travel(24 * 60 * 60 - 1)->seconds();
        $other = $this->submit();
        $this->assertBalance('0.065312500000', '0.934687500000', '0.000000000000', '0.000000000000');
        $this->travel(1)->second();
        $this->assertBalance('0.532656250000', '0.467343750000', '0.000000000000', '0.000000000000');
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('active', true)
            ->where('history.data.1.hold_released', true)->where('history.data.1.customer_charge', null));
        $this->artisan('pixelforge:recover', ['--resume' => $id])->assertSuccessful();
        $this->assertSame(2, $this->providerSubmissions);
        $this->assertDatabaseCount('final_bills', 0);
        $this->event($id, 'succeeded', '80');
        $this->event($id, 'succeeded', '80');
        $this->assertBalance('0.000000000000', '0.467343750000', '0.257187500000', '0.789843750000');
        $this->assertDatabaseCount('final_bills', 1);
        $this->event($other, 'failed');
        $this->assertBalance('0.210156250000', '0.000000000000', '0.000000000000', '0.789843750000');
    }

    public function test_rollout_preserves_earlier_charges_and_requotes_an_old_offer(): void
    {
        config(['services.pixelforge.wallets_enabled' => false]);
        $this->get('/wallet')->assertNotFound();
        $id = $this->submit();
        $oldOffer = $this->submission();
        config(['services.pixelforge.wallets_enabled' => true]);
        $this->event($id, 'succeeded');
        $this->reportedCost = '0.373875000';
        $this->actingAs(Admin::create(['email' => 'admin@example.test', 'password' => 'test-password']), 'admin');
        $date = now('UTC')->toDateString();
        $this->get("/admin/report?start=$date&end=$date")->assertInertia(fn ($p) => $p
            ->where('report.reconciled', true)->where('report.total.customer_charge', '0.467343750000')
            ->where('report.total.difference', '0.000000000000'));
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('history.data.0.customer_charge', '0.467343750000'));
        $this->postJson('/generations', $oldOffer)->assertConflict()->assertJsonPath('quote.wallet_enabled', true);
        $this->assertDatabaseCount('wallet_holds', 0);
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertBalance('0.000000000000', '0.000000000000', '0.000000000000', '0.000000000000');
    }

    public function test_rejection_failure_timeout_and_below_hold_success(): void
    {
        $this->postJson('/wallet/top-ups', ['purchase_key' => (string) Str::uuid(), 'amount' => '1.00'])->assertOk();
        $this->mode = 'rejected';
        $this->submit();
        $this->assertBalance('1.000000000000', '0.000000000000', '0.000000000000', '0.000000000000');
        $this->mode = 'timeout';
        $id = $this->submit();
        $this->assertBalance('0.532656250000', '0.467343750000', '0.000000000000', '0.000000000000');
        $this->event($id, 'failed');
        $this->event($id, 'failed');
        $this->assertBalance('1.000000000000', '0.000000000000', '0.000000000000', '0.000000000000');
        $this->mode = 'queued';
        $this->event($this->submit(), 'succeeded', '20');
        $this->assertBalance('0.693906250000', '0.000000000000', '0.000000000000', '0.306093750000');
    }

    public function test_purchase_validation_key_scope_and_conflicting_replay(): void
    {
        $key = (string) Str::uuid();
        foreach (['-1', '0', '0.50', '10000'] as $amount) {
            $this->postJson('/wallet/top-ups', ['purchase_key' => $key, 'amount' => $amount])->assertUnprocessable();
        }
        $this->postJson('/wallet/top-ups', ['purchase_key' => $key, 'amount' => '1.00'])->assertOk();
        $this->postJson('/wallet/top-ups', ['purchase_key' => $key, 'amount' => '5.00'])->assertConflict();
        $this->actingAs(User::factory()->create())->postJson('/wallet/top-ups', ['purchase_key' => $key, 'amount' => '5.00'])->assertOk()->assertJsonPath('available', '5.000000000000');
    }
}
