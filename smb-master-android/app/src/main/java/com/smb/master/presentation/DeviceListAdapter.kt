package com.smb.master.presentation

import android.graphics.Color
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.smb.master.R
import com.smb.master.data.network.DeviceSummary

class DeviceListAdapter(
    private val onLock: (DeviceSummary) -> Unit,
    private val onUnlock: (DeviceSummary) -> Unit,
    private val onRequestLocation: (DeviceSummary) -> Unit,
) : ListAdapter<DeviceSummary, DeviceListAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.item_device, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    inner class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val name: TextView = itemView.findViewById(R.id.deviceName)
        private val meta: TextView = itemView.findViewById(R.id.deviceMeta)
        private val lastSeen: TextView = itemView.findViewById(R.id.deviceLastSeen)
        private val statusDot: View = itemView.findViewById(R.id.statusDot)
        private val statusLabel: TextView = itemView.findViewById(R.id.statusLabel)
        private val lockButton: MaterialButton = itemView.findViewById(R.id.lockButton)
        private val unlockButton: MaterialButton = itemView.findViewById(R.id.unlockButton)
        private val locationButton: MaterialButton = itemView.findViewById(R.id.locationButton)

        fun bind(device: DeviceSummary) {
            name.text = device.name
            meta.text = listOfNotNull(device.siteName, device.teamName, device.androidVersion?.let { "Android $it" })
                .joinToString(separator = " · ")
                .ifBlank { "Belum ada metadata." }
            lastSeen.text = device.lastHeartbeatAt?.let { "Heartbeat terakhir: $it" } ?: "Belum pernah terhubung."

            statusLabel.text = statusLabelFor(device.status)
            statusDot.backgroundTintList = android.content.res.ColorStateList.valueOf(statusColorFor(device.status))

            val isLocked = device.status == "LOCKED"
            lockButton.isEnabled = !isLocked
            unlockButton.isEnabled = isLocked

            lockButton.setOnClickListener { onLock(device) }
            unlockButton.setOnClickListener { onUnlock(device) }
            locationButton.setOnClickListener { onRequestLocation(device) }
        }

        private fun statusLabelFor(status: String): String = when (status) {
            "ONLINE" -> "Online"
            "DEGRADED" -> "Terbatas"
            "OFFLINE" -> "Offline"
            "LOCKED" -> "Terkunci"
            else -> "Tidak diketahui"
        }

        private fun statusColorFor(status: String): Int = when (status) {
            "ONLINE" -> Color.parseColor("#138A5B")
            "DEGRADED" -> Color.parseColor("#B45309")
            "OFFLINE" -> Color.parseColor("#68778C")
            "LOCKED" -> Color.parseColor("#B42318")
            else -> Color.parseColor("#68778C")
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<DeviceSummary>() {
            override fun areItemsTheSame(oldItem: DeviceSummary, newItem: DeviceSummary) = oldItem.id == newItem.id
            override fun areContentsTheSame(oldItem: DeviceSummary, newItem: DeviceSummary) = oldItem == newItem
        }
    }
}
