<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Widens the check constraint behind users.role with the three roles added
     * to App\Enums\UserRole: export, user and shipment. Purely additive: every
     * existing row keeps a valid value.
     *
     * Raw SQL on purpose: Postgres rejects the `->change()` of an enum column,
     * which Laravel stores as a varchar plus the `users_role_check` constraint.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('administrator', 'carrier', 'pilot', 'manager', 'export', 'user', 'shipment'))");
    }

    /**
     * Reverse the migrations.
     *
     * Fails while any user still holds one of the new roles, on purpose: the
     * rollback would otherwise have to decide what those accounts become.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('administrator', 'carrier', 'pilot', 'manager'))");
    }
};
