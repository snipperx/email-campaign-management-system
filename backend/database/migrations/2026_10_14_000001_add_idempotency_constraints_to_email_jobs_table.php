<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_jobs', function (Blueprint $table) {
            // Queue job UUID of the worker that currently owns the record
            $table->uuid('claim_token')->nullable()->after('status');

            $table->unique(['campaign_id', 'recipient_email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_jobs', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'recipient_email']);
            $table->dropColumn('claim_token');
        });
    }
};
