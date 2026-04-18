<?php

declare(strict_types=1);

use App\Enums\CrawlTriggerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('crawl_jobs')
            ->where('trigger_type', 'homepage_fetch')
            ->update([
                'trigger_type' => CrawlTriggerType::Manual->value,
                'crawl_payload' => DB::raw("JSON_SET(COALESCE(crawl_payload, JSON_OBJECT()), '$.job_type', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(crawl_payload, '$.job_type')), 'homepage_fetch'))"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('crawl_jobs')
            ->where('trigger_type', CrawlTriggerType::Manual->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(crawl_payload, '$.job_type')) = 'homepage_fetch'")
            ->update([
                'trigger_type' => 'homepage_fetch',
                'updated_at' => now(),
            ]);
    }
};
