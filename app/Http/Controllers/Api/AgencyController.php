<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agency\StoreAgencyRequest;
use App\Http\Requests\Api\Agency\UpdateAgencyRequest;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ============================================================================
 * AGENCY CONTROLLER
 * ============================================================================
 * Endpoint:
 *   GET    /api/agencies                 -> daftar instansi       (Publik)
 *   GET    /api/agencies/{agency}        -> detail instansi       (Publik)
 *   POST   /api/admin/agencies           -> tambah instansi       (Admin)
 *   POST   /api/admin/agencies/{agency}  -> ubah instansi + logo  (Admin)
 *   PUT    /api/admin/agencies/{agency}  -> ubah instansi         (Admin)
 *   DELETE /api/admin/agencies/{agency}  -> hapus instansi        (Admin)
 *
 * Alur data:
 *   - categories.default_agency_id = rekomendasi instansi untuk AI/verifikator.
 *   - reports.assigned_agency_id   = instansi FINAL hasil keputusan admin.
 *   - users.agency_id              = instansi tempat petugas agency_admin.
 */
class AgencyController extends Controller
{
    /**
     * GET /api/agencies
     *
     * Query opsional:
     *   ?active_only=0  -> tampilkan juga instansi nonaktif (khusus admin)
     *   ?search=pupr    -> cari nama atau singkatan
     */
    public function index(Request $request): JsonResponse
    {
        $agencies = Agency::query()
            ->with(['categories:id,name,icon,default_agency_id'])
            ->search((string) $request->input('search'))
            ->when(
                $request->boolean('active_only', true),
                fn ($query) => $query->active()
            )
            ->orderBy('name')
            ->get();

        return $this->sendResponse(
            AgencyResource::collection($agencies)->resolve($request),
            'Daftar instansi berhasil dimuat.'
        );
    }

    /**
     * GET /api/agencies/{agency}
     *
     * Menyertakan kategori yang ditangani serta statistik laporan instansi.
     */
    public function show(Request $request, Agency $agency): JsonResponse
    {
        $agency->load(['categories:id,name,icon,default_agency_id'])
            ->loadCount([
                'categories',
                'users',
                'reports',
                'reports as resolved_reports_count' => fn ($query) => $query
                    ->where('status', Reports::STATUS_FOLLOWED_UP),
            ]);

        return $this->sendResponse(
            (new AgencyResource($agency))->resolve($request),
            'Detail instansi berhasil dimuat.'
        );
    }

    /**
     * POST /api/admin/agencies
     *
     * Logo dikirim sebagai file `logo` (multipart/form-data), disimpan di
     * disk `public`, lalu path-nya disimpan di kolom `logo_url`.
     */
    public function store(StoreAgencyRequest $request): JsonResponse
    {
        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo_url'] = $request->file('logo')->store('agencies', 'public');
        }

        $agency = Agency::create($data);

        return $this->sendResponse(
            (new AgencyResource($agency))->resolve($request),
            'Instansi berhasil ditambahkan.',
            201
        );
    }

    /**
     * POST/PUT/PATCH /api/admin/agencies/{agency}
     *
     * Kalau ada logo baru, logo lama dihapus agar storage tidak menumpuk.
     * `remove_logo=true` dipakai untuk menghapus logo tanpa mengunggah file.
     */
    public function update(UpdateAgencyRequest $request, Agency $agency): JsonResponse
    {
        $data = $request->safe()->except(['logo', 'remove_logo']);

        if ($request->hasFile('logo')) {
            $this->deleteStoredLogo($agency);
            $data['logo_url'] = $request->file('logo')->store('agencies', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteStoredLogo($agency);
            $data['logo_url'] = null;
        }

        $agency->update($data);

        return $this->sendResponse(
            (new AgencyResource($agency->fresh(['categories'])))->resolve($request),
            'Data instansi berhasil diperbarui.'
        );
    }

    /**
     * DELETE /api/admin/agencies/{agency}
     *
     * Instansi yang masih dipakai user/kategori/laporan TIDAK dihapus, supaya
     * histori laporan tidak kehilangan konteks. Admin diminta menonaktifkan
     * (is_active=false) saja.
     */
    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        if ($agency->categories()->exists() || $agency->reports()->exists() || $agency->users()->exists()) {
            return $this->sendError(
                'Instansi masih dipakai oleh kategori, laporan, atau akun petugas. '
                .'Nonaktifkan instansi ini alih-alih menghapusnya agar histori tetap utuh.',
                409
            );
        }

        $this->deleteStoredLogo($agency);
        $agency->delete();

        return $this->sendResponse(null, 'Instansi berhasil dihapus.');
    }

    /**
     * Hapus file logo lokal dari disk `public`.
     *
     * URL eksternal tidak disentuh karena bukan milik storage aplikasi.
     */
    protected function deleteStoredLogo(Agency $agency): void
    {
        if (blank($agency->logo_url) || filter_var($agency->logo_url, FILTER_VALIDATE_URL)) {
            return;
        }

        if (Storage::disk('public')->exists($agency->logo_url)) {
            Storage::disk('public')->delete($agency->logo_url);
        }
    }
}
