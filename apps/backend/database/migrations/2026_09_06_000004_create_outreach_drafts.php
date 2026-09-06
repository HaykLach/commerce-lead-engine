<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('active_domain_id')->nullable()->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->string('status', 20)->default('queued');
            $table->string('input_hash', 64)->index();
            $table->json('evidence');
            $table->json('prompt');
            $table->string('prompt_version', 64);
            $table->string('model', 100);
            $table->string('response_model', 100)->nullable();
            $table->string('response_id', 100)->nullable();
            $table->json('usage')->nullable();
            $table->json('selected_issue_ids')->nullable();
            $table->string('subject', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('generated_subject', 160)->nullable();
            $table->text('generated_body')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error', 512)->nullable();
            $table->timestamps();
            $table->index(['domain_id', 'website_audit_id', 'domain_contact_id'], 'draft_source_lookup');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_drafts');
    }
};
