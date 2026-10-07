package com.smb.lacak.ble

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothManager
import android.bluetooth.le.AdvertiseCallback
import android.bluetooth.le.AdvertiseData
import android.bluetooth.le.AdvertiseSettings
import android.bluetooth.le.BluetoothLeAdvertiser
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.ParcelUuid
import androidx.core.content.ContextCompat
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import java.security.SecureRandom

/** §50 — status subsistem BLE advertising Lacak, ditampilkan jujur ke UI (§75/§88). */
sealed class BleAdvertiseState {
    data object Idle : BleAdvertiseState()
    data object BleDisabled : BleAdvertiseState()
    data object PermissionRequired : BleAdvertiseState()
    data object Advertising : BleAdvertiseState()
    data class Error(val reason: String) : BleAdvertiseState()
    data object Unsupported : BleAdvertiseState()
}

/**
 * §14 SMB Lacak sebagai BLE PERIPHERAL/ADVERTISER. Advertise nyata lewat
 * android.bluetooth.le.BluetoothLeAdvertiser (bukan simulasi, §79/§88) — payload HANYA
 * service UUID + ephemeral identifier acak yang dirotasi periodik (§16), tidak pernah
 * berisi device_id/credential/secret apa pun.
 *
 * Batasan jujur: tidak semua chipset Android mendukung BLE peripheral/advertising
 * (`BluetoothAdapter.isMultipleAdvertisementSupported()`) — kalau tidak didukung,
 * state dilaporkan sebagai Unsupported, BUKAN pura-pura berhasil (§79).
 */
class BlePeripheralAdvertiser(private val context: Context) {
    private val _state = MutableStateFlow<BleAdvertiseState>(BleAdvertiseState.Idle)
    val state: StateFlow<BleAdvertiseState> = _state

    private var advertiser: BluetoothLeAdvertiser? = null
    private var callback: AdvertiseCallback? = null
    private var currentEphemeralId: ByteArray? = null

    fun startAdvertising() {
        val adapter = bluetoothAdapter()
        if (adapter == null || !adapter.isEnabled) {
            _state.update { BleAdvertiseState.BleDisabled }
            return
        }
        if (!adapter.isMultipleAdvertisementSupported) {
            _state.update { BleAdvertiseState.Unsupported }
            return
        }
        if (!hasAdvertisePermission()) {
            _state.update { BleAdvertiseState.PermissionRequired }
            return
        }
        val leAdvertiser = adapter.bluetoothLeAdvertiser
        if (leAdvertiser == null) {
            _state.update { BleAdvertiseState.Error("Adapter tidak menyediakan BLE advertiser.") }
            return
        }

        rotateEphemeralId()
        advertise(leAdvertiser)
    }

    /** Dipanggil periodik (misal dari WorkManager/timer) untuk rotasi identitas privasi (§16/§73). */
    fun rotateIfDue() {
        if (_state.value != BleAdvertiseState.Advertising) return
        val leAdvertiser = advertiser ?: return
        stopInternal(leAdvertiser)
        rotateEphemeralId()
        advertise(leAdvertiser)
    }

    fun stopAdvertising() {
        val leAdvertiser = advertiser ?: return
        stopInternal(leAdvertiser)
        advertiser = null
        _state.update { BleAdvertiseState.Idle }
    }

    private fun advertise(leAdvertiser: BluetoothLeAdvertiser) {
        val settings = AdvertiseSettings.Builder()
            .setAdvertiseMode(AdvertiseSettings.ADVERTISE_MODE_BALANCED) // §73: hemat baterai.
            .setTxPowerLevel(AdvertiseSettings.ADVERTISE_TX_POWER_MEDIUM)
            .setConnectable(false)
            .build()

        val data = AdvertiseData.Builder()
            .addServiceUuid(ParcelUuid(BleProximityProtocol.SERVICE_UUID))
            .addManufacturerData(BleProximityProtocol.MANUFACTURER_ID, currentEphemeralId!!)
            .setIncludeDeviceName(false) // §16: jangan percaya/expose nama Bluetooth sebagai identitas.
            .build()

        val cb = object : AdvertiseCallback() {
            override fun onStartSuccess(settingsInEffect: AdvertiseSettings?) {
                _state.update { BleAdvertiseState.Advertising }
            }

            override fun onStartFailure(errorCode: Int) {
                _state.update { BleAdvertiseState.Error("BLE advertise gagal (errorCode=$errorCode).") }
            }
        }

        runCatching {
            leAdvertiser.startAdvertising(settings, data, cb)
            advertiser = leAdvertiser
            callback = cb
        }.onFailure { error ->
            _state.update { BleAdvertiseState.Error(error.javaClass.simpleName) }
        }
    }

    private fun stopInternal(leAdvertiser: BluetoothLeAdvertiser) {
        val cb = callback ?: return
        if (hasAdvertisePermission()) {
            runCatching { leAdvertiser.stopAdvertising(cb) }
        }
        callback = null
    }

    private fun rotateEphemeralId() {
        val bytes = ByteArray(BleProximityProtocol.EPHEMERAL_ID_LENGTH_BYTES)
        SecureRandom().nextBytes(bytes)
        currentEphemeralId = bytes
    }

    private fun bluetoothAdapter(): BluetoothAdapter? =
        (context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter

    private fun hasAdvertisePermission(): Boolean {
        val permission = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Manifest.permission.BLUETOOTH_ADVERTISE
        } else {
            Manifest.permission.ACCESS_FINE_LOCATION
        }
        return ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
    }
}
