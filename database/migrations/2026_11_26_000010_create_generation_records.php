<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->uuid('submission_key');
            $t->unique(['user_id', 'submission_key']);
            $t->jsonb('payload');
            $t->string('provider_id')->nullable()->unique();
            $t->string('status')->default('queued');
            $t->text('message')->nullable();
            $t->text('image_url')->nullable();
            $t->timestampTz('image_expires_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('recover_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('accepted_quotes', function (Blueprint $t) {
            $t->foreignUuid('generation_id')->primary()->constrained('generations');
            $t->jsonb('pricing');
            $t->decimal('minimum', 24, 12);
            $t->decimal('maximum', 24, 12);
            $t->timestampTz('quoted_at');
            $t->timestampTz('accepted_at');
        });
        Schema::create('final_bills', function (Blueprint $t) {
            $t->foreignUuid('generation_id')->primary()->constrained('generations');
            $t->jsonb('usage')->nullable();
            $t->jsonb('rates')->nullable();
            foreach (['provider_cost', 'customer_charge', 'profit'] as $amount) {
                $t->decimal($amount, 24, 12);
            }
            $t->timestampTz('created_at');
        });
        Schema::create('webhook_receipts', function (Blueprint $t) {
            $t->string('event_id')->primary();
            $t->string('type');
            $t->jsonb('payload');
            $t->string('outcome');
            $t->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        foreach (['webhook_receipts', 'final_bills', 'accepted_quotes', 'generations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
