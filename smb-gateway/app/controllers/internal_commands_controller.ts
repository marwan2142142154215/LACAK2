import type { HttpContext } from '@adonisjs/core/http'
import websocketService from '#services/websocket_service'

/**
 * POST /api/v1/internal/commands/dispatch — dipanggil Laravel (smb-api) setelah
 * membuat device_commands row (§19). Di balik InternalAuthMiddleware.
 */
export default class InternalCommandsController {
  async dispatch({ request, response }: HttpContext) {
    const commandId = request.input('command_id')

    if (!commandId) {
      return response.badRequest({ success: false, message: 'command_id wajib diisi.', errors: [] })
    }

    const result = await websocketService.dispatchCommand(commandId)

    return response.ok({
      success: true,
      message: result.dispatched ? 'Command dikirim ke device.' : `Command belum dikirim: ${result.reason}`,
      data: result,
    })
  }
}
