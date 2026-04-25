// ============================================================
// CaféOS — API Client
// File: frontend/js/api.js
// ============================================================
// Centralised wrapper for all backend API calls.
// All methods return { success, data, message } or throw.
//
// Usage:
//   const menu = await API.menu.getAll();
//   const order = await API.orders.create({ table_id: 1 });
// ============================================================

const API = (() => {

    // ── Config ───────────────────────────────────────────────
    // Change this to your server URL in production
    const BASE = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
        ? 'http://localhost/cafeos/backend/api'
        : '/backend/api';

    // ── Token management ─────────────────────────────────────
    // ── Electron detection ────────────────────────────────────
    const isElectron = !!(window.electronAPI?.isElectron);

    const Auth = {
        getToken: () => sessionStorage.getItem('cafeos_token'),
        setToken: (t) => {
            sessionStorage.setItem('cafeos_token', t);
            // Also persist to electron-store so offline queue flush can use it
            if (isElectron) window.electronAPI.config.set('authToken', t);
        },
        clearToken: () => {
            sessionStorage.removeItem('cafeos_token');
            if (isElectron) window.electronAPI.config.set('authToken', '');
        },
        getUser: () => {
            const raw = sessionStorage.getItem('cafeos_user');
            return raw ? JSON.parse(raw) : null;
        },
        setUser: (u) => sessionStorage.setItem('cafeos_user', JSON.stringify(u)),
        clearUser: () => sessionStorage.removeItem('cafeos_user'),
        isLoggedIn: () => !!sessionStorage.getItem('cafeos_token'),
        hasRole: (role) => {
            const u = Auth.getUser();
            return u && (Array.isArray(role) ? role.includes(u.role) : u.role === role);
        },
    };

    // ── Core fetch wrapper ────────────────────────────────────
    async function request(endpoint, method = 'GET', body = null, requireAuth = true) {
        const headers = { 'Content-Type': 'application/json' };

        if (requireAuth) {
            const token = Auth.getToken();
            if (!token) {
                redirectToLogin();
                throw new Error('Not authenticated');
            }
            headers['Authorization'] = `Bearer ${token}`;
        }

        const options = { method, headers };
        if (body && method !== 'GET') {
            options.body = JSON.stringify(body);
        }

        try {
            const res = await fetch(`${BASE}/${endpoint}`, options);

            // Handle 401 globally — redirect to login
            if (res.status === 401) {
                Auth.clearToken();
                Auth.clearUser();
                redirectToLogin();
                throw new Error('Session expired');
            }

            const json = await res.json();

            if (!json.success) {
                throw new APIError(json.message || 'Request failed', res.status, json.errors);
            }

            return json.data;

        } catch (err) {
            if (err instanceof APIError) throw err;

            // In Electron, queue write operations when server is unreachable
            if (isElectron && method !== 'GET' && window.electronAPI?.queue) {
                const QUEUEABLE = ['orders.php', 'billing.php'];
                const canQueue  = QUEUEABLE.some(e => endpoint.includes(e));
                if (canQueue) {
                    try {
                        await window.electronAPI.queue.add({ method, endpoint, body });
                        console.warn('[CaféOS] Server unreachable — queued: ' + method + ' ' + endpoint);
                        throw new APIError('Server offline — request queued for sync', 0);
                    } catch (qErr) {
                        if (qErr instanceof APIError) throw qErr;
                    }
                }
            }

            throw new APIError(err.message || 'Network error. Check server connection.', 0);
        }
    }

    // Custom error class
    class APIError extends Error {
        constructor(message, status = 400, errors = null) {
            super(message);
            this.name    = 'APIError';
            this.status  = status;
            this.errors  = errors;
        }
    }

    function redirectToLogin() {
        const current = encodeURIComponent(window.location.pathname);
        window.location.href = `/cafeos/frontend/pages/login.html?redirect=${current}`;
    }

    // ── Auth endpoints ────────────────────────────────────────
    const auth = {
        async login(pin, email = null) {
            const data = await request('auth.php', 'POST', { pin, email }, false);
            Auth.setToken(data.token);
            Auth.setUser(data.staff);
            return data;
        },
        async logout() {
            await request('auth.php', 'DELETE').catch(() => {});
            Auth.clearToken();
            Auth.clearUser();
            window.location.href = '/cafeos/frontend/pages/login.html';
        },
        async me() {
            return request('auth.php', 'GET');
        },
        async changePin(oldPin, newPin) {
            return request('auth.php', 'PUT', { old_pin: oldPin, new_pin: newPin });
        },
    };

    // ── Menu endpoints ────────────────────────────────────────
    const menu = {
        async getAll(showAll = false) {
            const qs = showAll ? '?all=1' : '';
            return request(`menu.php${qs}`);
        },
        async getCategories(all = false) {
            return request(`menu.php?resource=categories${all ? '&all=1' : ''}`);
        },
        async getItem(id) {
            return request(`menu.php?id=${id}`);
        },
        async createItem(data) {
            return request('menu.php', 'POST', data);
        },
        async updateItem(id, data) {
            return request(`menu.php?id=${id}`, 'PUT', data);
        },
        async toggleAvailability(id, isAvailable) {
            return request(`menu.php?id=${id}`, 'PUT', { is_available: isAvailable ? 1 : 0 });
        },
        async deleteItem(id) {
            return request(`menu.php?id=${id}`, 'DELETE');
        },
        async createCategory(data) {
            return request('menu.php?resource=categories', 'POST', data);
        },
        async updateCategory(id, data) {
            return request(`menu.php?resource=categories&id=${id}`, 'PUT', data);
        },
    };

    // ── Tables endpoints ──────────────────────────────────────
    const tables = {
        async getAll(section = null) {
            const qs = section ? `?section=${encodeURIComponent(section)}` : '';
            return request(`tables.php${qs}`);
        },
        async getOne(id) {
            return request(`tables.php?id=${id}`);
        },
        async updateStatus(id, status) {
            return request(`tables.php?id=${id}`, 'PUT', { status });
        },
        async create(data) {
            return request('tables.php', 'POST', data);
        },
        async update(id, data) {
            return request(`tables.php?id=${id}`, 'PUT', data);
        },
        async delete(id) {
            return request(`tables.php?id=${id}`, 'DELETE');
        },
    };

    // ── Orders endpoints ──────────────────────────────────────
    const orders = {
        async list(params = {}) {
            const qs = new URLSearchParams(params).toString();
            return request(`orders.php${qs ? '?' + qs : ''}`);
        },
        async get(id) {
            return request(`orders.php?id=${id}`);
        },
        async create(data) {
            return request('orders.php', 'POST', data);
        },
        async update(id, data) {
            return request(`orders.php?id=${id}`, 'PUT', data);
        },
        async cancel(id) {
            return request(`orders.php?id=${id}`, 'DELETE');
        },
        async addItem(orderId, item) {
            return request(`orders.php?id=${orderId}&action=add_item`, 'POST', item);
        },
        async updateItem(orderId, itemId, quantity) {
            return request(`orders.php?id=${orderId}&action=update_item`, 'PUT', { item_id: itemId, quantity });
        },
        async voidItem(orderId, itemId) {
            return request(`orders.php?id=${orderId}&action=void_item`, 'PUT', { item_id: itemId });
        },
        async sendToKitchen(orderId) {
            return request(`orders.php?id=${orderId}&action=send_kitchen`, 'PUT', {});
        },
    };

    // ── Billing endpoints ─────────────────────────────────────
    const billing = {
        async preview(orderId, discountType = 'none', discountValue = 0) {
            const qs = `?order_id=${orderId}&discount_type=${discountType}&discount_value=${discountValue}`;
            return request(`billing.php${qs}`);
        },
        async generate(data) {
            return request('billing.php', 'POST', data);
        },
        async recordPayment(billId, data) {
            return request(`billing.php?id=${billId}`, 'PUT', data);
        },
        async get(billId) {
            return request(`billing.php?id=${billId}`);
        },
        async list(date = 'today') {
            return request(`billing.php?date=${date}`);
        },
    };

    // ── Reports endpoints ─────────────────────────────────────
    const reports = {
        async dashboard() {
            return request('reports.php?type=dashboard');
        },
        async daily(from, to) {
            return request(`reports.php?type=daily&date_from=${from}&date_to=${to}`);
        },
        async hourly(date) {
            return request(`reports.php?type=hourly&date_from=${date}`);
        },
        async topItems(from, to, limit = 10) {
            return request(`reports.php?type=items&date_from=${from}&date_to=${to}&limit=${limit}`);
        },
        async categories(from, to) {
            return request(`reports.php?type=categories&date_from=${from}&date_to=${to}`);
        },
        async payments(from, to) {
            return request(`reports.php?type=payments&date_from=${from}&date_to=${to}`);
        },
        async staff(from, to) {
            return request(`reports.php?type=staff&date_from=${from}&date_to=${to}`);
        },
        async weekly() {
            return request('reports.php?type=weekly');
        },
        async monthly() {
            return request('reports.php?type=monthly');
        },
    };

    // ── Utility helpers ───────────────────────────────────────
    const utils = {
        // Format currency with symbol from localStorage (set on login)
        currency: (amount) => {
            const sym = sessionStorage.getItem('cafeos_currency') || '₹';
            return `${sym}${parseFloat(amount).toFixed(2)}`;
        },
        // Format time ago
        timeAgo: (dateStr) => {
            const diff = Math.floor((Date.now() - new Date(dateStr)) / 60000);
            if (diff < 1)  return 'just now';
            if (diff < 60) return `${diff}m ago`;
            return `${Math.floor(diff / 60)}h ${diff % 60}m ago`;
        },
        // Show toast notification
        toast: (msg, type = 'info') => {
            const el = document.getElementById('toast-container');
            if (!el) return;
            const t = document.createElement('div');
            t.className = `toast toast-${type}`;
            t.textContent = msg;
            el.appendChild(t);
            setTimeout(() => t.classList.add('show'), 10);
            setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
        },
        // Show loading spinner on a button
        btnLoading: (btn, loading) => {
            if (loading) {
                btn.dataset.originalText = btn.textContent;
                btn.textContent = '...';
                btn.disabled = true;
            } else {
                btn.textContent = btn.dataset.originalText || 'Submit';
                btn.disabled = false;
            }
        },
        // Format date for input fields
        today: () => new Date().toISOString().split('T')[0],
    };

    // Public API
    return { Auth, auth, menu, tables, orders, billing, reports, utils, APIError };
})();
