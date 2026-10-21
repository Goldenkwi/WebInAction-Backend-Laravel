<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk DETAIL satu laporan + timeline (FR-PUB-02).
 *
 * Bentuknya mengikuti contoh JSON di SRS Bab 4 (Contoh 2):
 *   id, ticket_number, reporter_name, title, description, incident_date,
 *   location_text, image_url, category{id,name}, ai_confidence, status,
 *   timeline_logs[]
 */
class ReportDetailResource extends JsonResource
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
            'description' => $this->description,
            'report_type' => $this->report_type,

            'reporter_name' => $this->is_anonymous && ! $canSeeName
                ? 'Anonymous'
                : $this->user?->name,

            'incident_date' => $this->incident_date?->toDateString(),
            'location_text' => $this->location_text,
            'image_url' => $this->image_url,

            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ] : null,

            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'priority' => $this->priority,
            'priority_label' => $this->priorityLabel(),

            'is_anonymous' => (bool) $this->is_anonymous,
            'is_private' => (bool) $this->is_private,

            'ai_analysis' => [
                'predicted_category_id' => $this->ai_pred_cat_id,
                'predicted_category_name' => $this->aiPredictedCategory?->name,
                'confidence' => $this->ai_confidence,
                'is_corrected' => $this->isAiCorrected(),
                'predicted_priority' => $this->ai_priority,
                'priority_confidence' => $this->ai_priority_confidence,
            ],

            // Timeline 3 tahap + riwayat pesan dari tabel reports_logs.
            'timeline_logs' => ReportsLogResource::collection($this->logs)->resolve($request),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
