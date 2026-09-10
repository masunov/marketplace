<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach ($this->indexes() as $name => [$type, $columns]) {
            $this->dropIfInvalid($name);

            DB::statement("CREATE {$type} CONCURRENTLY IF NOT EXISTS {$name} ON order_processing_logs {$columns}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'SELECT 1
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ?
                AND NOT i.indisvalid',
            [$name]
        );

        if ($invalid) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function indexes(): array
    {
        return [
            'order_processing_logs_parent_log_id_unique'             => ['UNIQUE INDEX', '(parent_log_id)'],
            'order_processing_logs_attempt_type_unique'              => ['UNIQUE INDEX', '(attempt_request_id, type)'],
            'order_processing_logs_order_item_id_triggered_at_index' => ['INDEX', '(order_item_id, triggered_at)'],
        ];
    }
};
