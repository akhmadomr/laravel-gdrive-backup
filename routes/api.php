<?php

use Akhmadomr\GdriveBackup\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

$prefix = trim(config('gdrive-backup.route.prefix', 'api/v1/backup'), '/');

// Callback OAuth Google (redirect browser, tanpa auth; diamankan dengan parameter state).
Route::prefix($prefix)
    ->middleware(config('gdrive-backup.route.callback_middleware', ['api']))
    ->group(function () {
        Route::get('google/callback', [BackupController::class, 'callback'])->name('gdrive-backup.callback');
    });

Route::prefix($prefix)
    ->middleware(config('gdrive-backup.route.middleware', ['api', 'auth:sanctum']))
    ->group(function () {
        Route::get('status', [BackupController::class, 'status'])->name('gdrive-backup.status');
        Route::get('google/connect', [BackupController::class, 'connect'])->name('gdrive-backup.connect');
        Route::post('google/disconnect', [BackupController::class, 'disconnect'])->name('gdrive-backup.disconnect');
        Route::post('google/folder', [BackupController::class, 'updateFolder'])->name('gdrive-backup.folder');
        Route::post('schedule', [BackupController::class, 'updateSchedule'])->name('gdrive-backup.schedule');
        Route::post('run', [BackupController::class, 'runBackup'])->name('gdrive-backup.run');
        Route::post('restore', [BackupController::class, 'restore'])->name('gdrive-backup.restore');
        Route::get('restore-history', [BackupController::class, 'restoreHistory'])->name('gdrive-backup.restore-history');
    });
