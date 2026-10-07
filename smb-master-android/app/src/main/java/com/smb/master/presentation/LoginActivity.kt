package com.smb.master.presentation

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.smb.master.R
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {
    private val viewModel: LoginViewModel by viewModels()

    private lateinit var credentialStep: View
    private lateinit var twoFactorStep: View
    private lateinit var emailField: TextInputEditText
    private lateinit var passwordField: TextInputEditText
    private lateinit var twoFactorCodeField: TextInputEditText
    private lateinit var recoveryCodeField: TextInputEditText
    private lateinit var loginButton: MaterialButton
    private lateinit var verifyButton: MaterialButton
    private lateinit var backButton: MaterialButton
    private lateinit var progress: View
    private lateinit var errorText: android.widget.TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)
        bindViews()

        loginButton.setOnClickListener {
            viewModel.login(emailField.text?.toString().orEmpty(), passwordField.text?.toString().orEmpty())
        }
        verifyButton.setOnClickListener {
            viewModel.verifyTwoFactor(
                twoFactorCodeField.text?.toString().orEmpty(),
                recoveryCodeField.text?.toString().orEmpty(),
            )
        }
        backButton.setOnClickListener { viewModel.backToCredentials() }

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.state.collect(::render)
            }
        }
    }

    private fun bindViews() {
        credentialStep = findViewById(R.id.credentialStep)
        twoFactorStep = findViewById(R.id.twoFactorStep)
        emailField = findViewById(R.id.emailField)
        passwordField = findViewById(R.id.passwordField)
        twoFactorCodeField = findViewById(R.id.twoFactorCodeField)
        recoveryCodeField = findViewById(R.id.recoveryCodeField)
        loginButton = findViewById(R.id.loginButton)
        verifyButton = findViewById(R.id.verifyButton)
        backButton = findViewById(R.id.backButton)
        progress = findViewById(R.id.loginProgress)
        errorText = findViewById(R.id.loginError)
    }

    private fun render(state: LoginScreenState) {
        if (state.loggedIn) {
            startActivity(Intent(this, MainActivity::class.java))
            finish()
            return
        }
        credentialStep.visibility = if (state.awaitingTwoFactor) View.GONE else View.VISIBLE
        twoFactorStep.visibility = if (state.awaitingTwoFactor) View.VISIBLE else View.GONE
        loginButton.isEnabled = !state.isBusy
        verifyButton.isEnabled = !state.isBusy
        progress.visibility = if (state.isBusy) View.VISIBLE else View.GONE
        errorText.visibility = if (state.errorMessage.isNullOrBlank()) View.GONE else View.VISIBLE
        errorText.text = state.errorMessage.orEmpty()
    }
}
