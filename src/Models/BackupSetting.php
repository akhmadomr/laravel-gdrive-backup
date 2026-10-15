<?php

namespace Akhmadomr\GdriveBackup\Models;

use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    protected $table = 'gdrive_backup_settings';

    protected $fillable = ['key', 'value'];

    public static function getJson(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();
        if (! $row || $row->value === null || $row->value === '') {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function putJson(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }
}
