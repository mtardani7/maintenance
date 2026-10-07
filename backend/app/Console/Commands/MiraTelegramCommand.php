<?php

namespace App\Console\Commands;

use App\Services\MiraTelegramClient;
use App\Services\MiraTelegramMessageHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MiraTelegramCommand extends Command
{
    protected $signature = 'mira:telegram';

    protected $description = 'Poll Telegram messages and answer Maintenance questions with MIRA';

    private bool $shouldStop = false;

    public function handle(MiraTelegramClient $telegram, MiraTelegramMessageHandler $messages): int
    {
        if (! config('services.mira.telegram_token')) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        if (! function_exists('pcntl_async_signals')) {
            $this->error('The PHP pcntl extension is required for graceful shutdown.');

            return self::FAILURE;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        $offset = Cache::get('mira.telegram.update_offset');
        $offset = is_numeric($offset) ? (int) $offset : null;

        $this->info('MIRA Telegram polling started.');

        while (! $this->shouldStop) {
            try {
                $updates = $telegram->getUpdates($offset);
            } catch (Throwable $exception) {
                Log::warning('MIRA Telegram polling failed; retrying.', [
                    'exception' => $exception::class,
                ]);
                $this->pause(3);

                continue;
            }

            foreach ($updates as $update) {
                if ($this->shouldStop) {
                    break;
                }

                if (! is_array($update) || ! is_numeric($update['update_id'] ?? null)) {
                    continue;
                }

                $updateId = (int) $update['update_id'];

                if ($offset !== null && $updateId < $offset) {
                    continue;
                }

                $message = $update['message'] ?? null;

                if (is_array($message)
                    && is_string($message['text'] ?? null)
                    && trim($message['text']) !== ''
                    && isset($message['chat']['id'])
                    && is_numeric($message['chat']['id'])
                    && ! ($message['from']['is_bot'] ?? false)) {
                    $messages->handle($telegram, $updateId, $message);
                }

                $offset = $updateId + 1;

                try {
                    Cache::forever('mira.telegram.update_offset', $offset);
                } catch (Throwable $exception) {
                    Log::error('MIRA Telegram update offset could not be persisted.', [
                        'update_id' => $updateId,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        $this->info('MIRA Telegram polling stopped.');

        return self::SUCCESS;
    }

    private function pause(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds && ! $this->shouldStop; $elapsed++) {
            sleep(1);
        }
    }
}
