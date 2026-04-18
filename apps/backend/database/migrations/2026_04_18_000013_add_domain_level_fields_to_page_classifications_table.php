<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('page_classifications', function (Blueprint $table): void {
            $table->boolean('product_page_found')->default(false)->after('confidence');
            $table->boolean('category_page_found')->default(false)->after('product_page_found');
            $table->boolean('cart_page_found')->default(false)->after('category_page_found');
            $table->boolean('checkout_page_found')->default(false)->after('cart_page_found');
            $table->string('sample_product_url')->nullable()->after('checkout_page_found');
            $table->string('sample_category_url')->nullable()->after('sample_product_url');
            $table->string('sample_cart_url')->nullable()->after('sample_category_url');
            $table->string('sample_checkout_url')->nullable()->after('sample_cart_url');
            $table->unsignedInteger('product_count_guess')->nullable()->after('sample_checkout_url');
            $table->string('product_count_bucket', 20)->nullable()->after('product_count_guess');
            $table->json('classification_metadata')->nullable()->after('product_count_bucket');
            $table->timestamp('classified_at')->nullable()->after('classification_metadata');

            $table->index(['domain_id', 'classified_at'], 'page_classifications_domain_classified_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('page_classifications', function (Blueprint $table): void {
            $table->dropIndex('page_classifications_domain_classified_at_idx');
            $table->dropColumn([
                'product_page_found',
                'category_page_found',
                'cart_page_found',
                'checkout_page_found',
                'sample_product_url',
                'sample_category_url',
                'sample_cart_url',
                'sample_checkout_url',
                'product_count_guess',
                'product_count_bucket',
                'classification_metadata',
                'classified_at',
            ]);
        });
    }
};
