<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('common_crawl_domains', function (Blueprint $table): void {
            if (!Schema::hasColumn('common_crawl_domains', 'country')) {
                $table->string('country', 10)->nullable()->after('tld');
            }

            if (!Schema::hasColumn('common_crawl_domains', 'country_signals')) {
                $table->json('country_signals')->nullable()->after('matched_patterns');
            }

            $table->index(['country', 'ecommerce_score'], 'idx_common_crawl_country_score');
            $table->index('country', 'idx_common_crawl_country');
        });
    }

    public function down(): void
    {
        Schema::table('common_crawl_domains', function (Blueprint $table): void {
            $table->dropIndex('idx_common_crawl_country_score');
            $table->dropIndex('idx_common_crawl_country');

            if (Schema::hasColumn('common_crawl_domains', 'country_signals')) {
                $table->dropColumn('country_signals');
            }

            if (Schema::hasColumn('common_crawl_domains', 'country')) {
                $table->dropColumn('country');
            }
        });
    }
};
