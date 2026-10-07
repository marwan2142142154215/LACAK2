import type { CommandStatus } from '#models/device_command'

/**
 * §20/§21 — state machine command. Urutan forward-only; status terminal TIDAK BISA
 * ditimpa lagi (mencegah command lama yang "nyasar" diterima setelah command baru
 * untuk device yang sama sudah SUCCESS/FAILED — anti race condition §21).
 */
const ORDER: CommandStatus[] = ['PENDING', 'QUEUED', 'SENT', 'DELIVERED', 'RECEIVED', 'EXECUTING']
const TERMINAL: CommandStatus[] = ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED']

/** Transisi yang boleh DIAJUKAN DEVICE lewat ack (bukan oleh scheduler/admin). */
export function isValidDeviceAckTransition(from: CommandStatus, to: CommandStatus): boolean {
  if (TERMINAL.includes(from)) return false // §21: status terminal tidak bisa ditimpa
  if (to === 'SUCCESS' || to === 'FAILED') return true // boleh dari status non-terminal manapun

  const fromIndex = ORDER.indexOf(from)
  const toIndex = ORDER.indexOf(to)
  if (fromIndex === -1 || toIndex === -1) return false

  return toIndex > fromIndex // forward-only, tidak bisa mundur
}
