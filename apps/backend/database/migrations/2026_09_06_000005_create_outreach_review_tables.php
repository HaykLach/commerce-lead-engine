<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_drafts', fn (Blueprint $table) => $table->index('created_at', 'draft_report_date'));
        Schema::table('website_audits', function (Blueprint $table): void {
            $table->index('created_at', 'audit_report_date');
            $table->index('status', 'audit_report_status');
        });
        Schema::create('outreach_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outreach_draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_domain_id')->nullable()->unique()->constrained('domains')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('draft_revision');
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('body');
            $table->string('from_email');
            $table->string('from_name');
            $table->string('message_id')->unique();
            $table->string('status')->default('scheduled');
            $table->timestamp('approved_at');
            $table->timestamp('scheduled_at');
            $table->timestamp('next_attempt_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('send_started_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('outreach_delivery_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->timestamp('last_attempt_at')->nullable();
        });
        DB::table('outreach_delivery_locks')->insert(['id' => 1]);
        Schema::create('daily_outreach_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date')->unique();
            $table->json('summary');
            $table->string('notification_status')->default('pending');
            $table->string('telegram_message_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('notification_started_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_drafts', fn (Blueprint $table) => $table->dropIndex('draft_report_date'));
        Schema::table('website_audits', function (Blueprint $table): void {
            $table->dropIndex('audit_report_date');
            $table->dropIndex('audit_report_status');
        });
        Schema::dropIfExists('daily_outreach_reports');
        Schema::dropIfExists('outreach_delivery_locks');
        Schema::dropIfExists('outreach_messages');
    }
};
