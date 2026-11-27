<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use App\Pixelforge\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected array $pricing;

    protected array $input = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => ['https://example.org/style.png']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
        config(['services.pixelforge.secret' => 'test-secret']);
    }

    protected function offer(): array
    {
        return $this->postJson('/quotes', $this->input)->assertOk()->json();
    }

    protected function object(string $id, string $status): array
    {
        return ['id' => 'gen_'.$id, 'metadata' => ['local_id' => $id], 'status' => $status,
            'rates' => $this->pricing['rates'], 'usage' => array_combine(Pricing::CATEGORIES, [7, 765, 1100, 201]),
            'completed_at' => '2026-11-26T00:02:00Z'];
    }

    protected function event(string $eventId, array $object, int $offset = 0, bool $tamper = false): TestResponse
    {
        $raw = json_encode(['id' => $eventId, 'type' => 'generation.'.$object['status'], 'data' => ['object' => $object]]);
        $ts = now()->timestamp + $offset;
        $signature = hash_hmac('sha256', $ts.'.'.$raw, config('services.pixelforge.secret'));

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => "t=$ts,v1=$signature"], $raw.($tamper ? ' ' : ''));
    }

    public function test_early_success_replay_and_late_processing_settle_only_once(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            $id = $request['metadata']['local_id'];
            $this->assertSame($id, $request->header('Idempotency-Key')[0]);
            $this->event('evt_success', $this->object($id, 'succeeded'))->assertNoContent();

            return Http::response($this->object($id, 'queued'), 202);
        });
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];
        $id = $this->postJson('/generations', $data)->assertAccepted()->json('id');
        $this->event('evt_late', $this->object($id, 'processing'))->assertNoContent();
        $this->event('evt_success', $this->object($id, 'succeeded'))->assertNoContent();
        $this->event('evt_success_again', $this->object($id, 'succeeded'))->assertNoContent();
        $this->postJson('/generations', $data)->assertAccepted()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('accepted_quotes', 1);
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertDatabaseCount('webhook_receipts', 3);
        $this->assertDatabaseHas('generations', ['id' => $id, 'status' => 'succeeded']);
        $this->assertSame('0.067746875000', DB::table('final_bills')->value('customer_charge'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn ($page) => $page->has('history.data', 0));
    }

    public function test_changed_quote_requires_acceptance_and_invalid_signature_has_no_receipt(): void
    {
        Http::fake(fn () => Http::response($this->pricing));
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];
        $this->pricing['rates']['output_tokens'] = '80';
        $this->postJson('/generations', $data)->assertConflict()->assertJsonStructure(['quote' => ['quote_token']]);
        $this->assertDatabaseCount('generations', 0);
        $object = $this->object((string) Str::uuid(), 'succeeded');
        foreach ([-301, 301] as $offset) {
            $this->event('evt_bad', $object, $offset)->assertBadRequest();
        }
        $this->event('evt_tamper', $object, 0, true)->assertBadRequest();
        $this->assertDatabaseEmpty('webhook_receipts');
    }

    public function test_lost_response_replays_identical_payload_and_recovers_without_a_browser(): void
    {
        $submits = [];
        Http::fake(function ($request) use (&$submits) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            $id = $request['metadata']['local_id'];
            $submits[] = [$request->data(), $request->header('Idempotency-Key')];
            if (count($submits) === 1) {
                throw new ConnectionException('Lost response');
            }

            return Http::response($this->object($id, 'succeeded'), 202);
        });
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];
        $this->postJson('/generations', $data)->assertAccepted();
        $this->assertDatabaseEmpty('final_bills');
        $this->travel(10)->seconds();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertSame($submits[0], $submits[1]);
        $this->assertDatabaseHas('generations', ['status' => 'succeeded']);
        $this->assertDatabaseCount('final_bills', 1);
    }

    public function test_overload_respects_retry_after_and_definitive_rejections_end_free(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/pricing')
            ? Http::response($this->pricing)
            : Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '60']));
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];
        $this->postJson('/generations', $data)->assertAccepted();
        $this->travel(59)->seconds();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertSame(1, DB::table('generations')->value('attempts'));
        for ($attempt = 2; $attempt <= 8; $attempt++) {
            $this->travel(5)->minutes();
            $this->artisan('pixelforge:recover')->assertSuccessful();
        }
        $this->assertDatabaseHas('generations', ['attempts' => 8, 'status' => 'failed', 'recover_at' => null]);
        $this->assertSame('0.000000000000', DB::table('final_bills')->value('customer_charge'));
    }

    private function fundedSubmission(): Generation
    {
        config(['services.pixelforge.wallets_enabled' => true]);
        $this->postJson('/wallet/top-ups', ['amount' => '1.00', 'purchase_key' => (string) Str::uuid()])->assertOk();
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];

        return Generation::findOrFail($this->postJson('/generations', $data)->assertAccepted()->json('id'));
    }

    private function recoverWhenDue(Generation $g): void
    {
        $due = $g->refresh()->recover_at;
        $this->assertNotNull($due, 'Recovery paused before the expected provider call.');
        $this->travelTo($due);
        $this->artisan('pixelforge:recover')->assertSuccessful();
    }

    public function test_scheduled_recovery_settles_after_three_rejections_and_nine_healthy_polls_without_webhooks(): void
    {
        $this->freezeSecond();
        $posts = [];
        $accepted = $polls = 0;
        Http::fake(function (Request $r) use (&$posts, &$accepted, &$polls) {
            if (str_ends_with($r->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if ($r->method() === 'POST') {
                $posts[] = [$r->header('Idempotency-Key'), $r->data()];
                if (count($posts) <= 3) {
                    return Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '30']);
                }
                $accepted++;

                return Http::response($this->object($r['metadata']['local_id'], 'queued'), 202);
            }

            return Http::response($this->object(substr(basename($r->url()), 4), ++$polls <= 9 ? 'processing' : 'succeeded'));
        });
        $g = $this->fundedSubmission();
        for ($i = 0; $i < 13; $i++) {
            $this->recoverWhenDue($g);
        }
        $this->assertCount(4, $posts);
        $this->assertSame(array_fill(0, 4, $posts[0]), $posts);
        $this->assertSame(1, $accepted);
        $this->assertSame(10, $polls);
        $this->assertDatabaseHas('generations', ['id' => $g->id, 'attempts' => 14, 'submit_attempts' => 4, 'status' => 'succeeded']);
        $this->assertDatabaseCount('wallet_holds', 1);
        $this->assertDatabaseCount('webhook_receipts', 0);
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertSame(['available' => '0.932253125000', 'due' => '0.000000000000', 'held' => '0.000000000000',
            'spent' => '0.067746875000'], app(Wallet::class)->balance($this->user->id));
    }

    public function test_a_healthy_poll_clears_the_streak_but_eight_unusable_checks_pause(): void
    {
        $this->freezeSecond();
        $poll = 0;
        Http::fake(function (Request $r) use (&$poll) {
            if (str_ends_with($r->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if ($r->method() === 'POST') {
                return Http::response($this->object($r['metadata']['local_id'], 'queued'), 202);
            }
            $id = substr(basename($r->url()), 4);
            if (++$poll === 8) {
                return Http::response($this->object($id, 'processing'));
            }
            if ($poll > 8) {
                return Http::response(['usage' => null] + $this->object($id, 'succeeded'));
            }

            return Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '30']);
        });
        $g = $this->fundedSubmission();
        foreach ([1, 2, 3, 4, 5, 6, 7, 0, 1, 2, 3, 4, 5, 6, 7, 8] as $expected) {
            $this->recoverWhenDue($g);
            $this->assertSame($expected, $g->refresh()->poll_failures);
        }
        $this->assertNull($g->recover_at);
        $this->assertDatabaseEmpty('final_bills');
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertSame(16, $poll);
    }

    public function test_an_overdue_generation_gets_one_operator_poll_and_one_bill_with_a_late_webhook(): void
    {
        $this->freezeSecond();
        $polls = 0;
        Http::fake(function (Request $r) use (&$polls) {
            if (str_ends_with($r->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if ($r->method() === 'POST') {
                return Http::response($this->object($r['metadata']['local_id'], 'processing'), 202);
            }
            $polls++;
            $object = $this->object(substr(basename($r->url()), 4), 'succeeded');
            $this->event('evt_late_success', $object)->assertNoContent();

            return Http::response($object);
        });
        $g = $this->fundedSubmission();
        $this->travel(60)->minutes();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertNull($g->refresh()->recover_at);
        $this->assertSame(0, $polls);
        $this->event('evt_late_processing', $this->object($g->id, 'processing'))->assertNoContent();
        $this->assertNull($g->refresh()->recover_at);
        $this->assertFalse(app(Wallet::class)->holdWasReleased($g));
        $this->travelTo($g->created_at->copy()->addHours(24));
        $this->assertTrue(app(Wallet::class)->holdWasReleased($g));
        $this->artisan('pixelforge:recover', ['--resume' => $g->id])->assertSuccessful();
        $this->artisan('pixelforge:recover', ['--resume' => $g->id])->assertSuccessful();
        $this->assertSame(1, $polls);
        $this->assertSame(2, $g->refresh()->attempts);
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertSame('0.067746875000', app(Wallet::class)->balance($this->user->id)['spent']);
    }

    public function test_a_one_hour_operator_recheck_that_is_still_processing_stays_paused_until_the_webhook(): void
    {
        $this->freezeSecond();
        $polls = 0;
        Http::fake(function (Request $r) use (&$polls) {
            if (str_ends_with($r->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if ($r->method() === 'POST') {
                return Http::response($this->object($r['metadata']['local_id'], 'queued'), 202);
            }
            $polls++;

            return Http::response($this->object(substr(basename($r->url()), 4), 'processing'));
        });
        $g = $this->fundedSubmission();
        $this->travel(60)->minutes();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertNull($g->refresh()->recover_at);
        $this->artisan('pixelforge:recover', ['--resume' => $g->id])->assertSuccessful();
        $this->assertSame(1, $polls);
        $this->assertNull($g->refresh()->recover_at);
        $this->travel(2)->minutes();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertSame(1, $polls);
        $this->assertFalse(app(Wallet::class)->holdWasReleased($g));
        $this->event('evt_after_recheck', $this->object($g->id, 'succeeded'))->assertNoContent();
        $this->event('evt_after_recheck', $this->object($g->id, 'succeeded'))->assertNoContent();
        $this->assertDatabaseCount('final_bills', 1);
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertSame('0.932253125000', app(Wallet::class)->balance($this->user->id)['available']);
    }

    public function test_claims_inherit_mixed_revision_writes_and_leave_legacy_pauses_under_operator_control(): void
    {
        $this->freezeSecond();
        Http::fake(fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['error' => ['code' => 'overloaded']], 503)
            : Http::response($this->object(substr(basename($r->url()), 4), 'processing')));
        $legacy = fn () => Generation::create(['user_id' => $this->user->id, 'submission_key' => (string) Str::uuid(),
            'payload' => $this->input, 'attempts' => 6, 'recover_at' => now()]);
        $active = $legacy();
        $accepted = $legacy();
        $accepted->update(['provider_id' => 'gen_'.$accepted->id, 'attempts' => 8]);
        $paused = $legacy();
        $paused->update(['recover_at' => null]);
        // The old worker knows only attempts and can retry even after new columns exist.
        DB::table('generations')->where('id', $active->id)->increment('attempts');
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertDatabaseHas('generations', ['id' => $active->id, 'attempts' => 8, 'submit_attempts' => 8, 'status' => 'failed']);
        $this->assertDatabaseHas('generations', ['id' => $accepted->id, 'attempts' => 9, 'poll_failures' => 0, 'status' => 'processing']);
        $this->assertNotNull($accepted->refresh()->recover_at);
        $this->assertNull($paused->refresh()->recover_at);
        $this->assertSame(6, $paused->attempts);
        Http::assertSentCount(2);
    }

    public function test_ambiguous_submit_followed_by_quota_error_stays_recoverable(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if (++$requests === 1) {
                throw new ConnectionException('Lost response');
            }

            return Http::response(['error' => ['code' => 'daily_limit_exceeded']], 429);
        });
        $data = $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']];
        $this->postJson('/generations', $data)->assertAccepted();
        $this->travel(10)->seconds();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertDatabaseHas('generations', ['status' => 'queued', 'recover_at' => null, 'submission_uncertain' => true]);
        $this->assertDatabaseEmpty('final_bills');
    }

    public function test_receipt_rolls_back_with_failed_settlement_and_redelivery_succeeds(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/pricing')
            ? Http::response($this->pricing)
            : Http::response($this->object($request['metadata']['local_id'], 'queued'), 202));
        $id = $this->postJson('/generations', $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $this->offer()['quote_token']])->json('id');
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "final_bills"')) {
                $fail = false;
                throw new \RuntimeException('Simulated settlement write failure.');
            }
        });
        $this->event('evt_retry', $this->object($id, 'succeeded'))->assertServerError();
        $this->assertDatabaseEmpty('webhook_receipts');
        $this->assertDatabaseEmpty('final_bills');
        $this->event('evt_retry', $this->object($id, 'succeeded'))->assertNoContent();
        $this->assertDatabaseCount('webhook_receipts', 1);
        $this->assertDatabaseCount('final_bills', 1);
    }

    public function test_signed_ping_is_durable_and_a_missing_secret_fails_closed(): void
    {
        $raw = '{"id":"evt_ping","type":"ping"}';
        $ts = now()->timestamp;
        $send = fn () => $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$raw, 'test-secret')], $raw);
        $send()->assertNoContent();
        $send()->assertNoContent();
        $this->assertDatabaseCount('webhook_receipts', 1);
        config(['services.pixelforge.secret' => '']);
        $send()->assertServiceUnavailable();
    }

    public function test_input_bounds_and_intentional_repeat(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/pricing')
            ? Http::response($this->pricing)
            : Http::response($this->object($request['metadata']['local_id'], 'failed'), 202));
        $this->postJson('/quotes', ['prompt' => str_repeat('🌊', 4001)])->assertJsonValidationErrors('prompt');
        $this->postJson('/quotes', ['prompt' => '  '])->assertJsonValidationErrors('prompt');
        $this->postJson('/quotes', ['prompt' => 'A', 'reference_images' => array_fill(0, 5, 'https://example.org')])->assertJsonValidationErrors('reference_images');
        $this->postJson('/quotes', ['prompt' => 'A', 'reference_images' => ['file:///tmp/a']])->assertJsonValidationErrors('reference_images.0');
        $this->postJson('/quotes', ['prompt' => str_repeat('🌊', 4000), 'reference_images' => array_fill(0, 4, 'https://example.org')])->assertOk();
        $quote = $this->offer();
        for ($n = 0; $n < 2; $n++) {
            $this->postJson('/generations', $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $quote['quote_token']])->assertAccepted();
        }
        $this->assertDatabaseCount('generations', 2);
        $this->assertDatabaseCount('final_bills', 2);
        $this->assertEquals(0, DB::table('final_bills')->sum('customer_charge'));
    }

    public function test_accepted_quote_survives_provider_rate_change_before_creation(): void
    {
        $this->input = ['prompt' => str_repeat('🌊', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.org/a', 'https://example.org/b']];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            $object = $this->object($request['metadata']['local_id'], 'succeeded');
            $object['rates']['output_tokens'] = '80';
            $object['usage'] = array_combine(Pricing::CATEGORIES, [115, 1530, 6450, 8000]);

            return Http::response($object, 202);
        });
        $quote = $this->offer();
        $this->postJson('/generations', $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $quote['quote_token']])->assertAccepted();
        $this->assertSame('0.467343750000', DB::table('accepted_quotes')->value('maximum'));
        $bill = DB::table('final_bills')->first();
        $this->assertSame('0.631875000000', $bill->provider_cost);
        $this->assertSame('0.789843750000', $bill->customer_charge);
    }

    public function test_incomplete_success_awaits_authoritative_usage_even_with_a_late_processing_event(): void
    {
        $submissions = 0;
        Http::fake(function ($request) use (&$submissions) {
            if (str_ends_with($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }
            if ($request->method() === 'POST') {
                $submissions++;
                $object = $this->object($request['metadata']['local_id'], 'succeeded');
                $object['usage'] = null;

                return Http::response($object, 202);
            }
            $id = substr(basename($request->url()), 4);

            return Http::response($this->object($id, 'succeeded'));
        });
        $quote = $this->offer();
        $id = $this->postJson('/generations', $this->input + ['submission_key' => (string) Str::uuid(), 'quote_token' => $quote['quote_token']])->json('id');
        $this->event('evt_late', $this->object($id, 'processing'))->assertNoContent();
        $this->assertDatabaseHas('generations', ['id' => $id, 'status' => 'succeeded']);
        $this->assertDatabaseEmpty('final_bills');
        $this->travel(2)->minutes();
        $this->artisan('pixelforge:recover')->assertSuccessful();
        $this->assertSame(1, $submissions);
        $this->assertDatabaseCount('final_bills', 1);
    }
}
