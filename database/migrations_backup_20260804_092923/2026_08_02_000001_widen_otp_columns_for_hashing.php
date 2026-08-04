<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the OTP-hashing hardening change: verification_codes.code and
 * users.pending_email_code previously stored a raw 6-character code and were
 * likely sized accordingly (e.g. varchar(6)). A bcrypt hash is ~60
 * characters, so both columns need widening before AuthController/
 * VerificationCode start writing Hash::make() output into them.
 *
 * Run this BEFORE deploying the updated AuthController/VerificationCode
 * code — otherwise the first Hash::make()'d value will be silently
 * truncated by the DB and every subsequent Hash::check() will fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->string('code', 255)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email_code', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversing to varchar(6) — doing so with hashed
        // data already present would truncate and corrupt existing rows.
        // If you need to roll back, clear both columns first.
    }
};
