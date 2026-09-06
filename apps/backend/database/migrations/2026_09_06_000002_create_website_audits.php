<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_domain_id')->nullable()->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->index(['domain_id', 'expires_at']);
        });
        Schema::create('website_audit_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_audit_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('kind', 24);
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('retryable')->default(true);
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->json('evidence')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['website_audit_id', 'url_hash']);
        });
        Schema::table('domain_contact_sources', function (Blueprint $table): void {
            $table->foreignId('website_audit_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('domains', function (Blueprint $table): void {
            $table->foreignId('contacts_audit_id')->nullable()->constrained('website_audits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->dropConstrainedForeignId('contacts_audit_id'));
        Schema::table('domain_contact_sources', fn (Blueprint $table) => $table->dropConstrainedForeignId('website_audit_id'));
        Schema::dropIfExists('website_audit_pages');
        Schema::dropIfExists('website_audits');
    }
};
