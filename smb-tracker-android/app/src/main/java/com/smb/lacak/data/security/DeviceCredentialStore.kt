package com.smb.lacak.data.security

import android.annotation.SuppressLint
import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.core.content.edit
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * device_secret TIDAK PERNAH dikirim ke server lagi setelah registrasi — hanya disimpan
 * di sini untuk dipakai sebagai bagian dari handshake WebSocket (§43). Kalau hilang
 * (uninstall/reset), device harus registrasi ulang dengan kode baru — tidak ada cara
 * "recover" device_secret lama (§18/§39, server hanya simpan credential_hash).
 */
data class StoredDeviceCredential(
    val deviceId: String,
    val publicTokenId: String,
    val deviceSecret: String,
)

/**
 * §39: credential device WAJIB encrypted-at-rest. Dienkripsi AES-GCM dengan key yang
 * disimpan di Android Keystore (hardware-backed di device yang mendukung) — bukan
 * SharedPreferences plaintext biasa.
 */
class DeviceCredentialStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    @Synchronized
    @SuppressLint("UseKtx")
    fun save(value: StoredDeviceCredential) {
        val plain = JSONObject()
            .put("device_id", value.deviceId)
            .put("public_token_id", value.publicTokenId)
            .put("device_secret", value.deviceSecret)
            .toString()
            .toByteArray(Charsets.UTF_8)
        val key = getOrCreateKey()
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key)
        val encrypted = cipher.doFinal(plain)
        val packed = cipher.iv + encrypted
        val encoded = Base64.encodeToString(packed, Base64.NO_WRAP)
        check(preferences.edit().putString(ENCRYPTED_DEVICE, encoded).commit()) {
            "Kredensial perangkat tidak dapat disimpan dengan aman."
        }
    }

    @Synchronized
    fun load(): StoredDeviceCredential? {
        val encoded = preferences.getString(ENCRYPTED_DEVICE, null) ?: return null
        val packed = Base64.decode(encoded, Base64.NO_WRAP)
        require(packed.size > IV_LENGTH) { "Data kredensial perangkat rusak." }
        val iv = packed.copyOfRange(0, IV_LENGTH)
        val encrypted = packed.copyOfRange(IV_LENGTH, packed.size)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(TAG_LENGTH_BITS, iv))
        val json = JSONObject(String(cipher.doFinal(encrypted), Charsets.UTF_8))
        return StoredDeviceCredential(
            deviceId = json.getString("device_id"),
            publicTokenId = json.getString("public_token_id"),
            deviceSecret = json.getString("device_secret"),
        )
    }

    @Synchronized
    fun clear() {
        preferences.edit { remove(ENCRYPTED_DEVICE) }
        val keyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }
        runCatching { keyStore.deleteEntry(KEY_ALIAS) }
    }

    private fun getOrCreateKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEY_STORE).apply { load(null) }
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEY_STORE)
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build(),
        )
        return generator.generateKey()
    }

    private companion object {
        const val PREFERENCES = "smb_lacak_secure_state"
        const val ENCRYPTED_DEVICE = "encrypted_device_credential"
        const val ANDROID_KEY_STORE = "AndroidKeyStore"
        const val KEY_ALIAS = "com.smb.lacak.device-credential.aes-gcm"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val IV_LENGTH = 12
        const val TAG_LENGTH_BITS = 128
    }
}
