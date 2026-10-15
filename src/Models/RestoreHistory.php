<?php

namespace Akhmadomr\GdriveBackup\Models;

use Illuminate\Database\Eloquent\Model;

class RestoreHistory extends Model
{
    protected $table = 'gdrive_backup_restore_histories';

    protected $fillable = [
        'file_name',
        'restored_by_name',
        'restored_by_email',
        'status',
        'reference',
        'reason',
        'restored_at',
    ];

    // Alasan detail tidak pernah dikirim ke frontend.
    protected $hidden = ['reason'];

    protected $casts = [
        'restored_at' => 'datetime',
    ];
}
