<?php

namespace App\Services;

use App\Exceptions\InvalidReportTransitionException;
use App\Models\Categories;
use App\Models\Reports;
use App\Models\ReportsLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ============================================================================
 * REPORT SERVICE  (FR-REP-01 s/d FR-REP-03, FR-AI-01 s/d FR-AI-03,
 *                 FR-ADM-01 s/d FR-ADM-04, BR-01, BR-02, NFR-04)
 * ============================================================================
 * Semua operasi tulis terhadap tabel `reports` dan `reports_logs` dilakukan
 * di sini, BUKAN langsung di controller.
 *
 * Kenapa?
 * 1. Aturan bisnis (BR-01, BR-02) jadi terpusat dan tidak bisa dilanggar
 *    dari endpoint lain.
 * 2. Controller tetap ramping: validasi -> panggil service -> bungkus response.
 * 3. Mudah diuji: aturan bisnis bisa diuji tanpa lewat HTTP.
 *
 * ---------------------------------------------------------------------------
 * CATATAN PENTING SOAL "STATUS SELALU MULAI DARI PENDING"
 * ---------------------------------------------------------------------------
 * Lapisan database (migration) sudah memasang default `status = 'pending'`.
 * Kesengajaannya: tidak ada jalur kode yang bisa membuat laporan baru dengan
 * status selain pending. Laporan baru = "Menunggu", SELALU.
 *
 * (apakah auto-disposition confidence tinggi langsung jadi `verified`?)
 * TIDAK. Tetap `pending`, tapi kategorinya sudah terisi hasil tebakan AI dan
 * confidence-nya ditampilkan sebagai badge "Rekomendasi AI Sangat Yakin".
 * Alasannya (lihat docs/06):
 *   - Mekanisme Human-in-the-Loop di SRS jadi utuh - tombol Verifikasi tetap
 *     punya arti dan tetap terlihat saat demo.
 *   - Model dilatih dengan 65% data definisi tugas pokok instansi, jadi
 *     confidence tinggi belum tentu berarti tepat untuk laporan warga.
 *   - Salah dispose = laporan terkirim ke instansi yang salah.
 */
class ReportService
{
    /**
     * Dipakai untuk logging ke audit trail.
     */
    public function __construct(protected AiBertService $aiBert) {}

    /**
     * Buat laporan baru dari warga + analisis AI + tulis audit trail.
     *
     * @param  array<string, mixed>  $data  Data yang sudah tervalidasi.
     * @param  UploadedFile|null  $image  Foto laporan (opsional).
     */
    public function createFromCitizen(User $user, array $data, ?UploadedFile $image = null): Reports
    {
        // 1. Simpan foto (kalau ada) lebih dulu supaya bisa gagal duluan
        //    sebelum kita menyentuh database.
        $imagePath = $image ? $this->storeImage($image, 'reports') : null;

        // 2. Gabungkan judul + deskripsi jadi satu teks untuk AI (FR-AI-01).
        $text = trim($data['title'].' '.$data['description']);

        // 3. Tanya AI. Kalau gagal -> null (FR-AI-03 kondisi 3).
        $analysis = $this->aiBert->analyze($text);

        // 4. Auto-disposition (FR-AI-03 kondisi 1).
        //
        // Prioritas kategori:
        //   a) Kalau WARGA memilih kategori sendiri -> itu yang dipakai.
        //      Keputusan warga lebih tinggi dari tebakan AI, dan kolom
        //      ai_pred_cat_id tetap menyimpan tebakan AI (data evaluasi).
        //   b) Kalau warga TIDAK memilih dan AI yakin -> isi dengan tebakan AI.
        //   c) Kalau warga tidak memilih dan AI tidak yakin -> NULL, tunggu admin.
        //
        // Threshold diambil dari config (hasil kalibrasi, bukan asumsi).
        $threshold = (float) config('services.ai_bert.category_confidence_threshold', 0.85);
        $autoFill = $analysis
            && $analysis['category_id'] !== null
            && $analysis['category_confidence'] !== null
            && $analysis['category_confidence'] >= $threshold;

        $citizenCategoryId = $data['category_id'] ?? null;
        $finalCategoryId = $citizenCategoryId
            ?? ($autoFill ? $analysis['category_id'] : null);

        $report = DB::transaction(function () use ($user, $data, $imagePath, $analysis, $autoFill, $finalCategoryId) {
            $report = Reports::create([
                'ticket_number' => $this->generateTicketNumber(),
                'user_id' => $user->id,

                // Kategori hasil DISPOSISI (bisa dikoreksi admin nanti).
                // Pilihan warga (kalau ada) menang; kalau tidak, pakai tebakan
                // AI yang confident.
                'category_id' => $finalCategoryId,

                // Kategori TEBAKAN AI -> tidak pernah diubah admin (FR-ADM-03).
                'ai_pred_cat_id' => $analysis['category_id'] ?? null,
                'ai_confidence' => $analysis['category_confidence'] ?? null,

                // Prioritas / urgensi.
                'ai_priority' => $analysis['priority'] ?? null,
                'ai_priority_confidence' => $analysis['priority_confidence'] ?? null,
                'priority' => $analysis['priority'] ?? null,

                'report_type' => $data['report_type'],
                'title' => $data['title'],
                'description' => $data['description'],
                'incident_date' => $data['incident_date'] ?? null,
                'location_text' => $data['location_text'],
                'image_url' => $imagePath,
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
                'is_private' => (bool) ($data['is_private'] ?? false),
                'status' => Reports::STATUS_PENDING,
            ]);

            // 5. Audit trail: baris pertama (NFR-04, append-only).
            $this->writeLog($report, Reports::STATUS_PENDING, $this->buildInitialNote($analysis, $autoFill), null);

            return $report;
        });

        return $report->fresh(['category', 'aiPredictedCategory', 'assignedAgency', 'user', 'logs']);
    }

    /**
     * FR-ADM-01: verifikasi laporan -> status `verified`.
     *
     * @param  int  $categoryId  Kategori final yang dipilih admin.
     * @param  string  $note  Memo penugasan (wajib, BR-02).
     * @param  int|null  $agencyId  Instansi final; NULL = pakai default kategori.
     */
    public function verify(Reports $report, User $admin, int $categoryId, string $note, ?int $agencyId = null): Reports
    {
        $this->assertTransition($report, [Reports::STATUS_PENDING]);

        // Kalau admin tidak memilih instansi, gunakan default kategori sebagai
        // rekomendasi. Admin tetap boleh memilih instansi lain (override).
        $agencyId ??= Categories::whereKey($categoryId)->value('default_agency_id');

        $report->update([
            'category_id' => $categoryId,
            'assigned_agency_id' => $agencyId,
            'status' => Reports::STATUS_VERIFIED,
        ]);

        $this->writeLog($report, Reports::STATUS_VERIFIED, $note, $admin);

        return $report->fresh(['category', 'aiPredictedCategory', 'assignedAgency', 'logs']);
    }

    /**
     * FR-ADM-02: tolak laporan -> status `rejected`.
     *
     * @param  string  $note  Alasan penolakan (wajib, BR-02).
     */
    public function reject(Reports $report, User $admin, string $note): Reports
    {
        $this->assertTransition($report, [Reports::STATUS_PENDING]);

        $report->update(['status' => Reports::STATUS_REJECTED]);

        $this->writeLog($report, Reports::STATUS_REJECTED, $note, $admin);

        return $report->fresh(['category', 'aiPredictedCategory', 'assignedAgency', 'logs']);
    }

    /**
     * FR-ADM-04: tindak lanjut -> status `followed_up`.
     *
     * @param  string  $note  Catatan feedback (wajib, BR-02).
     * @param  UploadedFile|null  $proof  Foto bukti penyelesaian (opsional).
     */
    public function followUp(Reports $report, User $admin, string $note, ?UploadedFile $proof = null): Reports
    {
        $this->assertTransition($report, [Reports::STATUS_VERIFIED]);

        $proofPath = $proof ? $this->storeImage($proof, 'proofs') : null;

        $report->update(['status' => Reports::STATUS_FOLLOWED_UP]);

        $this->writeLog($report, Reports::STATUS_FOLLOWED_UP, $note, $admin, $proofPath);

        return $report->fresh(['category', 'aiPredictedCategory', 'assignedAgency', 'logs']);
    }

    /**
     * ---------------------------------------------------------------------------
     * BR-01: PENJAGA TRANSISI STATUS
     * ---------------------------------------------------------------------------
     * Laporan hanya boleh bergerak maju:
     *   pending -> verified -> followed_up
     *   pending -> rejected
     * Setelah `rejected` / `followed_up` = FINAL.
     *
     * Memakai exception supaya controller tinggal catch-nya (atau biarkan
     * Laravel yang merender jadi 409).
     *
     * @param  array<int, string>  $allowedFrom  Status asal yang boleh.
     */
    protected function assertTransition(Reports $report, array $allowedFrom): void
    {
        if (in_array($report->status, $allowedFrom, true)) {
            return;
        }

        $allowed = implode(' / ', $allowedFrom);

        throw new InvalidReportTransitionException(sprintf(
            'Laporan dengan status "%s" tidak dapat diproses lagi. '
            .'Tindakan ini hanya bisa dilakukan pada laporan berstatus: %s.',
            $report->statusLabel(),
            $allowed
        ));
    }

    /**
     * Tulis satu baris audit trail.
     *
     * Tabel `reports_logs` bersifat append-only (NFR-04) - model ReportsLog
     * menolak update/delete. Jadi method ini HANYA boleh dipakai untuk INSERT.
     *
     * @param  string|null  $proofPath  Path foto bukti (untuk tindak lanjut).
     */
    protected function writeLog(
        Reports $report,
        string $status,
        ?string $note,
        ?User $actor,
        ?string $proofPath = null,
    ): ReportsLog {
        return ReportsLog::create([
            'report_id' => $report->id,
            // null = log dibuat oleh sistem (AI), bukan oleh user.
            'changed_by' => $actor?->id,
            'status' => $status,
            'note' => $note,
            'proof_img' => $proofPath,
        ]);
    }

    /**
     * Susun catatan log pertama.
     *
     * Dua kemungkinan:
     *  - AI berhasil  : "Klasifikasi otomatis ke kategori X (Confidence: 91%)"
     *  - AI gagal     : "Laporan diterima. Modul AI sedang tidak tersedia,
     *                    menunggu verifikasi manual."
     *
     * Yang kedua penting: admin harus TAHU bahwa laporan ini tidak dianalisis
     * AI, supaya dia tidak mengira "AI tidak yakin" padahal "AI tidak jalan".
     */
    protected function buildInitialNote(?array $analysis, bool $autoFill): string
    {
        if (! $analysis) {
            return 'Laporan diterima. Modul AI sedang tidak tersedia, menunggu verifikasi manual.';
        }

        $categoryName = $analysis['category_label'] ?? 'Tidak diketahui';
        $confidence = $analysis['category_confidence'] !== null
            ? (int) round($analysis['category_confidence'] * 100)
            : null;

        $note = sprintf(
            'Klasifikasi otomatis ke kategori %s%s',
            $categoryName,
            $confidence !== null ? " (Confidence: {$confidence}%)" : ''
        );

        if ($analysis['priority']) {
            $priorityConfidence = $analysis['priority_confidence'] !== null
                ? (int) round($analysis['priority_confidence'] * 100)
                : null;

            $note .= sprintf(
                '. Prioritas: %s%s',
                ucfirst($analysis['priority']),
                $priorityConfidence !== null ? " (Confidence: {$priorityConfidence}%)" : ''
            );
        }

        $note .= $autoFill
            ? '. Rekomendasi AI sangat yakin, kategori sudah terisi otomatis dan menunggu konfirmasi admin.'
            : '. Menunggu verifikasi manual.';

        return $note;
    }

    /**
     * FR-REP-03: nomor tiket otomatis format LPR-YYYY-XXXX.
     *
     * Nomor urut dihitung per tahun, jadi LPR-2026-0001, LPR-2026-0002, ...
     * dan LPR-2027-0001 lagi mulai dari 1 di tahun berikutnya.
     *
     * Kolom ticket_number punya UNIQUE index, jadi kalau ada dua request
     * bersamaan_generate nomor yang sama, database akan menolak salah satunya
     * (dan RequestException-nya caught -> nomor baru dicoba).
     */
    protected function generateTicketNumber(): string
    {
        $prefix = 'LPR-'.now()->format('Y').'-';

        $last = Reports::where('ticket_number', 'like', $prefix.'%')
            ->orderByDesc('ticket_number')
            ->value('ticket_number');

        $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan file gambar ke disk `public` dan kembalikan URL-nya.
     *
     * URL yang dikembalikan formatnya "/storage/reports/nama-file.jpg"
     * (butuh `php artisan storage:link`).
     */
    protected function storeImage(UploadedFile $file, string $folder): string
    {
        $path = Storage::disk('public')->putFile($folder, $file);

        return Storage::disk('public')->url($path);
    }
}
