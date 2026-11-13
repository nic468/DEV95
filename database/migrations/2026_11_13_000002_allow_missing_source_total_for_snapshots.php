<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropReconciliationConstraints();

        Schema::table('upload_snapshots', function (Blueprint $table): void {
            $table->decimal('source_total', 19, 2)->nullable()->change();
            $table->decimal('reconciliation_difference', 19, 2)->nullable()->change();
        });

        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_status CHECK (reconciliation_status IN ('matched', 'confirmed_mismatch', 'source_total_missing'))"
        );
        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_difference CHECK ((reconciliation_status = 'source_total_missing' AND source_total IS NULL AND reconciliation_difference IS NULL) OR (reconciliation_status IN ('matched', 'confirmed_mismatch') AND source_total IS NOT NULL AND reconciliation_difference = imported_total - source_total))"
        );
        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_confirmation CHECK ((reconciliation_status = 'matched' AND reconciliation_difference = 0) OR (reconciliation_status = 'confirmed_mismatch' AND reconciliation_difference <> 0 AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL) OR (reconciliation_status = 'source_total_missing' AND source_total IS NULL AND reconciliation_difference IS NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        if (DB::table('upload_snapshots')->where('reconciliation_status', 'source_total_missing')->exists()) {
            throw new RuntimeException(
                'The reconciliation migration cannot be rolled back while snapshots without a source total exist.'
            );
        }

        $this->dropReconciliationConstraints();

        Schema::table('upload_snapshots', function (Blueprint $table): void {
            $table->decimal('source_total', 19, 2)->nullable(false)->change();
            $table->decimal('reconciliation_difference', 19, 2)->nullable(false)->change();
        });

        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_status CHECK (reconciliation_status IN ('matched', 'confirmed_mismatch'))"
        );
        DB::statement(
            'ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_difference CHECK (reconciliation_difference = imported_total - source_total)'
        );
        DB::statement(
            "ALTER TABLE upload_snapshots ADD CONSTRAINT ck_snapshot_reconciliation_confirmation CHECK ((reconciliation_status = 'matched' AND reconciliation_difference = 0) OR (reconciliation_status = 'confirmed_mismatch' AND reconciliation_difference <> 0 AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))"
        );
    }

    private function dropReconciliationConstraints(): void
    {
        DB::statement('ALTER TABLE upload_snapshots DROP CONSTRAINT ck_snapshot_reconciliation_status');
        DB::statement('ALTER TABLE upload_snapshots DROP CONSTRAINT ck_snapshot_reconciliation_difference');
        DB::statement('ALTER TABLE upload_snapshots DROP CONSTRAINT ck_snapshot_reconciliation_confirmation');
    }
};
