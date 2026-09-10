<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Entity\PaymentSystem\PaymentCallbackLog;
use App\Domain\Services\PaymentSystems\PsMir\ProcessPaymentCallbackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverPaymentCallbacksCommand extends Command
{
    protected $signature = 'marketplace:recover-callbacks
        {--stale-seconds=60 : сколько событие должно пролежать необработанным}
        {--limit=200 : максимум событий за прогон}
        {--dry-run : только показать, ничего не менять}';

    protected $description = 'Доприменяет платежные вебхуки, оставшиеся необработанными';

    public function handle(ProcessPaymentCallbackService $processPaymentCallbackService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $callbacks = PaymentCallbackLog::unprocessedOlderThan(
            now()->subSeconds((int) $this->option('stale-seconds')),
            (int) $this->option('limit')
        );

        if ($callbacks->isEmpty()) {
            $this->info('необработанных событий нет');

            return self::SUCCESS;
        }

        $this->line("кандидатов: {$callbacks->count()}" . ($dryRun ? ' (dry-run)' : ''));

        $applied = 0;
        $pending = 0;
        $failed  = 0;

        foreach ($callbacks as $callback) {
            if ($dryRun) {
                $this->line("  {$callback->ps_callback_id} order={$callback->order_id} {$callback->payment_status->value}");
                $applied++;

                continue;
            }

            try {
                $processPaymentCallbackService->applyStored($callback);
            } catch (\Throwable $e) {
                Log::error('payment.callback.recover_failed', [
                    'event_id' => $callback->ps_callback_id,
                    'order_id' => $callback->order_id,
                    'error'    => $e->getMessage(),
                ]);

                $this->warn("  {$callback->ps_callback_id}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $callback->refresh();

            if ($callback->processed_at) {
                Log::info('payment.callback.recovered', [
                    'event_id' => $callback->ps_callback_id,
                    'order_id' => $callback->order_id,
                ]);

                $applied++;

                continue;
            }

            $pending++;
        }

        $this->line("применено: {$applied}");
        $this->line("все еще ждут заказ: {$pending}");

        if ($failed > 0) {
            $this->error("не удалось применить: {$failed}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
