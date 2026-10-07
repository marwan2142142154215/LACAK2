import crypto from 'node:crypto'
import { DateTime } from 'luxon'
import db from '@adonisjs/lucid/services/db'
import type { TransactionClientContract } from '@adonisjs/lucid/types/database'
import Device from '#models/device'
import DeviceNetworkViolation from '#models/device_network_violation'
import SiteNetworkPolicy from '#models/site_network_policy'
import type { NetworkPolicyStatus } from '#services/network_policy_evaluator'
import { sendTelegramMessage } from '#services/telegram_bot_client'

/**
 * §103-106 — port TypeScript dari App\Services\NetworkViolationTracker (Laravel). State
 * machine & semantik dedup WAJIB identik dengan versi PHP (keduanya menulis tabel yang
 * sama, §34) — lihat komentar lengkap di NetworkViolationTracker.php untuk penjelasan
 * NORMAL->VIOLATION->RESOLVED.
 */
export async function recordNetworkViolation(
  device: Device,
  status: NetworkPolicyStatus,
  observedIp: string | null
): Promise<DeviceNetworkViolation | null> {
  return db.transaction(async (trx) => {
    const open = await DeviceNetworkViolation.query({ client: trx })
      .where('device_id', device.id)
      .whereNull('resolved_at')
      .forUpdate()
      .first()

    if (status === 'ALLOWED') {
      if (open) {
        open.useTransaction(trx)
        open.resolvedAt = DateTime.now()
        await open.save()
      }
      return null
    }

    if (open) {
      open.useTransaction(trx)
      open.lastSeenAt = DateTime.now()
      await open.save()
      return open
    }

    const violation = new DeviceNetworkViolation()
    violation.useTransaction(trx)
    violation.fill({
      id: crypto.randomUUID(),
      deviceId: device.id,
      siteId: device.siteId,
      observedIp,
      policyStatus: status,
      severity: status === 'BLOCKED' ? 'HIGH' : 'WARNING',
      firstSeenAt: DateTime.now(),
      lastSeenAt: DateTime.now(),
    })
    await violation.save()

    await sendAlert(device, violation, trx)
    violation.alertSentAt = DateTime.now()
    await violation.save()

    return violation
  })
}

async function sendAlert(
  device: Device,
  violation: DeviceNetworkViolation,
  trx: TransactionClientContract
) {
  const policies = await SiteNetworkPolicy.query({ client: trx })
    .where('site_id', device.siteId)
    .where('is_active', true)
    .whereNull('deleted_at')
  const allowedList = policies.map((p) => p.value).join(', ') || '(belum ada whitelist diisi)'

  const text =
    `⚠️ <b>PERINGATAN JARINGAN</b>\n\n` +
    `Device:\n${device.id}\n\n` +
    `Status:\nNETWORK POLICY VIOLATION (${violation.policyStatus})\n\n` +
    `IP terdeteksi:\n${violation.observedIp ?? 'tidak diketahui'}\n\n` +
    `IP yang diizinkan:\n${allowedList}\n\n` +
    `Waktu:\n${DateTime.now().toFormat('yyyy-MM-dd HH:mm:ss')}`

  const accounts = await db
    .from('telegram_accounts')
    .where('status', 'APPROVED')
    .select('telegram_id')
  await Promise.all(
    accounts.map((account) => sendTelegramMessage(Number(account.telegram_id), text))
  )
}
