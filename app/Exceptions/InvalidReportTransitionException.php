<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception domain untuk pelanggaran aturan bisnis pada laporan.
 *
 * ---------------------------------------------------------------------------
 * KAPAN DIPAKAI?
 * ---------------------------------------------------------------------------
 * BR-01 (SRS): transisi status laporan hanya boleh maju:
 *
 *     pending  -> verified  -> followed_up
 *     pending  -> rejected
 *
 * Setelah `rejected` atau `followed_up`, status bersifat FINAL dan tidak
 * bisa diubah lagi.
 *
 * Contoh pelanggaran:
 *   - Admin tries to follow up a report that is still `pending` (skipping verification)
 *   - Admin tries to verify a report that is already `followed_up`
 *   - Admin tries to change a `rejected` report
 *
 * Kenapa exception, bukan return false?
 * Karena aturan ini bukan "input tidak valid" (itu urusan Form Request /
 * 422), tapi "operasi bisnis yang tidak boleh dijalankan pada state ini".
 * Exception + HTTP 409 Conflict adalah representasi yang tepat.
 */
class InvalidReportTransitionException extends RuntimeException
{
    /**
     * Kontruktor dengan pesan siap-tampil untuk user.
     */
    public function __construct(
        string $message,
        protected int $status = 409,
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP status code yang dipakai saat exception ini dirender.
     */
    public function status(): int
    {
        return $this->status;
    }
}
