<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_holds', function (Blueprint $table) {
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('admins');
            $table->text('release_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_holds', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'released_by', 'release_reason']);
        });
    }
};
