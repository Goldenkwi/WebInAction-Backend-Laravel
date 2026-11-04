<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk model Agency.
 *
 * Field `logo_full_url` berasal dari accessor model, sedangkan `categories`
 * hanya muncul kalau controller memanggil with('categories'). Begitu juga
 * angka statistik: hanya muncul kalau query memakai withCount().
 */
class AgencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'acronym' => $this->acronym,
            'description' => $this->description,

            'logo_url' => $this->logo_url,
            'logo_full_url' => $this->logo_full_url,

            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,

            'sla_days' => $this->sla_days,
            'is_active' => (bool) $this->is_active,

            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->icon,
                'default_agency_id' => $category->default_agency_id,
            ])->values()),

            'categories_count' => $this->whenCounted('categories'),
            'users_count' => $this->whenCounted('users'),
            'total_reports' => $this->whenCounted('reports'),
            'resolved_reports_count' => $this->whenNotNull($this->resolved_reports_count),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
