<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('suggested_purpose', 32)->default('unknown');
            $table->string('purpose_override', 32)->nullable();
            $table->string('review_status', 16)->default('candidate');
            $table->boolean('is_manual')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'email']);
        });

        Schema::create('domain_contact_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_contact_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->json('methods');
            $table->string('original_hint', 64)->nullable();
            $table->foreignId('page_classification_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['domain_contact_id', 'url_hash'], 'contact_source_url_unique');
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->foreignId('primary_contact_id')->nullable()->constrained('domain_contacts')->nullOnDelete();
            $table->string('contact_selection_mode', 16)->default('automatic');
            $table->foreignId('contacts_classification_id')->nullable()->constrained('page_classifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('primary_contact_id');
            $table->dropConstrainedForeignId('contacts_classification_id');
            $table->dropColumn('contact_selection_mode');
        });
        Schema::dropIfExists('domain_contact_sources');
        Schema::dropIfExists('domain_contacts');
    }
};
