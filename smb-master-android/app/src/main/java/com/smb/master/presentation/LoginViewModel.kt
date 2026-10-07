package com.smb.master.presentation

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.smb.master.data.network.LoginOutcome
import com.smb.master.data.network.MasterApiClient
import com.smb.master.data.network.MasterApiException
import com.smb.master.data.security.SessionTokenStore
import com.smb.master.data.security.StoredSession
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class LoginScreenState(
    val isBusy: Boolean = false,
    val awaitingTwoFactor: Boolean = false,
    val loginToken: String? = null,
    val errorMessage: String? = null,
    val loggedIn: Boolean = false,
)

/**
 * §30: login SMB Master pakai endpoint admin yang SAMA dengan SMB Web (bukan jalur
 * device-credential) — dua langkah kalau 2FA aktif (step-up, sama dengan dashboard).
 */
class LoginViewModel(application: Application) : AndroidViewModel(application) {
    private val sessionStore = SessionTokenStore(application)
    private val apiClient = MasterApiClient()

    private val _state = MutableStateFlow(LoginScreenState())
    val state: StateFlow<LoginScreenState> = _state

    init {
        sessionStore.load()?.let { session ->
            apiClient.setToken(session.token)
            _state.update { it.copy(loggedIn = true) }
        }
    }

    fun login(email: String, password: String) {
        if (email.isBlank() || password.isBlank()) {
            _state.update { it.copy(errorMessage = "Email dan kata sandi wajib diisi.") }
            return
        }
        _state.update { it.copy(isBusy = true, errorMessage = null) }
        viewModelScope.launch {
            val deviceName = "SMB Master (${Build.MANUFACTURER} ${Build.MODEL})"
            val outcome = runCatching {
                withContext(Dispatchers.IO) { apiClient.login(email, password, deviceName) }
            }
            outcome.onSuccess { result ->
                when (result) {
                    is LoginOutcome.Authenticated -> persistAndComplete(result.token, result.userId, result.name, result.email, result.roles, result.permissions)
                    is LoginOutcome.TwoFactorRequired -> _state.update {
                        it.copy(isBusy = false, awaitingTwoFactor = true, loginToken = result.loginToken)
                    }
                }
            }.onFailure { error ->
                _state.update { it.copy(isBusy = false, errorMessage = errorMessageFor(error)) }
            }
        }
    }

    fun verifyTwoFactor(code: String, recoveryCode: String) {
        val loginToken = _state.value.loginToken ?: return
        if (code.isBlank() && recoveryCode.isBlank()) {
            _state.update { it.copy(errorMessage = "Masukkan kode verifikasi atau kode pemulihan.") }
            return
        }
        _state.update { it.copy(isBusy = true, errorMessage = null) }
        viewModelScope.launch {
            val outcome = runCatching {
                withContext(Dispatchers.IO) {
                    apiClient.verifyTwoFactor(loginToken, code.takeIf { it.isNotBlank() }, recoveryCode.takeIf { it.isNotBlank() })
                }
            }
            outcome.onSuccess { result ->
                persistAndComplete(result.token, result.userId, result.name, result.email, result.roles, result.permissions)
            }.onFailure { error ->
                _state.update { it.copy(isBusy = false, errorMessage = errorMessageFor(error)) }
            }
        }
    }

    fun backToCredentials() {
        _state.update { it.copy(awaitingTwoFactor = false, loginToken = null, errorMessage = null) }
    }

    private fun persistAndComplete(token: String, userId: String, name: String, email: String, roles: List<String>, permissions: List<String>) {
        sessionStore.save(StoredSession(token, userId, name, email, roles, permissions))
        _state.update { it.copy(isBusy = false, loggedIn = true) }
    }

    private fun errorMessageFor(error: Throwable): String =
        (error as? MasterApiException)?.message ?: "Tidak dapat terhubung ke server SMB."
}
