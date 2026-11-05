<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PlantAccess
{
    public static function canAccess(User $user, int|string|null $plantId): bool
    {
        return $user->hasAllPlantAccess()
            || ($plantId !== null && $user->plant_id !== null && (string) $user->plant_id === (string) $plantId);
    }

    public static function enforce(User $user, int|string|null $plantId): void
    {
        abort_unless(self::canAccess($user, $plantId), 403, 'Akun ini tidak memiliki akses ke Plant tersebut.');
    }

    public static function scope(EloquentBuilder|QueryBuilder $query, User $user, string $column = 'plant_id'): EloquentBuilder|QueryBuilder
    {
        if ($user->hasAllPlantAccess()) {
            return $query;
        }

        return $user->plant_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where($column, $user->plant_id);
    }
}
