<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('common_crawl_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 255);
            $table->string('tld', 20);
            $table->double('ecommerce_score')->default(0);
            $table->json('matched_patterns')->nullable();
            $table->text('source_url')->nullable();
            $table->string('crawl_id', 80)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('domain', 'uniq_common_crawl_domain');
            $table->index(['tld', 'ecommerce_score'], 'idx_common_crawl_tld_score');
            $table->index('ecommerce_score', 'idx_common_crawl_score');
            $table->index('last_seen_at', 'idx_common_crawl_last_seen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('common_crawl_domains');
    }
};
