// public_html/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    let csrfToken = null;
    let globalSettings = null;

    const authApp = document.getElementById('auth-app');
    const mainApp = document.getElementById('main-app');
    const formLogin = document.getElementById('form-login');
    const formRegister = document.getElementById('form-register');
    const formForcePass = document.getElementById('form-force-password');
    const viewBlocked = document.getElementById('view-blocked');

    const authError = document.getElementById('auth-error');
    const authInfo = document.getElementById('auth-info-alert');

    async function fetchCSRF() {
        try {
            const res = await fetch('api/auth.php?action=csrf');
            const data = await res.json();
            csrfToken = data.csrf_token;
            return true;
        } catch (e) {
            console.error("CSRF Fetch failed", e);
            return false;
        }
    }

    async function apiFetch(url, method = 'POST', body = null) {
        if (!csrfToken) await fetchCSRF();

        let initOptions = { method: method, headers: {} };
        if (method === 'POST') {
            initOptions.headers['Content-Type'] = 'application/json';
            const data = body || {};
            data.csrf_token = csrfToken;
            initOptions.body = JSON.stringify(data);
        }

        const res = await fetch(url, initOptions);
        const data = await res.json();

        if (!res.ok) {
            if (data.code === 'SUBSCRIPTION_REQUIRED') {
                showBlockedScreen();
                throw new Error("BLOCKED");
            }
            throw new Error(data.error || 'Server Error');
        }
        return data;
    }

    function showAuthError(msg) {
        authError.textContent = msg;
        authError.classList.remove('hidden');
        authInfo.classList.add('hidden');
    }

    function showAuthInfo(msg) {
        authInfo.textContent = msg;
        authInfo.classList.remove('hidden');
        authError.classList.add('hidden');
    }

    function showBlockedScreen() {
        authApp.classList.remove('hidden');
        mainApp.classList.add('hidden');
        formLogin.classList.add('hidden');
        formRegister.classList.add('hidden');
        formForcePass.classList.add('hidden');
        viewBlocked.classList.remove('hidden');
    }

    // --- INIT ---
    async function initApp() {
        await fetchCSRF();
        try {
            const data = await apiFetch('api/app.php?action=init', 'GET');
            if (data === "BLOCKED") return;

            if (data.must_change_password) {
                // Show force password form
                authApp.classList.remove('hidden');
                formLogin.classList.add('hidden');
                formForcePass.classList.remove('hidden');
                return;
            }

            // Normal load
            authApp.classList.add('hidden');
            mainApp.classList.remove('hidden');

            applySettings(data.settings);
            updateDashboardUI(data);

        } catch (err) {
            if (err.message !== "BLOCKED") {
                // Not logged in
                authApp.classList.remove('hidden');
                formLogin.classList.remove('hidden');
            }
        }
    }

    function applySettings(settings) {
        globalSettings = settings;
        document.documentElement.setAttribute('dir', settings.language === 'ar' ? 'rtl' : 'ltr');
        document.documentElement.setAttribute('lang', settings.language);
        document.documentElement.setAttribute('data-theme', settings.theme);
    }

    function updateDashboardUI(data) {
        document.getElementById('header-user-name').textContent = data.user.name;
        document.getElementById('brand-name').textContent = data.settings.company_name;

        const badge = document.getElementById('tenant-badge');
        badge.textContent = data.tenant.status;
        badge.className = 'tenant-badge ' + (data.tenant.status === 'active' ? 'bg-success' : 'bg-warning');

        document.getElementById('kpi-sales-today').textContent = `${data.kpi.sales_today} ${data.settings.currency}`;
        document.getElementById('kpi-sales-month').textContent = `${data.kpi.sales_month} ${data.settings.currency}`;
        document.getElementById('kpi-expenses').textContent = `${data.kpi.expenses_month} ${data.settings.currency}`;
        document.getElementById('kpi-low-stock').textContent = data.kpi.low_stock;
    }

    // --- AUTH EVENTS ---
    document.getElementById('link-to-register').addEventListener('click', (e) => {
        e.preventDefault();
        formLogin.classList.add('hidden');
        formRegister.classList.remove('hidden');
        authError.classList.add('hidden');
        authInfo.classList.add('hidden');
    });

    document.getElementById('link-to-login').addEventListener('click', (e) => {
        e.preventDefault();
        formRegister.classList.add('hidden');
        formLogin.classList.remove('hidden');
        authError.classList.add('hidden');
        authInfo.classList.add('hidden');
    });

    document.getElementById('link-forgot-pass').addEventListener('click', (e) => {
        e.preventDefault();
        Swal.fire({
            icon: 'info',
            title: 'Password Reset',
            text: 'Please contact support or your system administrator on WhatsApp to request a secure password reset token.'
        });
    });

    formLogin.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-login');
        btn.textContent = 'Loading...';
        btn.disabled = true;
        try {
            await apiFetch('api/auth.php?action=login', 'POST', {
                code: document.getElementById('login-code').value,
                password: document.getElementById('login-password').value
            });
            await initApp();
        } catch (err) {
            if (err.message !== "BLOCKED") showAuthError(err.message);
        } finally {
            btn.textContent = 'Login';
            btn.disabled = false;
        }
    });

    formRegister.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-register');
        btn.textContent = 'Creating Account...';
        btn.disabled = true;
        try {
            const res = await apiFetch('api/auth.php?action=signup', 'POST', {
                name: document.getElementById('reg-name').value,
                whatsapp: document.getElementById('reg-whatsapp').value,
                password: document.getElementById('reg-password').value,
                business_activity: document.getElementById('reg-activity').value
            });
            // Show the strict code immediately using SweetAlert securely
            Swal.fire({
                icon: 'success',
                title: 'Welcome to ENGAZ ERP!',
                html: `Your unique Company Login Code is: <b><span style="font-size:24px; color:#2563eb; letter-spacing:4px;">${res.code}</span></b><br><br><span style="color:#ef4444;">WARNING: Save this code now. You cannot log in without it. Do NOT share it.</span>`,
                confirmButtonText: 'I Have Saved the Code',
                allowOutsideClick: false
            }).then(() => {
                initApp();
            });
        } catch (err) {
            showAuthError(err.message);
        } finally {
            btn.textContent = 'Start 7-Day Free Trial';
            btn.disabled = false;
        }
    });

    formForcePass.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await apiFetch('api/app.php?action=change_force_password', 'POST', {
                new_password: document.getElementById('force-new-password').value
            });
            await initApp();
            Swal.fire({ icon: 'success', title: 'Password Updated', timer: 1500, showConfirmButton: false });
        } catch (err) {
            showAuthError(err.message);
        }
    });

    document.getElementById('btn-blocked-logout').addEventListener('click', async () => {
        await apiFetch('api/auth.php?action=logout', 'POST');
        location.reload();
    });

    document.getElementById('btn-app-logout').addEventListener('click', async () => {
        await apiFetch('api/auth.php?action=logout', 'POST');
        location.reload();
    });

    // --- NAVIGATION ---
    const navLinks = document.querySelectorAll('.nav-link');
    const views = document.querySelectorAll('.view-panel');
    const pageTitle = document.getElementById('page-title');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            navLinks.forEach(l => l.classList.remove('active'));
            link.classList.add('active');

            const target = link.dataset.view;
            views.forEach(v => v.classList.add('hidden'));

            const targetView = document.getElementById('view-' + target);
            if (targetView) targetView.classList.remove('hidden');

            pageTitle.textContent = link.querySelector('span').textContent;

            // Re-init dashboard on view load if needed
            if (target === 'dashboard') initApp();
        });
    });

    // Theme toggler
    document.getElementById('btn-theme-toggle').addEventListener('click', () => {
        const root = document.documentElement;
        const currentTheme = root.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', newTheme);
        // Save dynamically (API call omitted here for brevity, but should be saved in settings)
    });

    // Start App
    initApp();
});
