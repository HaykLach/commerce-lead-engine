<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_speed_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_audit_page_id')->constrained()->cascadeOnDelete();
            $table->string('strategy', 10);
            $table->string('active_key', 80)->nullable()->unique();
            $table->string('requested_url', 2048);
            $table->string('final_url', 2048)->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error', 512)->nullable();
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->string('lighthouse_version', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['website_audit_page_id', 'strategy', 'expires_at'], 'psi_page_strategy_expiry');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_speed_measurements');
    }
};
