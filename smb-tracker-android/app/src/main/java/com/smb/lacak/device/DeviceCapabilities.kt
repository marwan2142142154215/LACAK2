package com.smb.lacak.device

import android.Manifest
import android.app.NotificationManager
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.pm.PackageManager
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.location.LocationManager
import android.os.Build
import android.os.BatteryManager
import android.content.Context.BATTERY_SERVICE
import android.content.Context.CONNECTIVITY_SERVICE
import android.content.Context.LOCATION_SERVICE
import android.content.Context.NOTIFICATION_SERVICE
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import androidx.core.content.ContextCompat
import org.json.JSONObject

data class DeviceCapabilities(
    val cameraAvailable: Boolean,
    val frontCameraAvailable: Boolean,
    val backCameraAvailable: Boolean,
    val locationAvailable: Boolean,
    val managedDevice: Boolean,
    val deviceOwner: Boolean,
    val foregroundServiceAvailable: Boolean,
    val notificationPermission: Boolean,
    // §26/§38 — dilaporkan jujur berdasarkan chipset/Android nyata, bukan diasumsikan true.
    val bluetoothAvailable: Boolean = false,
    val bleSupported: Boolean = false,
    val bleAdvertisingSupported: Boolean = false,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("camera_available", cameraAvailable)
        .put("front_camera_available", frontCameraAvailable)
        .put("back_camera_available", backCameraAvailable)
        .put("location_available", locationAvailable)
        .put("managed_device", managedDevice)
        .put("device_owner", deviceOwner)
        .put("foreground_service_available", foregroundServiceAvailable)
        .put("notification_permission", notificationPermission)
        .put("bluetooth_available", bluetoothAvailable)
        .put("ble_supported", bleSupported)
        .put("ble_advertising_supported", bleAdvertisingSupported)
}

object DeviceCapabilitiesReporter {
    fun collect(context: Context): DeviceCapabilities {
        val packageName = context.packageName
        val policy = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val deviceOwner = policy.isDeviceOwnerApp(packageName)
        val profileOwner = policy.isProfileOwnerApp(packageName)
        val notificationPermission = notificationPermission(context)
        val cameraDirections = availableCameraDirections(context)
        val bluetoothAdapter = (context.getSystemService(Context.BLUETOOTH_SERVICE) as? android.bluetooth.BluetoothManager)?.adapter

        return DeviceCapabilities(
            cameraAvailable = cameraDirections.isNotEmpty(),
            frontCameraAvailable = CameraCharacteristics.LENS_FACING_FRONT in cameraDirections,
            backCameraAvailable = CameraCharacteristics.LENS_FACING_BACK in cameraDirections,
            locationAvailable = hasLocationPermission(context) && isLocationEnabled(context),
            managedDevice = deviceOwner || profileOwner,
            deviceOwner = deviceOwner,
            foregroundServiceAvailable = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && notificationPermission,
            notificationPermission = notificationPermission,
            // §14/§38: isMultipleAdvertisementSupported() adalah API resmi Android untuk
            // mengetahui chipset mendukung BLE peripheral/advertising — dilaporkan APA ADANYA,
            // bukan diasumsikan (§79, banyak device lama/murah tidak mendukung ini).
            bluetoothAvailable = bluetoothAdapter != null,
            bleSupported = context.packageManager.hasSystemFeature(android.content.pm.PackageManager.FEATURE_BLUETOOTH_LE),
            bleAdvertisingSupported = bluetoothAdapter?.isMultipleAdvertisementSupported == true,
        )
    }

    fun batteryPercent(context: Context): Int? {
        val manager = context.getSystemService(BATTERY_SERVICE) as BatteryManager
        val capacity = manager.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        return capacity.takeIf { it in 0..100 }
    }

    fun networkType(context: Context): String {
        val manager = context.getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = manager.activeNetwork ?: return "NONE"
        val capabilities = manager.getNetworkCapabilities(network) ?: return "UNKNOWN"
        return when {
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "WIFI"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "CELLULAR"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ETHERNET"
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN) -> "VPN"
            else -> "UNKNOWN"
        }
    }

    private fun availableCameraDirections(context: Context): Set<Int> {
        val manager = context.getSystemService(Context.CAMERA_SERVICE) as CameraManager
        return runCatching {
            manager.cameraIdList.mapNotNull { cameraId ->
                manager.getCameraCharacteristics(cameraId).get(CameraCharacteristics.LENS_FACING)
            }.toSet()
        }.getOrDefault(emptySet())
    }

    private fun hasLocationPermission(context: Context): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun isLocationEnabled(context: Context): Boolean = runCatching {
        val manager = context.getSystemService(LOCATION_SERVICE) as LocationManager
        manager.isProviderEnabled(LocationManager.GPS_PROVIDER) || manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }.getOrDefault(false)

    private fun notificationPermission(context: Context): Boolean {
        val runtimeGranted = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        val notificationsEnabled = (context.getSystemService(NOTIFICATION_SERVICE) as NotificationManager).areNotificationsEnabled()
        return runtimeGranted && notificationsEnabled
    }
}
