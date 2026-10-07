package com.smb.master.ble

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothManager
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanFilter
import android.bluetooth.le.ScanResult
import android.bluetooth.le.ScanSettings
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.ParcelUuid
import androidx.core.content.ContextCompat
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update

/**
 * §14 SMB Master sebagai BLE CENTRAL/SCANNER. Scan nyata via android.bluetooth.le API resmi
 * (bukan simulasi RSSI, §79/§88) — di-filter dengan BleProximityProtocol.SERVICE_UUID supaya
 * hanya SMB Lacak yang dikenali, sesuai §14.
 *
 * Catatan kejujuran batas lingkungan pengujian: kelas ini TIDAK dapat diverifikasi
 * end-to-end (Master benar-benar menemukan Lacak lewat radio Bluetooth nyata) di
 * lingkungan sesi ini karena tidak ada perangkat/emulator dengan radio BLE fisik yang
 * tersedia. Logika murni (smoothing+klasifikasi) sudah diuji lewat RssiSmootherTest dan
 * ProximityClassifierTest — pengujian radio BLE fisik Master<->Lacak masih harus
 * dilakukan di perangkat nyata sebelum fase ini dinyatakan DONE (lihat docs/DECISIONS.md).
 */
class BleCentralScanner(private val context: Context) {
    private val _state = MutableStateFlow<BleScanState>(BleScanState.Idle)
    val state: StateFlow<BleScanState> = _state

    private val _nearbyDevices = MutableStateFlow<Map<String, NearbyDevice>>(emptyMap())
    val nearbyDevices: StateFlow<Map<String, NearbyDevice>> = _nearbyDevices

    private val smoothers = mutableMapOf<String, RssiSmoother>()
    private var scanCallback: ScanCallback? = null

    fun startScanning() {
        val adapter = bluetoothAdapter()
        if (adapter == null || !adapter.isEnabled) {
            _state.update { BleScanState.BleDisabled }
            return
        }
        if (!hasScanPermission()) {
            _state.update { BleScanState.PermissionRequired }
            return
        }

        val scanner = adapter.bluetoothLeScanner
        if (scanner == null) {
            _state.update { BleScanState.Error("Adapter Bluetooth tidak menyediakan BLE scanner.") }
            return
        }

        val filter = ScanFilter.Builder()
            .setServiceUuid(ParcelUuid(BleProximityProtocol.SERVICE_UUID))
            .build()
        val settings = ScanSettings.Builder()
            .setScanMode(ScanSettings.SCAN_MODE_BALANCED) // §73: hemat baterai, bukan SCAN_MODE_LOW_LATENCY terus-menerus.
            .build()

        val callback = object : ScanCallback() {
            override fun onScanResult(callbackType: Int, result: ScanResult) {
                onResult(result)
            }

            override fun onBatchScanResults(results: MutableList<ScanResult>) {
                results.forEach(::onResult)
            }

            override fun onScanFailed(errorCode: Int) {
                _state.update { BleScanState.Error("BLE scan gagal (errorCode=$errorCode).") }
            }
        }

        runCatching {
            scanner.startScan(listOf(filter), settings, callback)
            scanCallback = callback
            _state.update { BleScanState.Scanning }
        }.onFailure { error ->
            _state.update { BleScanState.Error(error.javaClass.simpleName) }
        }
    }

    fun stopScanning() {
        val adapter = bluetoothAdapter() ?: return
        val scanner = adapter.bluetoothLeScanner
        val callback = scanCallback
        if (scanner != null && callback != null && hasScanPermission()) {
            runCatching { scanner.stopScan(callback) }
        }
        scanCallback = null
        _state.update { BleScanState.Idle }
    }

    /** §17: hapus device dari radar jika tidak terdeteksi lagi melewati timeout — dipanggil periodik oleh caller. */
    fun pruneStaleDevices(nowMs: Long) {
        _nearbyDevices.update { current ->
            current.filterValues { nowMs - it.lastSeenAtMs < BleProximityProtocol.NOT_DETECTED_TIMEOUT_MS }
        }
    }

    private fun onResult(result: ScanResult) {
        val ephemeralId = extractEphemeralId(result) ?: return
        val nowMs = System.currentTimeMillis()
        val smoother = smoothers.getOrPut(ephemeralId) { RssiSmoother() }
        val smoothed = smoother.addSample(result.rssi)
        val proximity = ProximityClassifier.classify(smoothed)
        val distance = ProximityClassifier.estimatedDistanceMeters(smoothed)

        _nearbyDevices.update { current ->
            val existing = current[ephemeralId]
            current + (ephemeralId to NearbyDevice(
                ephemeralId = ephemeralId,
                lastRssiDbm = result.rssi,
                smoothedRssiDbm = smoothed,
                proximity = proximity,
                estimatedDistanceMeters = distance,
                firstSeenAtMs = existing?.firstSeenAtMs ?: nowMs,
                lastSeenAtMs = nowMs,
            ))
        }
    }

    /** §16: identifier HANYA diambil dari manufacturer data ephemeral, tidak pernah dari device name. */
    private fun extractEphemeralId(result: ScanResult): String? {
        val manufacturerData = result.scanRecord?.getManufacturerSpecificData(BleProximityProtocol.MANUFACTURER_ID)
            ?: return null
        if (manufacturerData.size < BleProximityProtocol.EPHEMERAL_ID_LENGTH_BYTES) return null
        return manufacturerData.joinToString(separator = "") { byte -> "%02x".format(byte) }
    }

    private fun bluetoothAdapter(): BluetoothAdapter? =
        (context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter

    private fun hasScanPermission(): Boolean {
        val permission = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Manifest.permission.BLUETOOTH_SCAN
        } else {
            Manifest.permission.ACCESS_FINE_LOCATION
        }
        return ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
    }
}
