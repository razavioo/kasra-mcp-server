<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('kasra_connections', function (Blueprint $table): void {
            $table->dropUnique('kasra_connections_provider_unique');
            $table->unique(['provider', 'configured_by'], 'kasra_provider_manager_unique');
        });
    }
    public function down(): void
    {
        // Multiple managers cannot be collapsed without losing connections.
        if (\Illuminate\Support\Facades\DB::table('kasra_connections')->count() > 1) {
            throw new \RuntimeException('Multiple manager connections exist; retain the additive schema.');
        }
        Schema::table('kasra_connections', function (Blueprint $table): void {
            $table->dropUnique('kasra_provider_manager_unique');
            $table->unique('provider');
        });
    }
};
