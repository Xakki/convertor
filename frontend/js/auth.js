/**
 * auth.js — Telegram Login Widget callback and auth utilities
 */

/**
 * Called by Telegram Login Widget after successful auth
 * @param {Object} user - Telegram user data
 */
async function onTelegramAuth(user) {
  try {
    const response = await fetch('/api/v1/auth/telegram', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(user),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || 'Ошибка авторизации');
    }

    const data = await response.json();
    // data = { token: '...', user: { id, name, username, role, quota } }
    Alpine.store('auth').login(data.token, data.user);

    // Close modal if open
    const modalEl = document.getElementById('login-modal');
    if (modalEl) {
      const modalData = Alpine.$data(modalEl);
      if (modalData?.close) modalData.close();
    }

    // Dispatch event for components to react
    document.dispatchEvent(new CustomEvent('auth:login', { detail: data.user }));

    // Reload HTMX components that depend on auth
    htmx.trigger('#quota-bar', 'load');

  } catch (err) {
    console.error('Telegram auth error:', err);
    const errorEl = document.getElementById('auth-error');
    if (errorEl) {
      errorEl.textContent = err.message;
      errorEl.classList.remove('hidden');
    }
  }
}

/**
 * SMS auth: request OTP
 * @param {string} phone
 * @returns {Promise<boolean>}
 */
async function requestSmsOtp(phone) {
  try {
    const response = await fetch('/api/v1/auth/sms/request', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone }),
    });
    return response.ok;
  } catch (err) {
    console.error('SMS request error:', err);
    return false;
  }
}

/**
 * SMS auth: verify OTP
 * @param {string} phone
 * @param {string} code
 * @returns {Promise<boolean>}
 */
async function verifySmsOtp(phone, code) {
  try {
    const response = await fetch('/api/v1/auth/sms/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone, code }),
    });

    if (!response.ok) return false;

    const data = await response.json();
    Alpine.store('auth').login(data.token, data.user);
    document.dispatchEvent(new CustomEvent('auth:login', { detail: data.user }));
    return true;
  } catch (err) {
    console.error('SMS verify error:', err);
    return false;
  }
}

// Alpine.js component for the login modal
function loginModal() {
  return {
    isOpen: false,
    tab: 'telegram', // 'telegram' | 'sms'
    phone: '',
    otp: '',
    step: 'phone', // 'phone' | 'otp'
    loading: false,
    error: '',

    open() {
      this.isOpen = true;
      this.error = '';
    },

    close() {
      this.isOpen = false;
      this.error = '';
      this.step = 'phone';
      this.phone = '';
      this.otp = '';
    },

    async requestOtp() {
      if (!this.phone) return;
      this.loading = true;
      this.error = '';
      const ok = await requestSmsOtp(this.phone);
      this.loading = false;
      if (ok) {
        this.step = 'otp';
      } else {
        this.error = 'Не удалось отправить SMS. Проверьте номер.';
      }
    },

    async verifyOtp() {
      if (!this.otp) return;
      this.loading = true;
      this.error = '';
      const ok = await verifySmsOtp(this.phone, this.otp);
      this.loading = false;
      if (ok) {
        this.close();
      } else {
        this.error = 'Неверный код. Попробуйте снова.';
      }
    },
  };
}
