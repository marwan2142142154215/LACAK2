import Device from '#models/device'
import SiteNetworkPolicy from '#models/site_network_policy'

export type NetworkPolicyStatus = 'ALLOWED' | 'BLOCKED' | 'UNKNOWN'

/**
 * §97-102 — port TypeScript dari App\Services\NetworkPolicyEvaluator (Laravel). HARUS
 * tetap sinkron secara perilaku dengan versi PHP — keduanya mengevaluasi tabel
 * `site_network_policies` yang SAMA (§34, source of truth bersama).
 */
export function isValidIp(value: string | null | undefined): value is string {
  if (!value) return false
  const ipv4 = /^(\d{1,3}\.){3}\d{1,3}$/
  const ipv6 = /^[0-9a-fA-F:]+$/
  return (
    (ipv4.test(value) && value.split('.').every((octet) => Number(octet) <= 255)) ||
    ipv6.test(value)
  )
}

/** §100 — resolve IP client asli di belakang Cloudflare Tunnel, dari header handshake WS. */
export function resolveObservedIp(
  headers: Record<string, string | string[] | undefined>,
  fallbackAddress: string
): string | null {
  const header = headers['cf-connecting-ip']
  const cfIp = Array.isArray(header) ? header[0] : header
  if (isValidIp(cfIp)) return cfIp as string

  return isValidIp(fallbackAddress) ? fallbackAddress : null
}

function ipToLong(ip: string): number {
  return ip.split('.').reduce((acc, octet) => (acc << 8) + Number(octet), 0) >>> 0
}

function ipInCidr(ip: string, cidr: string): boolean {
  if (!cidr.includes('/')) return false
  const [subnet, maskBitsRaw] = cidr.split('/')
  if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ip) || !/^(\d{1,3}\.){3}\d{1,3}$/.test(subnet)) return false
  const maskBits = Number(maskBitsRaw)
  if (!Number.isInteger(maskBits) || maskBits < 0 || maskBits > 32) return false
  const mask = maskBits === 0 ? 0 : (0xffffffff << (32 - maskBits)) >>> 0
  return (ipToLong(ip) & mask) === (ipToLong(subnet) & mask)
}

export async function evaluateNetworkPolicy(
  device: Device,
  observedIp: string | null
): Promise<NetworkPolicyStatus> {
  if (!isValidIp(observedIp)) return 'UNKNOWN'

  const policies = await SiteNetworkPolicy.query()
    .where('site_id', device.siteId)
    .where('is_active', true)
    .whereNull('deleted_at')

  // §98/§131: Site tanpa policy = default-open (sama persis dengan sisi Laravel).
  if (policies.length === 0) return 'ALLOWED'

  const matches = policies.some((policy) =>
    policy.networkType === 'IP' ? policy.value === observedIp : ipInCidr(observedIp, policy.value)
  )

  return matches ? 'ALLOWED' : 'BLOCKED'
}
