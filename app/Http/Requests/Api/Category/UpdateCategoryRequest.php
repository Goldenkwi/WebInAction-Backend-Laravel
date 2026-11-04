<?php

namespace App\Http\Requests\Api\Category;

use App\Http\Requests\Api\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi untuk endpoint PUT/PATCH /api/categories/{category}.
 *
 * PERHATIKAN bedanya dengan StoreCategoryRequest:
 * - "name" jadi nullable (opsional) => PATCH = update sebagian field.
 * - aturan unique harus mengabaikan kategori yang SEDANG diedit, kalau tidak
 *   maka user tidak bisa menyimpan kategori dengan nama yang sama dengan
 *   dirinya sendiri ("unique" akan selalu gagal).
 *   Di sinilah Rule::unique()->ignore() digunakan.
 */
class UpdateCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:100',
                // Abaikan baris kategori yang sedang diedit (berdasarkan id).
                Rule::unique('categories', 'name')->ignore($this->route('category')?->id),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'default_agency_id' => ['sometimes', 'nullable', 'integer', 'exists:agencies,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori dengan nama tersebut sudah ada.',
            'default_agency_id.exists' => 'Instansi default yang dipilih tidak ditemukan.',
        ];
    }
}
