<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->string('normalized_domain')->unique();
            $table->string('status')->default(DomainStatus::Pending->value);
            $table->string('platform')->nullable()->index();
            $table->decimal('confidence', 5, 2)->default(0)->index();
            $table->string('country', 2)->nullable()->index();
            $table->string('niche')->nullable()->index();
            $table->string('business_model')->nullable()->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_crawled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
