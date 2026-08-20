<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom refresh_token semula string(191), cukup untuk token acak lama.
     * Setelah refresh_token berubah format jadi JWT (header.payload.signature),
     * panjangnya bisa >191 karakter, jadi diperlebar ke TEXT supaya tidak
     * terpotong (yang akan membuat pencocokan token selalu gagal).
     *
     * MySQL tidak mengizinkan kolom TEXT/BLOB dipakai sebagai index tanpa
     * panjang key eksplisit, sedangkan migration awal (create_sessions_table)
     * sudah bikin index biasa di refresh_token. Jadi index itu di-drop dulu
     * sebelum ALTER, lalu dibuat ulang sebagai prefix index (191 karakter
     * pertama) supaya lookup token tetap cepat.
     *
     * Pakai raw SQL (bukan Schema::table(...)->change()) supaya tidak butuh
     * paket doctrine/dbal yang belum ter-install di project ini.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->dropIndexIfExists('sessions', 'sessions_refresh_token_index');
            DB::statement('ALTER TABLE `sessions` MODIFY `refresh_token` TEXT NULL');
            DB::statement('ALTER TABLE `sessions` ADD INDEX `sessions_refresh_token_index` (`refresh_token`(191))');
        } elseif ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sessions_refresh_token_index');
            DB::statement('ALTER TABLE sessions ALTER COLUMN refresh_token TYPE TEXT');
            DB::statement('CREATE INDEX sessions_refresh_token_index ON sessions (refresh_token)');
        } elseif ($driver === 'sqlite') {
            // SQLite tidak membatasi panjang string, jadi kolom yang sudah ada
            // sebenarnya sudah bisa menampung string sepanjang apapun.
            // Tidak perlu tindakan tambahan.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->dropIndexIfExists('sessions', 'sessions_refresh_token_index');
            DB::statement('ALTER TABLE `sessions` MODIFY `refresh_token` VARCHAR(191) NULL');
            DB::statement('ALTER TABLE `sessions` ADD INDEX `sessions_refresh_token_index` (`refresh_token`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sessions_refresh_token_index');
            DB::statement('ALTER TABLE sessions ALTER COLUMN refresh_token TYPE VARCHAR(191)');
            DB::statement('CREATE INDEX sessions_refresh_token_index ON sessions (refresh_token)');
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        if ($exists && (int) $exists->cnt > 0) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }
};