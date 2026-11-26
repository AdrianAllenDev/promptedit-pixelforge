<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained();
            $t->uuid('purchase_key');
            $t->primary(['user_id', 'purchase_key']);
            $t->decimal('amount', 24, 12);
            $t->timestampTz('created_at');
        });
        Schema::create('wallet_holds', function (Blueprint $t) {
            $t->foreignUuid('generation_id')->primary()->constrained('generations');
            $t->decimal('amount', 24, 12);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
        Schema::dropIfExists('wallet_topups');
    }
};
