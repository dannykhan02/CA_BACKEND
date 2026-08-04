<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Widened up front for Hash::make() output (bcrypt ~60 chars),
            // instead of a later "widen_otp_columns_for_hashing" patch.
            $table->string('code', 255);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
