<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('terms_version', 30);
            $table->string('privacy_version', 30);
            $table->string('method', 20);
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'terms_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
