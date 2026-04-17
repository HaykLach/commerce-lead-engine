<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('score_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('version')->default('v1');
            $table->boolean('is_active')->default(false);
            $table->json('weights');
            $table->json('thresholds');
            $table->json('rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index(['is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_configs');
    }
};
