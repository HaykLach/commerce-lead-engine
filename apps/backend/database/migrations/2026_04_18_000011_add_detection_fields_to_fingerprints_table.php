<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->foreignId('domain_id')->nullable()->after('id')->constrained('domains')->nullOnDelete();
            $table->decimal('confidence', 5, 2)->nullable()->after('platform');
            $table->json('frontend_stack')->nullable()->after('confidence_weight');
            $table->json('signals')->nullable()->after('frontend_stack');
            $table->json('raw_payload')->nullable()->after('signals');
            $table->json('whatweb_payload')->nullable()->after('raw_payload');
            $table->timestamp('detected_at')->nullable()->after('whatweb_payload');

            $table->index(['domain_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('fingerprints', function (Blueprint $table): void {
            $table->dropIndex(['domain_id', 'detected_at']);
            $table->dropConstrainedForeignId('domain_id');
            $table->dropColumn([
                'confidence',
                'frontend_stack',
                'signals',
                'raw_payload',
                'whatweb_payload',
                'detected_at',
            ]);
        });
    }
};
