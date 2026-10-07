package com.smb.lacak.agent

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.smb.lacak.BuildConfig
import com.smb.lacak.R
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.presentation.MainActivity
import io.socket.client.IO
import io.socket.client.Socket
import io.socket.engineio.client.transports.WebSocket as EngineWebSocket
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.URI

/**
 * §A Foreground Service — mempertahankan koneksi WebSocket (Socket.IO, §43/§44) ke
 * smb-gateway selama memungkinkan. Reconnect/backoff ditangani library socket.io-client
 * (dikonfigurasi dengan konstanta DeviceReconnectPolicy supaya konsisten dgn dokumentasi),
 * DITAMBAH ConnectivityManager.NetworkCallback untuk memicu reconnect secepatnya saat
 * jaringan pulih (§D/§F) — bukan menunggu timer backoff saja.
 *
 * §6 (PHASE 11): heartbeat APPLICATION-LEVEL dikirim periodik lewat socket selama
 * terhubung (jalur utama). Saat socket putus, DeviceRecoveryWork mengambil alih via
 * WorkManager + HTTPS fallback (§45) sampai socket tersambung lagi.
 */
class DeviceAgentService : Service() {
    private val credentialStore by lazy { DeviceCredentialStore(this) }
    private val statusStore by lazy { AgentStatusStore(this) }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var socket: Socket? = null
    private var heartbeatJob: Job? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            statusStore.setAutoReconnect(false)
            disconnectSocket("stopped_by_user")
            stopSelf()
            return START_NOT_STICKY
        }

        val credential = runCatching { credentialStore.load() }.getOrNull()
        if (credential == null) {
            statusStore.write("UNKNOWN", "Credential perangkat tidak tersedia di Android Keystore.")
            stopSelf()
            return START_NOT_STICKY
        }

        try {
            startInForeground()
        } catch (error: RuntimeException) {
            // §69/§70: jujur kalau Android menolak foreground service — jangan diam-diam
            // dianggap "berhasil". PHASE 11 menambah WorkManager sebagai lapisan recovery.
            statusStore.write(
                "DEGRADED",
                "Android menolak foreground service (restriksi battery optimization/background start). " +
                    "Buka aplikasi untuk menyambung ulang secara manual.",
            )
            AgentLogger.warn(credential.deviceId, "Foreground service could not start: ${error.javaClass.simpleName}.")
            stopSelf()
            return START_NOT_STICKY
        }

        statusStore.setAutoReconnect(true)
        registerNetworkCallback()
        connectSocket(credential)
        return START_STICKY
    }

    override fun onDestroy() {
        unregisterNetworkCallback()
        disconnectSocket("service_destroyed")
        scope.cancel()
        super.onDestroy()
    }

    private fun startInForeground() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, android.Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            throw SecurityException("Notification permission is required for the visible device agent.")
        }
        createNotificationChannel()
        val notification = buildNotification("Menyiapkan koneksi ke server SMB.")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun connectSocket(credential: StoredDeviceCredential) {
        if (socket != null) return
        statusStore.write("CONNECTING", "Mengautentikasi koneksi WebSocket ke smb-gateway.")

        val options = IO.Options.builder()
            .setTransports(arrayOf(EngineWebSocket.NAME)) // §43/§44: websocket murni, bukan polling
            .setPath("/ws")
            .setReconnection(true)
            .setReconnectionDelay(DeviceReconnectPolicy.MIN_RETRY_MS)
            .setReconnectionDelayMax(DeviceReconnectPolicy.MAX_RETRY_MS)
            .setAuth(
                mapOf(
                    "device_id" to credential.deviceId,
                    "public_token_id" to credential.publicTokenId,
                    "device_secret" to credential.deviceSecret,
                ),
            )
            .build()

        val newSocket = IO.socket(URI.create(BuildConfig.WS_URL), options)

        newSocket.on(Socket.EVENT_CONNECT) {
            AgentLogger.info(credential.deviceId, "Socket.IO transport connected (auth masih diverifikasi server).")
        }
        newSocket.on("device.connected") { args ->
            val data = args.firstOrNull() as? JSONObject
            statusStore.write("ONLINE", "Terhubung ke smb-gateway (device_id=${data?.optString("device_id") ?: credential.deviceId}).")
            AgentLogger.info(credential.deviceId, "device.connected received from gateway.")
            startHeartbeatLoop(newSocket, credential)
        }
        newSocket.on("device.heartbeat.ack") { args ->
            val data = args.firstOrNull() as? JSONObject
            val serverStatus = data?.optString("status", "UNKNOWN") ?: "UNKNOWN"
            if (data?.optBoolean("accepted", false) == true) {
                statusStore.write(serverStatus, "Heartbeat WebSocket diterima server (${data.optString("server_received_at", "")}).")
            } else {
                statusStore.write("DEGRADED", "Server mengabaikan heartbeat; menunggu siklus berikutnya.")
            }
        }
        newSocket.on("device.disconnected") { args ->
            val data = args.firstOrNull() as? JSONObject
            statusStore.write("DEGRADED", "Gateway melaporkan sesi putus: ${data?.optString("reason") ?: "tidak diketahui"}.")
        }
        newSocket.on(Socket.EVENT_CONNECT_ERROR) { args ->
            val message = (args.firstOrNull() as? Exception)?.message ?: "Autentikasi WebSocket ditolak."
            statusStore.write("DEGRADED", "Koneksi gateway ditolak: $message")
            AgentLogger.warn(credential.deviceId, "connect_error: $message")
            // §45: WS gagal -> pastikan heartbeat tetap jalan lewat HTTPS fallback.
            DeviceRecoveryWork.enqueueImmediate(this@DeviceAgentService)
        }
        newSocket.on(Socket.EVENT_DISCONNECT) { args ->
            heartbeatJob?.cancel()
            val reason = args.firstOrNull()?.toString() ?: "unknown"
            if (reason != "io client disconnect") {
                statusStore.write("DEGRADED", "Koneksi ke gateway terputus ($reason); socket.io-client akan mencoba ulang otomatis.")
                // §45/§F: selama WS belum tersambung ulang, HTTPS fallback mengisi kekosongan.
                DeviceRecoveryWork.enqueueImmediate(this@DeviceAgentService)
            }
        }

        socket = newSocket
        newSocket.connect()
    }

    private fun disconnectSocket(reason: String) {
        heartbeatJob?.cancel()
        socket?.let {
            AgentLogger.info(null, "Disconnecting socket: $reason")
            it.disconnect()
            it.off()
        }
        socket = null
    }

    /**
     * §6: heartbeat berjalan SELAMA socket terhubung, berhenti otomatis saat socket
     * putus (dicek via `socket === this.socket && socket.connected()` tiap iterasi —
     * guard ganda supaya tidak ada dua loop heartbeat jalan bersamaan kalau reconnect
     * terjadi cepat).
     */
    private fun startHeartbeatLoop(targetSocket: Socket, credential: StoredDeviceCredential) {
        heartbeatJob?.cancel()
        heartbeatJob = scope.launch {
            while (isActive && socket === targetSocket && targetSocket.connected()) {
                val payload = withContext(Dispatchers.IO) {
                    DeviceHeartbeatPayload.create(this@DeviceAgentService)
                }
                targetSocket.emit("device.heartbeat", payload)
                AgentLogger.info(credential.deviceId, "WebSocket heartbeat sent.", payload.optString("request_id"))
                delay(HEARTBEAT_INTERVAL_MS)
            }
        }
    }

    private fun registerNetworkCallback() {
        if (networkCallback != null) return
        val connectivity = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                // §F: begitu jaringan pulih, paksa reconnect sekarang — jangan tunggu
                // backoff timer socket.io-client yang mungkin masih lama.
                val current = socket
                if (current != null && !current.connected()) current.connect()
            }
        }
        networkCallback = callback
        runCatching {
            connectivity.registerNetworkCallback(
                NetworkRequest.Builder().addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET).build(),
                callback,
            )
        }.onFailure { error -> AgentLogger.warn(null, "Network callback unavailable: ${error.javaClass.simpleName}.") }
    }

    private fun unregisterNetworkCallback() {
        val callback = networkCallback ?: return
        val connectivity = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        runCatching { connectivity.unregisterNetworkCallback(callback) }
        networkCallback = null
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        if (manager.getNotificationChannel(NOTIFICATION_CHANNEL_ID) != null) return
        manager.createNotificationChannel(
            NotificationChannel(NOTIFICATION_CHANNEL_ID, getString(R.string.notification_channel_name), NotificationManager.IMPORTANCE_LOW)
                .apply { description = getString(R.string.notification_channel_description) },
        )
    }

    private fun buildNotification(content: String): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(this, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_smb)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(content)
            .setContentIntent(pendingIntent)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    companion object {
        const val ACTION_STOP = "com.smb.lacak.action.STOP_AGENT"
        private const val NOTIFICATION_CHANNEL_ID = "smb_lacak_agent"
        private const val NOTIFICATION_ID = 2601
        private const val HEARTBEAT_INTERVAL_MS = 30_000L

        fun startIntent(context: Context) = Intent(context, DeviceAgentService::class.java)
        fun stopIntent(context: Context) = Intent(context, DeviceAgentService::class.java).setAction(ACTION_STOP)
    }
}
