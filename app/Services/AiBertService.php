<?php

namespace App\Services;

use App\Models\Categories;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 * AI BERT SERVICE  (FR-AI-01 s/d FR-AI-03)
 * ============================================================================
 * Satu-satunya tempat di seluruh aplikasi yang TAHU bentuk JSON dari
 * AI Microservice. Controller tidak pernah bicara HTTP langsung ke service AI.
 *
 * ---------------------------------------------------------------------------
 * ALUR KERJA
 * ---------------------------------------------------------------------------
 *   1. Teks laporan (title + description) dikirim ke POST /analyze
 *   2. AI Microservice mengembalikan 2 prediksi:
 *        - category : ke-12 kategori mana laporan ini masuk
 *        - urgency  : seberapa penting (darurat/tinggi/sedang/rendah)
 *   3. Hasil "category" dipetakan ke id kategori di database lewat kolom
 *      `categories.ai_label_id` (lihat migration add_ai_label_id).
 *   4. Kalau ada yang GAGAL (timeout, service down, jawaban aneh),
 *      method ini mengembalikan NULL.
 *
 * Kenapa NULL dan bukan melempar error?
 * Karena FR-AI-03 kondisi 3 + NFR-05: "jika service AI down/timeout, laporan
 * TETAP tersimpan di database dengan status pending tanpa menggagalkan
 * pengiriman form warga". Jadi kegagalan AI harus MUNDUR, bukan MATI.
 *
 * Caller (ReportService) akan menulis log: "Modul AI sedang tidak tersedia,
 * menunggu verifikasi manual." - jujur ke admin, dan aman buat warga.
 *
 * ---------------------------------------------------------------------------
 * TIMEOUT (NFR-01)
 * ---------------------------------------------------------------------------
 * Batas keras 3 detik. Ditulis di config/services.php (ai_bert.timeout)
 * supaya bisa diubah tanpa menyentuh kode.
 */
class AiBertService
{
    /**
     * Hasil analisis AI dalam bentuk yang sudah dinormalisasi.
     *
     * @return array{
     *     category_id: ?int,       // id kategori di DB (null = tidak ditemukan)
     *     category_label: ?string, // nama label dari model
     *     category_confidence: ?float,
     *     priority: ?string,       // rendah|sedang|tinggi|darurat
     *     priority_confidence: ?float,
     *     processing_ms: ?int
     * }|null  null = layanan tidak tersedia / gagal
     */
    public function analyze(string $text): ?array
    {
        if (blank(trim($text))) {
            return null;
        }

        $payload = $this->callService($text);

        if ($payload === null) {
            return null;
        }

        return [
            'category_id' => $this->resolveCategoryId($payload['category'] ?? null),
            'category_label' => $payload['category']['label'] ?? null,
            'category_confidence' => $this->normalizeConfidence($payload['category']['confidence'] ?? null),
            'priority' => $this->resolvePriority($payload['urgency'] ?? null),
            'priority_confidence' => $this->normalizeConfidence($payload['urgency']['confidence'] ?? null),
            'processing_ms' => isset($payload['processing_ms']) ? (int) $payload['processing_ms'] : null,
        ];
    }

    /**
     * Panggil AI Microservice.
     *
     * @return array<string, mixed>|null
     */
    protected function callService(string $text): ?array
    {
        $url = config('services.ai_bert.url');
        $timeout = (int) config('services.ai_bert.timeout', 3);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url, ['text' => $text]);

            if (! $response->successful()) {
                Log::warning('AI Bert service merespons dengan status tidak sukses.', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $payload = $response->json();

            return is_array($payload) ? $payload : null;
        } catch (Throwable $e) {
            // Timeout / connection refused / service mati.
            // PENTING: tidak boleh melempar exception ke atas, karena itu akan
            // menggagalkan pengiriman laporan warga (melanggar FR-AI-03).
            Log::warning('AI Bert service tidak dapat dihubungi.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Petakan label kategori dari model ke id kategori di database.
     *
     * Alur: ambil "label_id" (angka) dari jawaban model, lalu cari kategori
     * dengan ai_label_id yang sama. Kalau model mengirim nama label saja
     * (tanpa angka), cari berdasarkan nama dengan perbandingan longgar.
     */
    protected function resolveCategoryId(?array $category): ?int
    {
        if (! is_array($category)) {
            return null;
        }

        // Jalur utama: pakai label_id (angka) -> kolom categories.ai_label_id.
        if (isset($category['label_id']) && is_numeric($category['label_id'])) {
            $match = Categories::where('ai_label_id', (int) $category['label_id'])->first();

            if ($match) {
                return $match->id;
            }
        }

        // Jalur cadangan: cocokkan berdasarkan nama (case & spasi diabaikan).
        if (isset($category['label']) && is_string($category['label'])) {
            $needle = $this->normalizeLabel($category['label']);

            $match = Categories::all()->first(
                fn (Categories $item) => $this->normalizeLabel($item->name) === $needle
            );

            return $match?->id;
        }

        return null;
    }

    /**
     * Petakan level urgensi dari model ke nilai enum priority.
     *
     * Model memberi label: Darurat / Tinggi / Sedang / Rendah.
     * Disimpan lowercase agar sama dengan nilai enum di database.
     */
    protected function resolvePriority(?array $urgency): ?string
    {
        if (! is_array($urgency) || ! isset($urgency['label']) || ! is_string($urgency['label'])) {
            return null;
        }

        $allowed = ['rendah', 'sedang', 'tinggi', 'darurat'];
        $value = strtolower(trim($urgency['label']));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Pastikan confidence berupa float 0.00-1.00.
     */
    protected function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;

        // Kadang service mengirim nilai dalam persen (91.0) - normalkan.
        if ($confidence > 1.0 && $confidence <= 100.0) {
            $confidence /= 100;
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Normalisasi nama label supaya perbandingan tidak rapuh.
     *
     * "IT & Jaringan" => "itjaringan"
     * "Lain-lain"    => "lainlain"
     * "Infrastruktur" => "infrastruktur"
     */
    protected function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));

        // Buang semua karakter non-alphanumeric (spasi, &, -, _).
        $label = preg_replace('/[^a-z0-9]+/', '', $label) ?? $label;

        return $label;
    }
}
