package com.smb.master.presentation

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.smb.master.ble.BleCentralScanner
import com.smb.master.ble.BleScanState
import com.smb.master.ble.NearbyDevice
import com.smb.master.data.network.DeviceOverview
import com.smb.master.data.network.DeviceSummary
import com.smb.master.data.network.MasterApiClient
import com.smb.master.data.network.MasterApiException
import com.smb.master.data.security.SessionTokenStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class DeviceListScreenState(
    val isLoading: Boolean = true,
    val overview: DeviceOverview? = null,
    val devices: List<DeviceSummary> = emptyList(),
    val userName: String = "",
    val errorMessage: String? = null,
    val actionFeedback: String? = null,
)

/**
 * §27 SMB Master — device list + command center + BLE radar (§14). Reuse endpoint admin
 * yang sama dengan SMB Web (§9/§30): satu source of truth server-side untuk authorization
 * (§31 RBAC ditegakkan server, bukan di client).
 */
class MainViewModel(application: Application) : AndroidViewModel(application) {
    private val sessionStore = SessionTokenStore(application)
    private val apiClient = MasterApiClient()
    val bleScanner = BleCentralScanner(application)

    private val _state = MutableStateFlow(DeviceListScreenState())
    val state: StateFlow<DeviceListScreenState> = _state

    private val _searchQuery = MutableStateFlow("")

    val nearbyDevices: StateFlow<List<NearbyDevice>> = bleScanner.nearbyDevices
        .map { devices -> devices.values.sortedByDescending { it.smoothedRssiDbm } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val bleState: StateFlow<BleScanState> = bleScanner.state

    init {
        val session = sessionStore.load()
        if (session != null) {
            apiClient.setToken(session.token)
            _state.update { it.copy(userName = session.name) }
        }
        refresh()
        // §17: prune perangkat yang tidak lagi terdeteksi lewat timeout — dijalankan periodik
        // selama ViewModel hidup, bukan polling agresif (§72, cukup setiap 5 detik).
        viewModelScope.launch {
            while (isActive) {
                delay(5_000)
                bleScanner.pruneStaleDevices(System.currentTimeMillis())
            }
        }
    }

    fun refresh() {
        _state.update { it.copy(isLoading = true, errorMessage = null) }
        viewModelScope.launch {
            val result = runCatching {
                withContext(Dispatchers.IO) {
                    val overview = apiClient.deviceOverview()
                    val devices = apiClient.listDevices(search = _searchQuery.value.ifBlank { null })
                    overview to devices
                }
            }
            result.onSuccess { (overview, devices) ->
                _state.update { it.copy(isLoading = false, overview = overview, devices = devices) }
            }.onFailure { error ->
                _state.update { it.copy(isLoading = false, errorMessage = errorMessageFor(error)) }
            }
        }
    }

    fun search(query: String) {
        _searchQuery.update { query }
        refresh()
    }

    fun lock(device: DeviceSummary) = dispatchCommand(device, "Perintah kunci dikirim ke ${device.name}.") {
        apiClient.lockDevice(device.id)
    }

    fun unlock(device: DeviceSummary) = dispatchCommand(device, "Perintah buka kunci dikirim ke ${device.name}.") {
        apiClient.unlockDevice(device.id)
    }

    fun requestLocation(device: DeviceSummary) = dispatchCommand(device, "Permintaan lokasi dikirim ke ${device.name}.") {
        apiClient.requestLocation(device.id)
    }

    fun startBleScan() = bleScanner.startScanning()
    fun stopBleScan() = bleScanner.stopScanning()

    fun logout() {
        viewModelScope.launch {
            withContext(Dispatchers.IO) { apiClient.logout() }
            sessionStore.clear()
        }
    }

    private fun dispatchCommand(device: DeviceSummary, successMessage: String, call: suspend () -> Unit) {
        viewModelScope.launch {
            val result = runCatching { withContext(Dispatchers.IO) { call() } }
            result.onSuccess {
                _state.update { it.copy(actionFeedback = successMessage) }
                refresh()
            }.onFailure { error ->
                _state.update { it.copy(actionFeedback = errorMessageFor(error)) }
            }
        }
    }

    fun consumeActionFeedback() {
        _state.update { it.copy(actionFeedback = null) }
    }

    private fun errorMessageFor(error: Throwable): String =
        (error as? MasterApiException)?.message ?: "Tidak dapat terhubung ke server SMB."
}
