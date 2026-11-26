<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use App\Pixelforge\Wallet;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentSettlementTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_webhook_and_recovery_connections_contend_for_one_bill(): void
    {
        $id = (string) Str::uuid();
        $object = ['id' => 'gen_race', 'metadata' => ['local_id' => $id], 'status' => 'succeeded', 'completed_at' => now()->toISOString(),
            'usage' => array_combine(Pricing::CATEGORIES, [7, 765, 1100, 201]),
            'rates' => array_combine(Pricing::CATEGORIES, ['5', '10', '40', '12.50'])];
        Generation::create(['id' => $id, 'user_id' => User::factory()->create()->id, 'submission_key' => (string) Str::uuid(), 'provider_id' => 'gen_race', 'payload' => ['metadata' => $object['metadata']], 'recover_at' => now()->subMinute()]);
        $workers = [];
        foreach (['poll', 'webhook'] as $entry) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Fixtures/settlement-worker.php'), $entry], base_path(), ['DB_DATABASE' => 'pixelforge_test', 'APP_ENV' => 'testing'], json_encode($object), 15);
        }
        $this->runContested($workers, fn () => Generation::whereKey($id)->lockForUpdate()->firstOrFail());
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertDatabaseHas('generations', ['id' => $id, 'status' => 'succeeded']);
        $this->assertSame('0.067746875000', DB::table('final_bills')->value('customer_charge'));
    }

    public function test_two_submissions_cannot_reserve_the_same_credit(): void
    {
        $user = User::factory()->create();
        DB::table('wallet_topups')->insert(['user_id' => $user->id, 'purchase_key' => (string) Str::uuid(), 'amount' => '0.500000000', 'created_at' => now()]);
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $workers[] = new Process([PHP_BINARY, base_path('tests/Fixtures/submission-worker.php'), (string) $user->id], base_path(), ['DB_DATABASE' => 'pixelforge_test', 'APP_ENV' => 'testing'], null, 15);
        }
        $this->runContested($workers, fn () => User::whereKey($user->id)->lockForUpdate()->firstOrFail());
        $results = array_map(fn (Process $p) => json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR), $workers);
        $this->assertEqualsCanonicalizing([202, 402], array_column($results, 'status'));
        $this->assertSame(1, array_sum(array_column($results, 'provider_submits')));
        $this->assertDatabaseCount('wallet_holds', 1);
        $this->assertDatabaseCount('generations', 1);
        $this->assertSame('0.032656250000', app(Wallet::class)->balance($user->id)['available']);
    }

    private function runContested(array $workers, Closure $lock): void
    {
        DB::beginTransaction();
        try {
            $lock();
            foreach ($workers as $worker) {
                $worker->start();
            }
            // Confirm both independent sessions are actually waiting on a database lock.
            $until = microtime(true) + 8;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'pixelforge_race_test')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $until);
            $this->assertSame(2, $waiting, implode("\n", array_map(fn (Process $p) => $p->getOutput().$p->getErrorOutput(), $workers)));
        } finally {
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
            }
        }
        foreach ($workers as $worker) {
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        }
    }
}
