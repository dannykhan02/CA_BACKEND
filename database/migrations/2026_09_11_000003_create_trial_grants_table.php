<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Independent unique indexes also arbitrate simultaneous signups.
            $table->string('email')->unique();
            $table->string('ip_address', 45)->unique();
            $table->string('fingerprint')->nullable()->unique();
            // Preserve the abuse guard even if its account is deleted.
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_grants');
    }
};
