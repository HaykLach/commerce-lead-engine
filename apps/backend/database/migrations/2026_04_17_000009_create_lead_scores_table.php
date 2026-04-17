<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('crawl_job_id')->nullable()->constrained('crawl_jobs')->nullOnDelete();
            $table->foreignId('domain_metric_id')->nullable()->constrained('domain_metrics')->nullOnDelete();
            $table->foreignId('score_config_id')->nullable()->constrained('score_configs')->nullOnDelete();
            $table->decimal('opportunity_score', 5, 2)->default(0)->index();
            $table->string('grade')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('score_reasons')->nullable();
            $table->string('version')->default('v1');
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->index(['domain_id', 'computed_at']);
            $table->index(['grade', 'opportunity_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
    }
};
