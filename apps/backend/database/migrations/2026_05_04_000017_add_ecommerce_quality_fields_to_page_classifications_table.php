<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('page_classifications', function (Blueprint $table): void {
            $table->boolean('is_ecommerce')->nullable()->after('checkout_page_found');
            $table->string('detected_language', 10)->nullable()->after('is_ecommerce');

            $table->index(['domain_id', 'is_ecommerce'], 'page_classifications_domain_is_ecommerce_idx');
        });
    }

    public function down(): void
    {
        Schema::table('page_classifications', function (Blueprint $table): void {
            $table->dropIndex('page_classifications_domain_is_ecommerce_idx');
            $table->dropColumn(['is_ecommerce', 'detected_language']);
        });
    }
};
