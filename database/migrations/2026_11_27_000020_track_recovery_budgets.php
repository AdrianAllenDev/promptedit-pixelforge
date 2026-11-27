<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedInteger('submit_attempts')->nullable();
            $table->unsignedInteger('poll_failures')->default(0);
            $table->timestampTz('review_retry_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', fn (Blueprint $table) => $table->dropColumn(['submit_attempts', 'poll_failures', 'review_retry_after']));
    }
};
