<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domain_fingerprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('platform')->nullable()->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('frontend_stack')->nullable();
            $table->json('signals')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('whatweb_payload')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_fingerprints');
    }
};
