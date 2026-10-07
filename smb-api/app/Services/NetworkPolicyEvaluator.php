<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SiteNetworkPolicy;
use Illuminate\Http\Request;

enum NetworkPolicyStatus: string
{
    case ALLOWED = 'ALLOWED';
    case BLOCKED = 'BLOCKED';
    case UNKNOWN = 'UNKNOWN';
}

/**
 * §97-102 — evaluasi IP observed device terhadap whitelist jaringan Site-nya.
 *
 * §102/§131: ini SALAH SATU sinyal keamanan, bukan satu-satunya (kombinasi dengan device
 * credential + site binding + server authorization yang sudah ada sejak PHASE 9/12).
 * §99: pengecekan utama WAJIB server-side — kelas ini dipanggil dari
 * DeviceHeartbeatController (Laravel) & websocket_service.ts (AdonisJS), bukan di APK.
 */
class NetworkPolicyEvaluator
{
    /**
     * §100: resolve IP client ASLI di belakang Cloudflare Tunnel. `CF-Connecting-IP` di-set
     * oleh edge Cloudflare sendiri (tidak bisa dipalsukan client — request langsung dari
     * client tidak pernah sampai ke origin, SELALU lewat tunnel) — jauh lebih bisa dipercaya
     * daripada `client_reported_ip` arbitrary dari body request (§101 — jangan percaya SSID/IP
     * yang device klaim sendiri sebagai bukti keamanan).
     */
    public function resolveObservedIp(Request $request): ?string
    {
        $cfConnectingIp = $request->header('CF-Connecting-IP');
        if (is_string($cfConnectingIp) && filter_var($cfConnectingIp, FILTER_VALIDATE_IP)) {
            return $cfConnectingIp;
        }

        return $request->ip();
    }

    public function evaluate(Device $device, ?string $observedIp): NetworkPolicyStatus
    {
        if ($observedIp === null || ! filter_var($observedIp, FILTER_VALIDATE_IP)) {
            return NetworkPolicyStatus::UNKNOWN;
        }

        $policies = SiteNetworkPolicy::query()
            ->where('site_id', $device->site_id)
            ->where('is_active', true)
            ->get();

        // §98/§131: Site tanpa policy terkonfigurasi = tidak ada restriksi jaringan untuk
        // Site itu (default-open) — admin HARUS secara eksplisit mengisi whitelist kalau
        // mau restriksi, bukan diam-diam di-BLOCKED tanpa konfigurasi apa pun (§66 jujur:
        // jangan "gagal" tanpa alasan yang admin sendiri atur).
        if ($policies->isEmpty()) {
            return NetworkPolicyStatus::ALLOWED;
        }

        foreach ($policies as $policy) {
            if ($this->matches($policy, $observedIp)) {
                return NetworkPolicyStatus::ALLOWED;
            }
        }

        return NetworkPolicyStatus::BLOCKED;
    }

    private function matches(SiteNetworkPolicy $policy, string $observedIp): bool
    {
        return match ($policy->network_type) {
            'IP' => hash_equals($policy->value, $observedIp),
            'CIDR' => $this->ipInCidr($observedIp, $policy->value),
            default => false,
        };
    }

    /** Dukungan CIDR IPv4 sederhana — cukup untuk kebutuhan whitelist jaringan kantor/site. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $maskBits] = explode('/', $cidr, 2);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            ! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $maskBits = (int) $maskBits;
        if ($maskBits < 0 || $maskBits > 32) {
            return false;
        }
        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
