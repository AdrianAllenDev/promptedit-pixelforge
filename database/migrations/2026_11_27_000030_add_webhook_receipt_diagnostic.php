<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_receipts', fn (Blueprint $table) => $table->string('diagnostic')->nullable());
    }

    public function down(): void
    {
        Schema::table('webhook_receipts', fn (Blueprint $table) => $table->dropColumn('diagnostic'));
    }
};
