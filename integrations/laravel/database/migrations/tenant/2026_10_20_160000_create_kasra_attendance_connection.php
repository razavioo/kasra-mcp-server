<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kasra_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('kasra')->unique();
            $table->text('credentials');
            $table->uuid('generation');
            $table->unsignedBigInteger('configured_by');
            $table->boolean('enabled')->default(false);
            $table->string('status')->default('configured');
            $table->string('account_name')->nullable();
            $table->string('period_id', 32)->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('kasra_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('kasra_connections')->cascadeOnDelete();
            $table->uuid('generation');
            $table->uuid('snapshot_id')->nullable();
            $table->string('kind', 24);
            $table->string('external_key', 190);
            $table->string('personnel_code')->nullable()->index();
            $table->string('person_name')->nullable();
            $table->string('period', 32)->nullable()->index();
            $table->string('source_status', 32)->nullable();
            $table->text('payload');
            $table->char('fingerprint', 64);
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['connection_id', 'generation', 'kind', 'external_key'], 'kasra_record_source_unique');
        });
        Schema::create('kasra_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('kasra_records');
            $table->unsignedBigInteger('actor_id');
            $table->string('decision', 16);
            $table->char('expected_fingerprint', 64);
            $table->string('status')->default('sending');
            $table->text('note')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['record_id', 'expected_fingerprint'], 'kasra_decision_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kasra_decisions');
        Schema::dropIfExists('kasra_records');
        Schema::dropIfExists('kasra_connections');
    }
};
