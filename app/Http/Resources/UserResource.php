<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource untuk model User.
 *
 * ---------------------------------------------------------------------------
 * ATURAN KEAMANAN YANG DITERAPKAN DI SINI (NFR-02)
 * ---------------------------------------------------------------------------
 * a) PASSWORD TIDAK PERNAH dikembalikan.
 *    Field 'password' disembunyikan di model lewat atribut #[Hidden], dan
 *    Resource ini juga tidak pernah memanggilnya.
 *
 * b) EMAIL hanya ditampilkan kepada:
 *    - pemilik akun itu sendiri, atau
 *    - admin (verifier_admin / agency_admin).
 *    Untuk user lain, field email dihilangkan dari payload.
 *
 * c) Untuk pelapor yang anonim (is_anonymous = true), frontend halaman
 *    publik harus menampilkan "Anonymous". Logika itu ada di ReportsResource
 *    (nanti saat modul laporan dikerjakan), karena flag is_anonymous berada
 *    di tabel reports, bukan di users.
 */
class UserResource extends JsonResource
{
    /**
     * User yang dianggap "penampil" data ini.
     *
     * Kenapa perlu? Pada response register & login, user BELUM terautentikasi
     * saat request berjalan (token baru saja dibuat), sehingga $request->user()
     * masih null. Padahal frontend WAJIB menerima email-nya sendiri.
     *
     * Solusi: controller memberi tahu Resource siapa yang sedang melihat
     * lewat ->forViewer($user). Kalau tidak dipanggil, Resource otomatis
     * memakai $request->user() (token yang dikirim di header).
     */
    protected ?User $viewer = null;

    /**
     * Tetapkan user penampil secara eksplisit.
     */
    public function forViewer(?User $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->viewer ?? $request->user();
        $isOwner = $viewer !== null && $viewer->id === $this->id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,

            // when($kondisi, $nilai): kalau kondisi false, key-nya dihapus
            // dari hasil JSON (bukan diisi null).
            'email' => $this->when($isOwner || $isAdmin, $this->email),

            'role' => $this->role,
            'role_label' => $this->roleLabel(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Label role yang lebih ramah dibaca manusia (dipakai untuk badge di UI).
     */
    protected function roleLabel(): string
    {
        return match ($this->role) {
            User::ROLE_VERIFIER => 'Admin Verifikator',
            User::ROLE_AGENCY => 'Admin Instansi',
            default => 'Warga',
        };
    }
}
