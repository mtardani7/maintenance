<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MiraTelegramMessageHandler
{
    public function __construct(
        private readonly MiraAssistantService $assistant,
        private readonly MiraQueryService $queries,
        private readonly MiraResponseFormatter $formatter,
    ) {}

    public function handle(MiraTelegramClient $telegram, int $updateId, array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = is_numeric($message['from']['id'] ?? null) ? (int) $message['from']['id'] : null;
        $startedAt = microtime(true);
        $chatType = $message['chat']['type'] ?? 'private';
        $maintenanceChatId = (string) config('services.mira.telegram_maintenance_chat_id', '');
        $question = trim((string) ($message['text'] ?? ''));

        if ($chatType !== 'private'
            && ($maintenanceChatId === '' || (string) $chatId !== $maintenanceChatId)) {
            Log::notice('MIRA Telegram ignored a message from an unconfigured group.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
            ]);

            return;
        }

        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $username = trim((string) config('services.mira.telegram_bot_username', ''));
            $mentionPattern = $username === ''
                ? null
                : '/(?<![\p{L}\p{N}_])@'.preg_quote(ltrim($username, '@'), '/').'(?![\p{L}\p{N}_])/iu';

            if ($mentionPattern === null || preg_match($mentionPattern, $question) !== 1) {
                return;
            }

            $question = trim((string) preg_replace($mentionPattern, '', $question));

            if ($question === '') {
                $telegram->sendMessage($chatId, 'Silakan tuliskan pertanyaan setelah memanggil MIRA.');

                return;
            }
        }

        if (preg_match('/^\/(?:start|menu)(?:@\w+)?(?:\s|$)/i', $question) === 1) {
            $telegram->sendMessage($chatId, 'Selamat datang di MIRA. Pilih menu yang ingin Anda buka.', $this->mainMenu());

            return;
        }

        $pending = Cache::get($this->inputKey($chatId, $userId));
        if (is_string($pending) && $pending !== '') {
            Cache::forget($this->inputKey($chatId, $userId));
            if ($pending === 'ticket_number') {
                $this->runAction($telegram, $chatId, 'ticket.detail', ['ticket_number' => $question], null, null);

                return;
            }
        }

        try {
            $result = $this->assistant->answer($question);
            $totalMs = $result['total_ms'] ?? (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('MIRA Telegram question processed.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
                'intent' => $result['intent'],
                'query_type' => $result['query_type'],
                'intent_ms' => $result['intent_ms'],
                'query_ms' => $result['query_ms'],
                'total_ms' => $totalMs,
            ]);

            $telegram->sendMessage($chatId, $result['answer']);
        } catch (Throwable $exception) {
            Log::error('MIRA Telegram question failed.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
                'exception' => $exception::class,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            try {
                $telegram->sendMessage($chatId, 'MIRA sedang mengalami gangguan sementara. Silakan coba lagi nanti.');
            } catch (Throwable $sendException) {
                Log::error('MIRA Telegram safe error response could not be sent.', [
                    'update_id' => $updateId,
                    'telegram_user_id' => $userId,
                    'chat_id' => $chatId,
                    'exception' => $sendException::class,
                ]);
            }
        }
    }

    public function handleCallback(MiraTelegramClient $telegram, int $updateId, array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $data = (string) ($callback['data'] ?? '');
        $userId = is_numeric($callback['from']['id'] ?? null) ? (int) $callback['from']['id'] : null;
        if ($callbackId === '' || ! is_numeric($chatId) || ! is_numeric($messageId)) {
            return;
        }

        try {
            $chatType = (string) ($message['chat']['type'] ?? 'private');
            $maintenanceChatId = (string) config('services.mira.telegram_maintenance_chat_id', '');
            if ($chatType !== 'private' && ($maintenanceChatId === '' || (string) $chatId !== $maintenanceChatId)) {
                $telegram->answerCallbackQuery($callbackId, 'Menu ini tidak tersedia di grup ini.');

                return;
            }

            $telegram->answerCallbackQuery($callbackId);
            if ($data === 'mira:home') {
                $telegram->editMessageText($chatId, (int) $messageId, 'Menu utama MIRA. Pilih salah satu:', $this->mainMenu());

                return;
            }
            if (str_starts_with($data, 'mira:menu:')) {
                $menu = substr($data, strlen('mira:menu:'));
                $screen = $this->submenu($menu);
                if ($screen === null) {
                    $telegram->editMessageText($chatId, (int) $messageId, 'Menu tidak dikenal.', $this->mainMenu());

                    return;
                }
                if ($menu === 'ask') {
                    Cache::forget($this->inputKey($chatId, $userId));
                }
                $telegram->editMessageText($chatId, (int) $messageId, $screen['text'], $screen['keyboard']);

                return;
            }
            if (preg_match('/^mira:input:(ticket_number)$/', $data, $m) === 1) {
                Cache::put($this->inputKey($chatId, $userId), $m[1], now()->addMinutes(10));
                $telegram->editMessageText($chatId, (int) $messageId, 'Kirim nomor tiket yang ingin dicari, misalnya MT-001.', $this->navigation());

                return;
            }
            if (preg_match('/^mira:plant:(\d+)$/', $data, $m) === 1) {
                $this->showPlantMachines($telegram, $chatId, (int) $messageId, (int) $m[1], 0);

                return;
            }
            if (preg_match('/^mira:history:(\d+)$/', $data, $m) === 1) {
                $this->runAction($telegram, $chatId, 'incident.machine_history', ['machine_id' => (int) $m[1], 'page' => 0], (int) $messageId, null);

                return;
            }
            if (preg_match('/^mira:page:(ticket\.open_list|incident\.latest|spare\.list|machine\.list|incident\.machine_history):(\d+)(?::(\d+))?$/', $data, $m) === 1) {
                $params = ['page' => min((int) $m[2], 100)];
                if (isset($m[3]) && $m[3] !== '') {
                    $params[$m[1] === 'incident.machine_history' ? 'machine_id' : 'plant_id'] = (int) $m[3];
                }
                $this->runAction($telegram, $chatId, $m[1], $params, (int) $messageId, null);

                return;
            }
            if (preg_match('/^mira:action:([a-z_.]+)$/', $data, $m) === 1 && in_array($m[1], $this->allowedActions(), true)) {
                if ($m[1] === 'ticket.detail.prompt') {
                    Cache::put($this->inputKey($chatId, $userId), 'ticket_number', now()->addMinutes(10));
                    $telegram->editMessageText($chatId, (int) $messageId, 'Kirim nomor tiket yang ingin dicari, misalnya MT-001.', $this->navigation());

                    return;
                }
                if ($m[1] === 'machine.choose_plant') {
                    $this->showPlants($telegram, $chatId, (int) $messageId, 'Pilih plant untuk melihat daftar mesin:');

                    return;
                }
                if ($m[1] === 'machine.history.prompt') {
                    $this->showPlants($telegram, $chatId, (int) $messageId, 'Pilih plant, lalu pilih mesin untuk melihat riwayat insiden:');

                    return;
                }
                $this->runAction($telegram, $chatId, $m[1], [], (int) $messageId, null);

                return;
            }
            $telegram->editMessageText($chatId, (int) $messageId, 'Pilihan tidak dikenal. Silakan buka menu utama.', $this->mainMenu());
        } catch (Throwable $exception) {
            Log::error('MIRA Telegram callback failed.', ['update_id' => $updateId, 'exception' => $exception::class]);
            try {
                $telegram->editMessageText($chatId, (int) $messageId, 'MIRA tidak dapat memproses pilihan ini saat ini.', $this->navigation());
            } catch (Throwable) {
            }
        }
    }

    private function mainMenu(): array
    {
        return ['inline_keyboard' => [
            [['text' => '📊 Ringkasan', 'callback_data' => 'mira:menu:summary'], ['text' => '🎫 Tiket Maintenance', 'callback_data' => 'mira:menu:tickets']],
            [['text' => '🚨 Insiden', 'callback_data' => 'mira:menu:incidents'], ['text' => '🏭 Mesin & Plant', 'callback_data' => 'mira:menu:machines']],
            [['text' => '📦 Spare Part', 'callback_data' => 'mira:menu:spare'], ['text' => '🤖 Tanya MIRA', 'callback_data' => 'mira:menu:ask']],
        ]];
    }

    private function navigation(): array
    {
        return ['inline_keyboard' => [[['text' => '🏠 Menu Utama', 'callback_data' => 'mira:home']]]];
    }

    private function submenu(string $menu): ?array
    {
        $menus = [
            'summary' => ['📊 Ringkasan', [['OPEN saat ini', 'ticket.open'], ['Tiket dibuat hari ini', 'ticket.created_today'], ['Tiket ditutup hari ini', 'ticket.closed_today'], ['Insiden hari ini', 'incident.today'], ['Insiden belum selesai', 'incident.open']]],
            'tickets' => ['🎫 Tiket Maintenance', [['Tiket OPEN saat ini', 'ticket.open'], ['Dibuat hari ini', 'ticket.created_today'], ['Ditutup hari ini', 'ticket.closed_today'], ['Daftar tiket OPEN', 'ticket.open_list'], ['Cari nomor tiket', 'ticket.detail.prompt'], ['Ringkasan per plant', 'ticket.by_plant']]],
            'incidents' => ['🚨 Insiden', [['Insiden hari ini', 'incident.today'], ['7 hari terakhir', 'incident.last7'], ['30 hari terakhir', 'incident.last30'], ['Mesin terbanyak · 7 hari', 'incident.machine_rank7'], ['Jenis masalah · 30 hari', 'incident.problem_rank30'], ['Insiden terbaru', 'incident.latest']]],
            'machines' => ['🏭 Mesin & Plant', [['Jumlah mesin', 'machine.count'], ['Mesin per plant', 'machine.by_plant'], ['Daftar mesin per plant', 'machine.choose_plant'], ['Riwayat insiden mesin', 'machine.history.prompt']]],
            'spare' => ['📦 Spare Part', [['Daftar catatan penggunaan', 'spare.list'], ['Total kuantitas · 30 hari', 'spare.total30'], ['Paling banyak digunakan', 'spare.top']]],
            'ask' => ['🤖 Tanya MIRA', [['Pertanyaan bebas tetap aktif. Kirim pertanyaan Anda sebagai pesan biasa. Contoh: “Berapa tiket OPEN di Plant 3?”', 'mira:noop']]],
        ];
        if (! isset($menus[$menu])) {
            return null;
        }
        [$title, $items] = $menus[$menu];
        $rows = [];
        foreach ($items as [$label, $action]) {
            if ($action === 'mira:noop') {
                continue;
            }
            $rows[] = [['text' => $label, 'callback_data' => 'mira:action:'.$action]];
        }
        $rows[] = [['text' => '⬅️ Kembali', 'callback_data' => 'mira:home']];

        $description = $menu === 'ask'
            ? "\nMode pertanyaan bebas aktif. Kirim pesan biasa, misalnya:\n• Berapa tiket OPEN di Plant 3?\n• Mesin mana yang paling sering mengalami insiden minggu ini?\n• Jelaskan penyebab kerusakan pada tiket MT-001."
            : "\nPilih informasi yang ingin ditampilkan:";

        return ['text' => $title.$description, 'keyboard' => ['inline_keyboard' => $rows]];
    }

    private function allowedActions(): array
    {
        return ['ticket.open', 'ticket.created_today', 'ticket.closed_today', 'ticket.open_list', 'ticket.detail.prompt', 'ticket.by_plant', 'incident.today', 'incident.open', 'incident.last7', 'incident.last30', 'incident.machine_rank7', 'incident.problem_rank30', 'incident.latest', 'machine.count', 'machine.by_plant', 'machine.choose_plant', 'machine.history.prompt', 'spare.list', 'spare.total30', 'spare.top'];
    }

    private function showPlants(MiraTelegramClient $telegram, int|string $chatId, int $messageId, string $title): void
    {
        $result = $this->queries->runMenuAction('plant.options');
        $rows = [];
        foreach (($result['data'] ?? []) as $plant) {
            $rows[] = [['text' => mb_substr($plant['label'], 0, 60), 'callback_data' => 'mira:plant:'.$plant['id']]];
        }
        $rows[] = [['text' => '🏠 Menu Utama', 'callback_data' => 'mira:home']];
        $telegram->editMessageText($chatId, $messageId, $rows === [] ? 'Tidak ada plant aktif yang ditemukan.' : $title, ['inline_keyboard' => $rows]);
    }

    private function showPlantMachines(MiraTelegramClient $telegram, int|string $chatId, int $messageId, int $plantId, int $page): void
    {
        $result = $this->queries->runMenuAction('machine.list', ['plant_id' => $plantId, 'page' => $page]);
        $rows = [];
        foreach (($result['data'] ?? []) as $machine) {
            $label = mb_substr($machine['code'].' - '.$machine['name'], 0, 50);
            $rows[] = [['text' => $label, 'callback_data' => 'mira:history:'.$machine['id']]];
        }
        if (count($rows) === 10) {
            $rows[] = [['text' => 'Lihat berikutnya', 'callback_data' => 'mira:page:machine.list:'.($page + 1).':'.$plantId]];
        }
        $rows[] = [['text' => '⬅️ Pilih plant', 'callback_data' => 'mira:action:machine.choose_plant']];
        $rows[] = [['text' => '🏠 Menu Utama', 'callback_data' => 'mira:home']];
        $telegram->editMessageText($chatId, $messageId, empty($result['data']) ? 'Tidak ada mesin pada plant tersebut.' : 'Pilih mesin untuk membuka riwayat insiden:', ['inline_keyboard' => $rows]);
    }

    private function runAction(MiraTelegramClient $telegram, int|string $chatId, string $action, array $parameters, ?int $messageId, ?array $keyboard): void
    {
        if ($action === 'incident.open') {
            $action = 'incident.open';
        }
        $result = $this->queries->runMenuAction($action, $parameters);
        if ($result === null) {
            throw new \RuntimeException('Unsupported menu action.');
        }
        $data = $result['data'] ?? null;
        $text = $this->formatMenuResult($action, $result);
        $page = (int) ($parameters['page'] ?? 0);
        $keyboardRows = [];
        if (is_array($data) && count($data) === 10) {
            $next = 'mira:page:'.$action.':'.($page + 1);
            if (isset($parameters['plant_id'])) {
                $next .= ':'.(int) $parameters['plant_id'];
            }
            if (isset($parameters['machine_id'])) {
                $next .= ':'.(int) $parameters['machine_id'];
            }
            $keyboardRows[] = [['text' => 'Lihat berikutnya', 'callback_data' => $next]];
        }
        $keyboardRows[] = [['text' => '⬅️ Kembali', 'callback_data' => 'mira:home']];
        $markup = ['inline_keyboard' => $keyboardRows];
        if ($messageId !== null) {
            $telegram->editMessageText($chatId, $messageId, $text, $markup);
        } else {
            $telegram->sendMessage($chatId, $text, $markup);
        }
    }

    private function formatMenuResult(string $action, array $result): string
    {
        $data = $result['data'] ?? null;
        if (is_numeric($data)) {
            if ($action === 'incident.open') {
                return (int) $data === 0
                    ? 'Saat ini tidak ada insiden OPEN yang belum selesai.'
                    : 'Saat ini ada '.(int) $data.' insiden OPEN yang belum selesai.';
            }
            $map = [
                'ticket.open' => ['ticket', 'count', 'none', ['status' => 'open'], 'saat ini'],
                'ticket.created_today' => ['ticket', 'count', 'none', [], 'hari ini'],
                'ticket.closed_today' => ['ticket', 'count', 'none', ['status' => 'closed'], 'hari ini'],
                'incident.today' => ['incident', 'count', 'none', [], 'hari ini'],
                'incident.last7' => ['incident', 'count', 'none', [], '7 hari terakhir'],
                'incident.last30' => ['incident', 'count', 'none', [], '30 hari terakhir'],
                'machine.count' => ['machine', 'count', 'none', [], ''],
                'spare.total30' => ['spare_part', 'sum', 'none', [], '30 hari terakhir'],
            ];
            if (isset($map[$action])) {
                [$entity,$metric,$group,$filter,$period] = $map[$action];

                return $this->formatter->format('', ['entity' => $entity, 'metric' => $metric, 'group_by' => $group, 'filter' => $filter, 'sort' => 'desc'], ['data' => $data, 'period' => $period]);
            }
        }
        if (! is_array($data) || $data === []) {
            return 'Tidak ada data yang ditemukan.';
        }
        if ($action === 'ticket.open_list') {
            return "Daftar tiket OPEN (maks. 10 per halaman):\n".collect($data)->map(fn ($r) => $r['ticket_number'].' · '.$r['machine_code'].' '.$r['machine_name'].' · '.$r['problem_type'].' · '.$r['priority'])->implode("\n");
        }
        if ($action === 'ticket.detail') {
            $r = $data[0] ?? null;
            if (! $r) {
                return 'Nomor tiket tidak ditemukan.';
            }

            return "Tiket {$r['ticket_number']} · {$r['status']} · {$r['priority']}\nMesin: {$r['machine_code']} - {$r['machine_name']}\nPlant: ".($r['plant_code'] ?? 'tidak diketahui')."\nMasalah: {$r['problem_type']}\nDeskripsi: ".mb_substr((string) $r['description'], 0, 700);
        }
        if ($action === 'ticket.by_plant') {
            return 'Ringkasan tiket per plant (OPEN / total): '.collect($data)->map(fn ($r) => $r['plant'].': '.$r['open'].' / '.$r['total'])->implode('; ').'.';
        }
        if ($action === 'machine.by_plant') {
            return 'Mesin per plant: '.collect($data)->map(fn ($r) => $r['plant'].': '.$r['count'])->implode('; ').'.';
        }
        if ($action === 'incident.machine_rank7' || $action === 'incident.problem_rank30' || $action === 'spare.top') {
            return $this->formatter->format('', ['entity' => $action === 'spare.top' ? 'spare_part' : 'incident', 'metric' => $action === 'spare.top' ? 'sum' : 'count', 'group_by' => $action === 'spare.top' ? 'spare_part' : ($action === 'incident.machine_rank7' ? 'machine' : 'problem_type'), 'sort' => 'desc', 'filter' => []], $result);
        }
        if ($action === 'incident.latest') {
            return "Insiden terbaru (maks. 10 per halaman):\n".collect($data)->map(fn ($r) => $r['created_at'].' · '.$r['machine_code'].' '.$r['machine_name'].' · '.$r['problem_type'].' · '.$r['status'].' · '.mb_substr((string) $r['description'], 0, 100))->implode("\n");
        }
        if ($action === 'incident.machine_history') {
            return "Riwayat insiden mesin (maks. 10 per halaman):\n".collect($data)->map(fn ($r) => $r['created_at'].' · '.$r['problem_type'].' · '.$r['status'].' · '.mb_substr((string) $r['description'], 0, 120))->implode("\n");
        }
        if ($action === 'spare.list') {
            return "Catatan pemakaian spare part (bukan stok, maks. 10 per halaman):\n".collect($data)->map(fn ($r) => $r['name'].' ('.$r['material_code'].') · '.$r['quantity'].' unit · tiket '.$r['ticket_number'])->implode("\n");
        }

        return 'Tidak ada data yang ditemukan.';
    }

    private function inputKey(int|string $chatId, ?int $userId): string
    {
        return 'mira.telegram.input.'.(string) $chatId.'.'.($userId ?? 'unknown');
    }
}
