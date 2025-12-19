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
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('gmail_id')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('from');
            $table->string('to');
            $table->string('subject')->nullable();
            $table->dateTime('date')->nullable();
            $table->boolean('remote_delete')->default(false);
            $table->timestamps();
            $table->index('user_id');
            $table->index('gmail_id');
            $table->index('from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
