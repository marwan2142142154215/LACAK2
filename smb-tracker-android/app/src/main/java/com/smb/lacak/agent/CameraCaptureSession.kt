package com.smb.lacak.agent

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.ImageFormat
import android.hardware.camera2.CameraCaptureSession
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraDevice
import android.hardware.camera2.CameraManager
import android.hardware.camera2.CaptureRequest
import android.media.Image
import android.media.ImageReader
import android.os.Handler
import android.os.HandlerThread
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import org.json.JSONObject
import java.security.MessageDigest
import java.time.Instant
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/**
 * §27/§28/§110-114 — capture SATU foto JPEG via Camera2 resmi (BUKAN hidden/exploit) lalu
 * upload LANGSUNG ke URL yang sudah disiapkan Laravel — secara default URL signed ke
 * storage lokal server (`MediaStorageService`, §110), atau presigned Spaces URL kalau
 * `SMB_MEDIA_DISK=spaces` dikonfigurasi. Device tidak perlu tahu/peduli mana yang dipakai
 * — keduanya sama-sama HTTP PUT biasa (`uploadToStorage`). Dijalankan dari
 * DeviceAgentService (foreground service type "camera", §7) — TIDAK membuka
 * Activity/preview (tidak ada UI capture), tapi indikator privasi kamera Android (titik
 * hijau, API 29+) tetap muncul karena memang benar2 memakai kamera — ini JUJUR, bukan
 * disembunyikan dari OS (§69/§78: tidak ada accessibility abuse/bypass permission).
 */
object CameraCaptureSession {
    sealed class Result {
        data class Success(val result: JSONObject) : Result()
        data class Unavailable(val reason: String) : Result()
    }

    suspend fun captureAndUpload(context: Context, facing: String, uploadUrl: String, uploadHeaders: Map<String, String>): Result {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            return Result.Unavailable("Izin CAMERA belum diberikan.")
        }

        val manager = context.getSystemService(Context.CAMERA_SERVICE) as CameraManager
        val wantedLensFacing = if (facing == "FRONT") CameraCharacteristics.LENS_FACING_FRONT else CameraCharacteristics.LENS_FACING_BACK
        val cameraId = runCatching {
            manager.cameraIdList.firstOrNull { id ->
                manager.getCameraCharacteristics(id).get(CameraCharacteristics.LENS_FACING) == wantedLensFacing
            }
        }.getOrNull() ?: return Result.Unavailable("Kamera $facing tidak tersedia di perangkat ini.")

        val jpegBytes = try {
            captureJpeg(context, manager, cameraId)
        } catch (error: SecurityException) {
            return Result.Unavailable("Izin kamera ditolak OS saat membuka device: ${error.message}")
        } catch (error: Exception) {
            return Result.Unavailable("Gagal mengambil foto: ${error.javaClass.simpleName}: ${error.message}")
        }

        val sha256 = MessageDigest.getInstance("SHA-256").digest(jpegBytes).joinToString("") { "%02x".format(it) }

        val uploaded = uploadToStorage(uploadUrl, uploadHeaders, jpegBytes)
        if (!uploaded) {
            return Result.Unavailable("Upload ke storage gagal (jaringan atau URL presigned sudah kedaluwarsa).")
        }

        return Result.Success(
            JSONObject()
                .put("mime_type", "image/jpeg")
                .put("size_bytes", jpegBytes.size)
                .put("sha256_hash", sha256)
                .put("captured_at", Instant.now().toString()),
        )
    }

    private suspend fun captureJpeg(context: Context, manager: CameraManager, cameraId: String): ByteArray =
        suspendCancellableCoroutine { continuation ->
            val thread = HandlerThread("smb-camera-capture").apply { start() }
            val handler = Handler(thread.looper)

            var imageReader: ImageReader? = null
            var cameraDevice: CameraDevice? = null

            fun cleanup() {
                runCatching { cameraDevice?.close() }
                runCatching { imageReader?.close() }
                thread.quitSafely()
            }

            try {
                imageReader = ImageReader.newInstance(1280, 960, ImageFormat.JPEG, 1)
                imageReader.setOnImageAvailableListener({ reader ->
                    val image: Image? = reader.acquireLatestImage()
                    if (image != null) {
                        val buffer = image.planes[0].buffer
                        val bytes = ByteArray(buffer.remaining())
                        buffer.get(bytes)
                        image.close()
                        cleanup()
                        if (continuation.isActive) continuation.resume(bytes)
                    }
                }, handler)

                manager.openCamera(cameraId, object : CameraDevice.StateCallback() {
                    override fun onOpened(device: CameraDevice) {
                        cameraDevice = device
                        val surface = imageReader.surface
                        device.createCaptureSession(
                            listOf(surface),
                            object : CameraCaptureSession.StateCallback() {
                                override fun onConfigured(session: CameraCaptureSession) {
                                    val request = device.createCaptureRequest(CameraDevice.TEMPLATE_STILL_CAPTURE)
                                        .apply { addTarget(surface) }
                                        .build()
                                    session.capture(request, null, handler)
                                }

                                override fun onConfigureFailed(session: CameraCaptureSession) {
                                    cleanup()
                                    if (continuation.isActive) {
                                        continuation.resumeWithException(IllegalStateException("Capture session configure failed."))
                                    }
                                }
                            },
                            handler,
                        )
                    }

                    override fun onDisconnected(device: CameraDevice) {
                        cleanup()
                        if (continuation.isActive) continuation.resumeWithException(IllegalStateException("Camera disconnected."))
                    }

                    override fun onError(device: CameraDevice, error: Int) {
                        cleanup()
                        if (continuation.isActive) continuation.resumeWithException(IllegalStateException("Camera error code $error."))
                    }
                }, handler)
            } catch (error: Exception) {
                cleanup()
                if (continuation.isActive) continuation.resumeWithException(error)
            }

            continuation.invokeOnCancellation { cleanup() }
        }

    private fun uploadToStorage(url: String, headers: Map<String, String>, bytes: ByteArray): Boolean {
        return try {
            val connection = (java.net.URL(url).openConnection() as java.net.HttpURLConnection).apply {
                requestMethod = "PUT"
                doOutput = true
                connectTimeout = 15_000
                readTimeout = 30_000
                headers.forEach { (key, value) -> setRequestProperty(key, value) }
            }
            connection.outputStream.use { it.write(bytes) }
            val code = connection.responseCode
            connection.disconnect()
            code in 200..299
        } catch (_: Exception) {
            false
        }
    }
}
