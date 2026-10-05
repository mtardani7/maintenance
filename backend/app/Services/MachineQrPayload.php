<?php

namespace App\Services;

final class MachineQrPayload
{
    /** @return array{plant_code: string, machine_code: string}|null */
    public static function parse(string $payload): ?array
    {
        if (! preg_match('/^MAINTENANCE-MACHINE\|1\|([^|]+)\|([^|]+)$/', trim($payload), $matches)) return null;

        $plantCode = rawurldecode($matches[1]);
        $machineCode = rawurldecode($matches[2]);
        if ($plantCode === '' || $machineCode === '') return null;

        return ['plant_code' => $plantCode, 'machine_code' => $machineCode];
    }
}
