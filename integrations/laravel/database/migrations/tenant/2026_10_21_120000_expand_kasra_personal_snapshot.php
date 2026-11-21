<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A month of daily columns plus long request descriptions is encrypted
        // and base64 encoded; it must not be limited to MySQL TEXT's 64 KiB.
        Schema::table('kasra_personal_connections', fn (Blueprint $table) => $table->longText('snapshot')->nullable()->change());
    }

    public function down(): void
    {
        // Keep the larger column on rollback so existing reports cannot truncate.
    }
};
