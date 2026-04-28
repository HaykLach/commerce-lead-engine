<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('common_crawl_domains', function (Blueprint $table): void {
            if (!Schema::hasColumn('common_crawl_domains', 'language_signals')) {
                $table->json('language_signals')->nullable()->after('country_signals');
            }
        });
    }

    public function down(): void
    {
        Schema::table('common_crawl_domains', function (Blueprint $table): void {
            if (Schema::hasColumn('common_crawl_domains', 'language_signals')) {
                $table->dropColumn('language_signals');
            }
        });
    }
};
