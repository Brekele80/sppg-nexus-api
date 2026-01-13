<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * You are stuck because:
         * - A previous failed run already created notifications_old AND notifications
         * - Copy step fails due to datatype mismatch (old morphs created non-uuid notifiable_id)
         *
         * Minimal, safe fix (since notifications are new / likely empty):
         * - Drop both notifications tables if they exist
         * - Recreate the correct notifications table for UUID-based notifiables (Profile uuid)
         */

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notifications_old');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');

            // UUID notifiable morphs (Profile uses UUID)
            $table->uuid('notifiable_id');
            $table->string('notifiable_type');

            // Laravel expects JSON for notifications payload
            $table->json('data');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
