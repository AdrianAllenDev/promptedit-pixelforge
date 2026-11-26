<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
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
        DB::beginTransaction();
        try {
            Generation::whereKey($id)->lockForUpdate()->firstOrFail();
            foreach (['poll', 'webhook'] as $entry) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/settlement-worker.php'), $entry], base_path(), ['DB_DATABASE' => 'pixelforge_test', 'APP_ENV' => 'testing'], json_encode($object), 15);
                $worker->start();
                $workers[] = $worker;
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
            $this->assertSame(2, $waiting);
        } finally {
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
            }
        }
        foreach ($workers as $worker) {
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        }
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertDatabaseHas('generations', ['id' => $id, 'status' => 'succeeded']);
        $this->assertSame('0.067746875000', DB::table('final_bills')->value('customer_charge'));
    }
}
