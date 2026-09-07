<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_drafts', fn (Blueprint $table) => $table->text('body_html')->nullable());
        Schema::table('outreach_messages', fn (Blueprint $table) => $table->text('body_html')->nullable());
    }

    public function down(): void
    {
        Schema::table('outreach_messages', fn (Blueprint $table) => $table->dropColumn('body_html'));
        Schema::table('outreach_drafts', fn (Blueprint $table) => $table->dropColumn('body_html'));
    }
};
