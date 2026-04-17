<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domain_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_name');
            $table->string('source_reference')->nullable();
            $table->timestamp('discovered_at')->useCurrent();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_name']);
            $table->index('discovered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_sources');
    }
};
