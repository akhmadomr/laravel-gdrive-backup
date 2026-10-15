<?php

namespace Akhmadomr\GdriveBackup\Exceptions;

use RuntimeException;

/**
 * Dilempar saat file backup ditolak (bukan milik sistem ini, rusak, versi lebih baru, dll).
 * Pesan exception adalah alasan INTERNAL untuk log, tidak boleh ditampilkan ke user.
 */
class RestoreRejectedException extends RuntimeException
{
}
