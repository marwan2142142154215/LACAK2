package com.smb.master.data.security

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

/** §30/§39-setara: token Sanctum (bearer) + identitas user yang sedang login di SMB Master. */
data class StoredSession(
    val token: String,
    val userId: String,
    val name: String,
    val email: String,
    val roles: List<String>,
    val permissions: List<String>,
)

/**
 * Pola identik dengan DeviceCredentialStore di smb-tracker-android: AES-GCM dengan key
 * Android Keystore (hardware-backed bila tersedia) — token Sanctum TIDAK PERNAH disimpan
 * plaintext di SharedPreferences biasa (§39/§44).
 */
class SessionTokenStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    @Synchronized
    @SuppressLint("UseKtx")
    fun save(session: StoredSession) {
        val plain = JSONObject()
            .put("token", session.token)
            .put("user_id", session.userId)
            .put("name", session.name)
            .put("email", session.email)
            .put("roles", session.roles.joinToString(","))
            .put("permissions", session.permissions.joinToString(","))
            .toString()
            .toByteArray(Charsets.UTF_8)
        val key = getOrCreateKey()
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key)
        val encrypted = cipher.doFinal(plain)
        val packed = cipher.iv + encrypted
        val encoded = Base64.encodeToString(packed, Base64.NO_WRAP)
        check(preferences.edit().putString(ENCRYPTED_SESSION, encoded).commit()) {
            "Sesi login tidak dapat disimpan dengan aman."
        }
    }

    @Synchronized
    fun load(): StoredSession? {
        val encoded = preferences.getString(ENCRYPTED_SESSION, null) ?: return null
        val packed = Base64.decode(encoded, Base64.NO_WRAP)
        if (packed.size <= IV_LENGTH) return null
        val iv = packed.copyOfRange(0, IV_LENGTH)
        val encrypted = packed.copyOfRange(IV_LENGTH, packed.size)
        return runCatching {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(TAG_LENGTH_BITS, iv))
            val json = JSONObject(String(cipher.doFinal(encrypted), Charsets.UTF_8))
            StoredSession(
                token = json.getString("token"),
                userId = json.getString("user_id"),
                name = json.getString("name"),
                email = json.getString("email"),
                roles = json.getString("roles").split(",").filter { it.isNotBlank() },
                permissions = json.getString("permissions").split(",").filter { it.isNotBlank() },
            )
        }.getOrNull()
    }

    @Synchronized
    fun clear() {
        preferences.edit { remove(ENCRYPTED_SESSION) }
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
        const val PREFERENCES = "smb_master_secure_state"
        const val ENCRYPTED_SESSION = "encrypted_session"
        const val ANDROID_KEY_STORE = "AndroidKeyStore"
        const val KEY_ALIAS = "com.smb.master.session-token.aes-gcm"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val IV_LENGTH = 12
        const val TAG_LENGTH_BITS = 128
    }
}
