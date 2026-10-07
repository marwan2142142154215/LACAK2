package com.smb.master.presentation

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.google.android.material.button.MaterialButton
import com.google.android.material.snackbar.Snackbar
import com.smb.master.R
import com.smb.master.ble.BleScanState
import com.smb.master.data.network.DeviceSummary
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {
    private val viewModel: MainViewModel by viewModels()
    private val deviceAdapter = DeviceListAdapter(
        onLock = ::confirmLock,
        onUnlock = ::confirmUnlock,
        onRequestLocation = { device -> viewModel.requestLocation(device) },
    )
    private val nearbyAdapter = NearbyDeviceAdapter()

    private lateinit var userNameText: TextView
    private lateinit var overviewRow: android.widget.LinearLayout
    private lateinit var tabDevicesButton: MaterialButton
    private lateinit var tabBleRadarButton: MaterialButton
    private lateinit var devicesPanel: View
    private lateinit var bleRadarPanel: View
    private lateinit var devicesEmptyState: View
    private lateinit var deviceSwipeRefresh: SwipeRefreshLayout
    private lateinit var bleStateTitle: TextView
    private lateinit var bleStateSubtitle: TextView
    private lateinit var bleGrantPermissionButton: MaterialButton

    private val blePermissionRequest = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { results ->
        if (results.values.all { it }) viewModel.startBleScan()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        bindViews()
        setupLists()

        tabDevicesButton.setOnClickListener { showTab(devices = true) }
        tabBleRadarButton.setOnClickListener {
            showTab(devices = false)
            requestBlePermissionsOrScan()
        }
        bleGrantPermissionButton.setOnClickListener { requestBlePermissionsOrScan() }
        deviceSwipeRefresh.setOnRefreshListener { viewModel.refresh() }
        findViewById<TextView>(R.id.searchField).setOnEditorActionListener { textView, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                viewModel.search(textView.text.toString())
                true
            } else {
                false
            }
        }
        findViewById<View>(R.id.logoutButton).setOnClickListener { confirmLogout() }

        showTab(devices = true)

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.state.collect(::renderDevices)
            }
        }
        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.nearbyDevices.collect(nearbyAdapter::submitList)
            }
        }
        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.bleState.collect(::renderBleState)
            }
        }
    }

    override fun onStop() {
        viewModel.stopBleScan()
        super.onStop()
    }

    private fun bindViews() {
        userNameText = findViewById(R.id.currentUserName)
        overviewRow = findViewById(R.id.overviewRow)
        tabDevicesButton = findViewById(R.id.tabDevicesButton)
        tabBleRadarButton = findViewById(R.id.tabBleRadarButton)
        devicesPanel = findViewById(R.id.devicesPanel)
        bleRadarPanel = findViewById(R.id.bleRadarPanel)
        devicesEmptyState = findViewById(R.id.devicesEmptyState)
        deviceSwipeRefresh = findViewById(R.id.deviceSwipeRefresh)
        bleStateTitle = findViewById(R.id.bleStateTitle)
        bleStateSubtitle = findViewById(R.id.bleStateSubtitle)
        bleGrantPermissionButton = findViewById(R.id.bleGrantPermissionButton)
    }

    private fun setupLists() {
        findViewById<RecyclerView>(R.id.deviceList).apply {
            layoutManager = LinearLayoutManager(this@MainActivity)
            adapter = deviceAdapter
        }
        findViewById<RecyclerView>(R.id.nearbyList).apply {
            layoutManager = LinearLayoutManager(this@MainActivity)
            adapter = nearbyAdapter
        }
    }

    private fun showTab(devices: Boolean) {
        devicesPanel.visibility = if (devices) View.VISIBLE else View.GONE
        bleRadarPanel.visibility = if (devices) View.GONE else View.VISIBLE
        tabDevicesButton.setBackgroundColor(if (devices) getColor(R.color.smb_blue) else getColor(R.color.smb_surface_card))
        if (!devices) viewModel.startBleScan() else viewModel.stopBleScan()
    }

    private fun renderDevices(state: DeviceListScreenState) {
        userNameText.text = state.userName.ifBlank { getString(R.string.app_name) }
        deviceSwipeRefresh.isRefreshing = state.isLoading
        deviceAdapter.submitList(state.devices)
        devicesEmptyState.visibility = if (!state.isLoading && state.devices.isEmpty()) View.VISIBLE else View.GONE
        renderOverview(state)

        state.errorMessage?.let {
            Snackbar.make(deviceSwipeRefresh, it, Snackbar.LENGTH_LONG).show()
        }
        state.actionFeedback?.let {
            Snackbar.make(deviceSwipeRefresh, it, Snackbar.LENGTH_SHORT).show()
            viewModel.consumeActionFeedback()
        }
    }

    private fun renderOverview(state: DeviceListScreenState) {
        overviewRow.removeAllViews()
        val overview = state.overview ?: return
        val chips = listOf(
            getString(R.string.overview_total) to overview.total,
            getString(R.string.overview_online) to overview.online,
            getString(R.string.overview_degraded) to overview.degraded,
            getString(R.string.overview_offline) to overview.offline,
            getString(R.string.overview_locked) to overview.locked,
        )
        chips.forEach { (label, value) ->
            val chip = layoutInflater.inflate(android.R.layout.simple_list_item_1, overviewRow, false) as TextView
            chip.text = "$value\n$label"
            chip.setTextColor(getColor(android.R.color.white))
            chip.textSize = 11f
            chip.gravity = android.view.Gravity.CENTER
            chip.setPadding(24, 10, 24, 10)
            chip.setBackgroundResource(R.drawable.bg_chip_dark)
            val params = android.widget.LinearLayout.LayoutParams(android.widget.LinearLayout.LayoutParams.WRAP_CONTENT, android.widget.LinearLayout.LayoutParams.WRAP_CONTENT)
            params.marginEnd = 10
            chip.layoutParams = params
            overviewRow.addView(chip)
        }
    }

    private fun renderBleState(state: BleScanState) {
        bleGrantPermissionButton.visibility = View.GONE
        when (state) {
            is BleScanState.Idle -> {
                bleStateTitle.text = "BLE tidak aktif"
                bleStateSubtitle.text = "Buka tab Radar BLE untuk mulai memindai perangkat SMB Lacak terdekat."
            }
            is BleScanState.BleDisabled -> {
                bleStateTitle.text = getString(R.string.ble_disabled_title)
                bleStateSubtitle.text = getString(R.string.ble_disabled_subtitle)
            }
            is BleScanState.PermissionRequired -> {
                bleStateTitle.text = getString(R.string.ble_permission_title)
                bleStateSubtitle.text = getString(R.string.ble_permission_subtitle)
                bleGrantPermissionButton.visibility = View.VISIBLE
            }
            is BleScanState.Scanning -> {
                bleStateTitle.text = getString(R.string.ble_scanning_title)
                bleStateSubtitle.text = getString(R.string.ble_distance_label) + " — hasil bukan jarak absolut."
            }
            is BleScanState.Error -> {
                bleStateTitle.text = "BLE bermasalah"
                bleStateSubtitle.text = state.reason
            }
        }
    }

    private fun requestBlePermissionsOrScan() {
        val permissions = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            arrayOf(Manifest.permission.BLUETOOTH_SCAN, Manifest.permission.BLUETOOTH_CONNECT)
        } else {
            arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)
        }
        val granted = permissions.all { ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED }
        if (granted) viewModel.startBleScan() else blePermissionRequest.launch(permissions)
    }

    private fun confirmLock(device: DeviceSummary) {
        AlertDialog.Builder(this)
            .setTitle("Kunci perangkat?")
            .setMessage("Perangkat \"${device.name}\" akan dikunci dari jarak jauh. Lanjutkan?")
            .setPositiveButton("Kunci") { _, _ -> viewModel.lock(device) }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun confirmUnlock(device: DeviceSummary) {
        AlertDialog.Builder(this)
            .setTitle("Buka kunci perangkat?")
            .setMessage("Perangkat \"${device.name}\" akan dibuka kuncinya. Lanjutkan?")
            .setPositiveButton("Buka kunci") { _, _ -> viewModel.unlock(device) }
            .setNegativeButton("Batal", null)
            .show()
    }

    private fun confirmLogout() {
        AlertDialog.Builder(this)
            .setTitle("Keluar dari SMB Master?")
            .setPositiveButton("Keluar") { _, _ ->
                viewModel.logout()
                startActivity(Intent(this, LoginActivity::class.java))
                finish()
            }
            .setNegativeButton("Batal", null)
            .show()
    }
}
