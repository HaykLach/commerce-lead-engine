<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('page_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('crawl_job_id')->nullable()->constrained('crawl_jobs')->nullOnDelete();
            $table->string('url');
            $table->string('canonical_url')->nullable();
            $table->string('page_type')->default('unknown');
            $table->decimal('confidence', 5, 2)->default(0)->index();
            $table->json('signals')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'page_type']);
            $table->index('crawl_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_classifications');
    }
};
