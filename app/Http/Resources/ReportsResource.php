<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk daftar laporan (publik / milik sendiri / tabel admin).
 *
 * ---------------------------------------------------------------------------
 * NFR-02: PENYENSORAN NAMA PELAPOR
 * ---------------------------------------------------------------------------
 * Kalau laporan bersifat anonim (is_anonymous = true), nama pelapor harus
 * diganti "Anonymous" - KECUALI kalau yang melihat adalah:
 *   - pelapor itu sendiri, atau
 *   - admin (verifier_admin / agency_admin).
 *
 * Ini aturan yang persis sama seperti NFR-02 di SRS.
 */
class ReportsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $this->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();
        $canSeeName = $isOwner || $isAdmin;

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,
            'report_type' => $this->report_type,

            'status' => $this->status,
            'status_label' => $this->statusLabel(),

            // Prioritas (output model urgency + koreksi admin).
            'priority' => $this->priority,
            'priority_label' => $this->priorityLabel(),

            // Nama pelapor - disensor kalau anonim.
            'reporter_name' => $this->is_anonymous && ! $canSeeName
                ? 'Anonymous'
                : $this->user?->name,

            // Kategori hasil disposisi.
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ] : null,

            'location_text' => $this->location_text,
            'incident_date' => $this->incident_date?->toDateString(),
            'image_url' => $this->image_url,

            'is_anonymous' => (bool) $this->is_anonymous,
            'is_private' => (bool) $this->is_private,

            // Analisis AI - dipakai admin untuk menilai keyakinan model.
            'ai_analysis' => [
                'predicted_category_id' => $this->ai_pred_cat_id,
                'predicted_category_name' => $this->aiPredictedCategory?->name,
                'confidence' => $this->ai_confidence,
                'auto_disposed' => (bool) (
                    $this->ai_confidence !== null
                    && $this->ai_confidence >= (float) config('services.ai_bert.category_confidence_threshold', 0.85)
                    && ! $this->isAiCorrected()
                ),
                // true = admin mengoreksi tebakan AI (FR-ADM-03, data evaluasi).
                'is_corrected' => $this->isAiCorrected(),

                'predicted_priority' => $this->ai_priority,
                'priority_confidence' => $this->ai_priority_confidence,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
