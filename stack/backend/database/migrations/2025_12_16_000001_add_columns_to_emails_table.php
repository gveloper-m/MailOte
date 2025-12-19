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
        Schema::table('emails', function (Blueprint $table) {
            if (!Schema::hasColumn('emails', 'thread_id')) {
                $table->string('thread_id')->nullable()->index();
            }
            if (!Schema::hasColumn('emails', 'snippet')) {
                $table->text('snippet')->nullable();
            }
            if (!Schema::hasColumn('emails', 'label_ids')) {
                $table->json('label_ids')->nullable();
            }
            if (!Schema::hasColumn('emails', 'has_attachments')) {
                $table->boolean('has_attachments')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (Schema::hasColumn('emails', 'thread_id')) {
                $table->dropColumn('thread_id');
            }
            if (Schema::hasColumn('emails', 'snippet')) {
                $table->dropColumn('snippet');
            }
            if (Schema::hasColumn('emails', 'label_ids')) {
                $table->dropColumn('label_ids');
            }
            if (Schema::hasColumn('emails', 'has_attachments')) {
                $table->dropColumn('has_attachments');
            }
        });
    }
};
