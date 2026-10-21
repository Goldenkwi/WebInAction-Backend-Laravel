<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Model User = akun yang dipakai oleh 3 aktor di SRS Bab 1:
 *   citizen        -> masyarakat pelapor
 *   verifier_admin -> admin verifikator
 *   agency_admin   -> admin instansi / unit kerja
 *
 * ---------------------------------------------------------------------------
 * KONSEP: Attribute Fillable & Hidden (Laravel 11+)
 * ---------------------------------------------------------------------------
 * Cara modern (Laravel 11+) menulis aturan mass-assignment:
 *
 *   #[Fillable(['name', 'email', 'password'])]
 *   #[Hidden(['password', 'remember_token'])]
 *
 * - Fillable : field yang BOLEH diisi lewat mass-assignment (create/update/fill).
 *              Field TIDAK ada di sini akan diabaikan diam-diam.
 * - Hidden   : field yang tidak pernah ikut di-serialize ke JSON.
 *
 * Kenapa 'role' TIDAK ada di Fillable?
 * Karena role harus ditentukan oleh server, bukan boleh datang dari input
 * client. Kalau 'role' ikut fillable, penyerang bisa mengirim
 * { "name": "x", "email": "y", "password": "z", "role": "verifier_admin" }
 * dan langsung mendapat hak admin. Ini namanya privilege escalation.
 * Role di-set lewat method registerCitizen() yang selalu mengunci citizen.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Daftar role yang sah. Dipakai juga oleh migration (enum) dan Gate.
     */
    public const ROLE_CITIZEN = 'citizen';

    public const ROLE_VERIFIER = 'verifier_admin';

    public const ROLE_AGENCY = 'agency_admin';

    /**
     * Cast atribut. 'hashed' berarti: nilai yang dimasukkan ke atribut password
     * akan otomatis di-hash dengan bcrypt sebelum disimpan (NFR-02).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Daftar laporan milik user ini.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Reports::class);
    }

    /**
     * Apakah user ini termasuk kelompok pengelola (admin)?
     *
     * Dipakai di:
     *  - Form Request authorize()
     *  - Gate 'manage-categories' / 'manage-reports' (lihat AppServiceProvider)
     *  - UserResource untuk menentukan data mana yang boleh ditampilkan
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_VERIFIER, self::ROLE_AGENCY], true);
    }

    /**
     * Apakah user ini masyarakat pelapor biasa?
     */
    public function isCitizen(): bool
    {
        return $this->role === self::ROLE_CITIZEN;
    }

    /**
     * Register akun warga baru.
     *
     * Sengaja dibuat sebagai method di Model (bukan create([...]) di controller)
     * supaya aturan "role selalu citizen" berada di satu tempat dan tidak
     * bisa dilanggar dari mana pun.
     */
    public static function registerCitizen(string $name, string $email, string $password): static
    {
        $user = new static;

        // Password TIDAK di-hash manual di sini. Cast 'hashed' yang
        // menangani hashing-nya secara otomatis.
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = self::ROLE_CITIZEN;

        $user->save();

        return $user;
    }
}
