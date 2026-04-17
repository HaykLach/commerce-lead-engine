<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fingerprints', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('platform')->index();
            $table->string('version')->nullable();
            $table->integer('priority')->default(100);
            $table->decimal('confidence_weight', 5, 2)->default(1.00);
            $table->json('rules');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'platform']);
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprints');
    }
};
