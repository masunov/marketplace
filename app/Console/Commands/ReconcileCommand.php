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

        $this->line('<comment>денежный итог</comment>');
        $this->line(sprintf('  оплачено             %10s', number_format($money['paid_total'], 2, '.', ' ')));
        $this->line(sprintf('  из них выдано        %10s', number_format($money['delivered_total'], 2, '.', ' ')));
        $this->line(sprintf('  ждет выдачи          %10s', number_format($money['pending_total'], 2, '.', ' ')));
        $this->line(sprintf('  выдано без оплаты    %10s', number_format($money['issued_not_paid_total'], 2, '.', ' ')));
        $this->line('  сходится: ' . ($money['balanced'] ? '<info>да</info>' : '<error>НЕТ</error>'));

        $this->section('оплачен, но не выдан', $report['paid_not_issued'], ['id', 'status', 'price', 'updated_at']);
        $this->section('выдан, но не оплачен', $report['issued_not_paid'], ['id', 'status', 'price', 'updated_at']);
        $this->section('ключ выдан, статус не delivered', $report['issued_not_delivered'], ['id', 'status', 'price']);
        $this->section('расхождение суммы', $report['amount_mismatch'], ['ps_callback_id', 'order_id', 'amount', 'price']);
        $this->section('необработанные вебхуки', $report['unprocessed_callbacks'], ['ps_callback_id', 'order_id', 'payment_status', 'created_at']);
        $this->section('код сожжен у другого поставщика', $report['orphaned_vendor_keys'], ['order_id', 'delivered_by', 'delivered_key', 'burned_at', 'burned_key']);

        $this->newLine();

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
