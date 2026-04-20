/**
 * app.js — глобальные утилиты и Alpine.js store
 */

// ─── Alpine.js Store ─────────────────────────────────────────────────────────
document.addEventListener('alpine:init', () => {
  Alpine.store('auth', {
    user: null,
    token: null,

    init() {
      const token = localStorage.getItem('jwt_token');
      const user = localStorage.getItem('auth_user');
      if (token && user) {
        try {
          this.token = token;
          this.user = JSON.parse(user);
        } catch (e) {
          this.logout();
        }
      }
    },

    login(token, user) {
      this.token = token;
      this.user = user;
      localStorage.setItem('jwt_token', token);
      localStorage.setItem('auth_user', JSON.stringify(user));
    },

    logout() {
      this.token = null;
      this.user = null;
      localStorage.removeItem('jwt_token');
      localStorage.removeItem('auth_user');
    },

    get isAuthenticated() {
      return !!this.token;
    },

    get isAdmin() {
      if (!this.token) return false;
      try {
        const payload = JSON.parse(atob(this.token.split('.')[1]));
        return payload.role === 'admin';
      } catch (e) {
        return false;
      }
    },

    get quota() {
      return this.user?.quota || null;
    }
  });
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Get JWT token from localStorage
 * @returns {string|null}
 */
function getToken() {
  return localStorage.getItem('jwt_token');
}

/**
 * Fetch wrapper with JWT header and 401 handling
 * @param {string} url
 * @param {RequestInit} options
 * @returns {Promise<Response>}
 */
async function apiFetch(url, options = {}) {
  const token = getToken();
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  // Don't set Content-Type for FormData — let browser set multipart boundary
  if (options.body instanceof FormData) {
    delete headers['Content-Type'];
  }

  const response = await fetch(url, { ...options, headers });

  if (response.status === 401) {
    Alpine.store('auth').logout();
    // Show login modal if available
    const modal = document.getElementById('login-modal');
    if (modal) {
      modal._x_dataStack?.[0]?.open?.();
    }
    throw new Error('Unauthorized');
  }

  return response;
}

/**
 * Format bytes to human-readable size
 * @param {number} bytes
 * @returns {string}
 */
function formatFileSize(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

/**
 * Format conversion status to display object
 * @param {string} status
 * @returns {{ label: string, color: string, icon: string }}
 */
function formatStatus(status) {
  const map = {
    idle:       { label: 'Ожидание',    color: 'text-zinc-400',   icon: '○' },
    uploading:  { label: 'Загрузка',    color: 'text-indigo-400', icon: '↑' },
    pending:    { label: 'В очереди',   color: 'text-yellow-400', icon: '⏳' },
    processing: { label: 'Обработка',   color: 'text-blue-400',   icon: '⟳' },
    done:       { label: 'Готово',      color: 'text-green-400',  icon: '✓' },
    error:      { label: 'Ошибка',      color: 'text-red-400',    icon: '✗' },
  };
  return map[status] || { label: status, color: 'text-zinc-400', icon: '○' };
}

/**
 * Format date to locale string
 * @param {string} dateStr
 * @returns {string}
 */
function formatDate(dateStr) {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

// ─── HTMX JWT header injection ────────────────────────────────────────────────
document.addEventListener('htmx:configRequest', (e) => {
  const token = getToken();
  if (token) {
    e.detail.headers['Authorization'] = `Bearer ${token}`;
  }
});

// Handle 401 from HTMX requests
document.addEventListener('htmx:responseError', (e) => {
  if (e.detail.xhr.status === 401) {
    Alpine.store('auth').logout();
  }
});
