<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreRegistrationCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceRegistrationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationCodeController extends Controller
{
    /**
     * Karakter yang dipakai sengaja menghindari 0/O dan 1/I/l (ambigu saat dibaca manusia
     * dari layar admin lalu diketik ulang di device, §17).
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * POST /api/v1/devices/registration-codes — §17/§31 Download Center flow.
     * Admin pilih Site + Team -> server generate kode SEKALI tampil (tidak disimpan plaintext).
     */
    public function store(StoreRegistrationCodeRequest $request): JsonResponse
    {
        $plainCode = $this->generateCode();

        // code_hash WAJIB diset SEBELUM save() pertama — kolomnya NOT NULL di DB.
        // ::create() akan langsung INSERT begitu dipanggil, jadi tidak bisa forceFill()
        // sesudahnya (ketahuan lewat not-null violation nyata saat testing).
        //
        // SHA-256, BUKAN bcrypt: kode ini high-entropy random token (20 char dari alfabet
        // 33 char, >100 bit entropi), bukan password manusia — bcrypt (lambat, untuk
        // melawan brute force kamus pada secret berentropi rendah) tidak diperlukan DAN
        // menghalangi lookup langsung by hash (bcrypt salted-nondeterministic, butuh iterasi
        // semua baris). SHA-256 memungkinkan WHERE code_hash = ? langsung kena unique index
        // — pola yang sama dipakai Laravel Sanctum sendiri untuk personal_access_tokens.
        $registrationCode = new DeviceRegistrationCode([
            'site_id' => $request->site_id,
            'team_id' => $request->team_id,
            'created_by' => $request->user()->id,
            'expires_at' => now()->addMinutes($request->integer('expires_in_minutes', 15)),
        ]);
        $registrationCode->forceFill(['code_hash' => hash('sha256', $plainCode)]);
        $registrationCode->save();

        activity()
            ->causedBy($request->user())
            ->performedOn($registrationCode)
            ->withProperties(['site_id' => $request->site_id, 'team_id' => $request->team_id])
            ->log('registration_code_generated');

        return ApiResponse::success('Registration code dibuat. Kode ini hanya ditampilkan sekali.', [
            'code' => $plainCode,
            'expires_at' => $registrationCode->expires_at,
            'site_id' => $registrationCode->site_id,
            'team_id' => $registrationCode->team_id,
        ], 201);
    }

    /**
     * GET /api/v1/devices/registration-codes — riwayat kode (audit, §38 pagination).
     * code_hash tidak pernah ikut (hidden di model) — hanya metadata & status.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('devices.create', DeviceRegistrationCode::class);

        $codes = DeviceRegistrationCode::query()
            ->with(['site:id,name', 'team:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Riwayat registration code.', $codes);
    }

    private function generateCode(): string
    {
        return collect(range(1, 20))
            ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
            ->implode('');
    }
}
