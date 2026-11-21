<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kasra_personal_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('personnel_code', 30)->unique();
            $table->text('credentials')->nullable();
            $table->uuid('generation');
            $table->text('profile');
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('configured');
            $table->longText('snapshot')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('kasra_personal_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('kasra_personal_connections')->cascadeOnDelete();
            $table->uuid('generation');
            $table->uuid('request_key')->unique();
            $table->text('payload');
            $table->string('status')->default('sending');
            $table->text('result')->nullable();
            $table->timestamps();
        });
        Schema::table('attendance_mappings', function (Blueprint $table): void {
            // An external user ID must never be assigned to the local user FK.
            $table->string('linked_tenant_id')->nullable();
            $table->unsignedBigInteger('linked_user_id')->nullable();
            $table->string('linked_account_name')->nullable();
        });
        Schema::table('kasra_records', function (Blueprint $table): void {
            $table->foreignId('cartable_item_id')->nullable()->constrained('cartable_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kasra_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cartable_item_id');
        });
        Schema::table('attendance_mappings', fn (Blueprint $table) => $table->dropColumn(['linked_tenant_id', 'linked_user_id', 'linked_account_name']));
        Schema::dropIfExists('kasra_personal_submissions');
        Schema::dropIfExists('kasra_personal_connections');
    }
};
