<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domain_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('crawl_job_id')->nullable()->constrained('crawl_jobs')->nullOnDelete();
            $table->string('platform')->nullable()->index();
            $table->decimal('confidence', 5, 2)->default(0)->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('niche')->nullable()->index();
            $table->string('business_model')->nullable()->index();
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('product_pages')->default(0);
            $table->unsignedInteger('collection_pages')->default(0);
            $table->unsignedInteger('blog_pages')->default(0);
            $table->unsignedInteger('contact_pages')->default(0);
            $table->boolean('has_cart')->default(false);
            $table->boolean('has_checkout')->default(false);
            $table->json('raw_signals')->nullable();
            $table->json('signal_summary')->nullable();
            $table->timestamp('measured_at')->useCurrent();
            $table->timestamps();

            $table->index(['domain_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_metrics');
    }
};
