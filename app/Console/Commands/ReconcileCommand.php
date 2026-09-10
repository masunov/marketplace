<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class ReconcileCommand extends Command
{
    protected $signature = 'marketplace:reconcile
        {--stale-minutes=15 : с какого возраста считать заказ подозрительным}
        {--json : вывести отчет в JSON}';

    protected $description = 'Сверка: оплачен но не выдан, выдан но не оплачен, расхождения сумм';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $report = $reconciliationService->report((int) $this->option('stale-minutes'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report['anomalies'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $money = $report['money'];

        $this->info('денежный итог');
        $this->line(sprintf('  оплачено             %10s', number_format($money['charged_total'], 2, '.', ' ')));
        $this->line(sprintf('  из них выдано        %10s', number_format($money['delivered_total'], 2, '.', ' ')));
        $this->line(sprintf('  возвращено           %10s', number_format($money['refunded_total'], 2, '.', ' ')));
        $this->line(sprintf('  еще в работе         %10s', number_format($money['in_flight_total'], 2, '.', ' ')));
        $this->newLine();

        $this->section('заказ закрыт, деньги не разнесены', $report['unsettled_closed_orders'],
            ['order_id', 'status', 'charged', 'delivered', 'refunded', 'unsettled']);
        $this->section('оплачено, но ни кода ни возврата', $report['paid_but_not_settled'],
            ['id', 'order_id', 'status', 'price', 'updated_at']);
        $this->section('контент выдан, статус не delivered', $report['content_without_status'],
            ['id', 'order_id', 'status']);
        $this->section('платежная система отказала в возврате', $report['rejected_refunds'],
            ['order_item_id', 'price', 'failed_reason', 'processed_at']);
        $this->section('позиции отработали, заказ не закрыт', $report['stalled_before_finalize'],
            ['id', 'status', 'total_amount']);
        $this->section('расхождение суммы', $report['amount_mismatch'],
            ['ps_callback_id', 'order_id', 'amount']);
        $this->section('необработанные вебхуки', $report['unprocessed_callbacks'],
            ['ps_callback_id', 'order_id', 'payment_status', 'created_at']);

        $this->newLine();
        $this->info('наблюдения (разбираются автоматически)');
        $this->section('оплата не подтвердилась, похоже на потерянный вебхук',
            $report['observations']['unpaid_orders'], ['id', 'total_amount', 'created_at']);
        $this->section('коды поставщиков отвергнуты',
            $report['observations']['rejected_vendor_codes'], ['vendor', 'result', 'count']);
        $this->section('код сожжен у поставщика, но не выдан',
            $report['observations']['orphaned_vendor_codes'],
            ['vendor_name', 'sku', 'key', 'attempt_request_id']);

        if ($report['anomalies'] > 0) {
            $this->error("расхождений: {$report['anomalies']}");

            return self::FAILURE;
        }

        $this->info('расхождений нет');

        return self::SUCCESS;
    }

    private function section(string $title, iterable $rows, array $columns): void
    {
        $rows = collect($rows);

        $this->newLine();
        $this->line("<comment>{$title}</comment>: {$rows->count()}");

        if ($rows->isEmpty()) {
            return;
        }

        $this->table(
            $columns,
            $rows->take(20)->map(
                static function ($row) use ($columns): array {
                    $data = $row instanceof Model ? $row->toArray() : (array) $row;

                    return array_map(
                        static fn(string $column): string => (string) ($data[$column] ?? ''),
                        $columns
                    );
                }
            )->all()
        );
    }
}
