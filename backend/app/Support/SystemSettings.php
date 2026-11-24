<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class SystemSettings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = DB::table('system_settings')->where('key', $key)->value('value');
        if ($value === null) return $default;
        if (is_string($value)) return json_decode($value, true) ?? $default;
        return $value;
    }

    public static function put(string $key, mixed $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
