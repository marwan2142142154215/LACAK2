package com.smb.master.presentation

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.smb.master.R
import com.smb.master.ble.NearbyDevice
import com.smb.master.ble.ProximityLevel
import java.util.Locale

/** §14/§15 — radar BLE: label jarak SELALU berupa "Perkiraan jarak" (§15), tidak pernah diklaim presisi. */
class NearbyDeviceAdapter : ListAdapter<NearbyDevice, NearbyDeviceAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.item_nearby_device, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val ephemeralId: TextView = itemView.findViewById(R.id.nearbyEphemeralId)
        private val distance: TextView = itemView.findViewById(R.id.nearbyDistance)
        private val proximityLabel: TextView = itemView.findViewById(R.id.proximityLabel)

        fun bind(device: NearbyDevice) {
            ephemeralId.text = "SMB-${device.ephemeralId.take(12)}"
            val meters = device.estimatedDistanceMeters
            distance.text = if (meters != null) {
                String.format(Locale.getDefault(), "Perkiraan jarak: ~%.1f m · RSSI %d dBm", meters, device.lastRssiDbm)
            } else {
                "RSSI ${device.lastRssiDbm} dBm"
            }
            proximityLabel.text = proximityLabelFor(device.proximity)
        }

        private fun proximityLabelFor(level: ProximityLevel): String = when (level) {
            ProximityLevel.VERY_NEAR -> "Sangat dekat"
            ProximityLevel.NEAR -> "Dekat"
            ProximityLevel.MEDIUM -> "Sedang"
            ProximityLevel.FAR -> "Jauh"
            ProximityLevel.NOT_DETECTED -> "Tidak terdeteksi"
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<NearbyDevice>() {
            override fun areItemsTheSame(oldItem: NearbyDevice, newItem: NearbyDevice) = oldItem.ephemeralId == newItem.ephemeralId
            override fun areContentsTheSame(oldItem: NearbyDevice, newItem: NearbyDevice) = oldItem == newItem
        }
    }
}
