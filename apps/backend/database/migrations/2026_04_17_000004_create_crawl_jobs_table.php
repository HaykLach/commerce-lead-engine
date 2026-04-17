<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crawl_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('recrawl_of_job_id')->nullable()->constrained('crawl_jobs')->nullOnDelete();
            $table->string('status')->default('queued');
            $table->string('trigger_type')->default('discovery');
            $table->unsignedTinyInteger('priority')->default(5);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_crawl_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('crawl_payload')->nullable();
            $table->json('crawl_summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['domain_id', 'next_crawl_at']);
            $table->index('trigger_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_jobs');
    }
};
