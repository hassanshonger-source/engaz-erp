// admin_html/js/admin.js

document.addEventListener('DOMContentLoaded', () => {
    let csrfToken = null;

    // Fetch CSRF initial wrapper
    async function init() {
        try {
            const res = await fetch('api/auth.php?action=csrf');
            const data = await res.json();
            csrfToken = data.csrf_token;
            // Check session status - simple ping
            const ping = await apiFetch('api/admin.php?action=dashboard_stats', 'GET');
            if (ping.success) {
                showMainApp();
                loadDashboard(ping.stats);
            }
        } catch (err) {
            // Not logged in
            document.getElementById('auth-app').classList.remove('hidden');
        }
    }

    async function apiFetch(url, method = 'POST', body = null) {
        if (!csrfToken) {
            throw new Error("Application not fully loaded");
        }

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
            if (res.status === 401) {
                // Ignore silent pings on boot
            }
            throw new Error(data.error || 'Server Error');
        }
        return data;
    }

    function showError(msg) {
        const err = document.getElementById('auth-error');
        err.textContent = msg;
        err.classList.remove('hidden');
    }

    // Auth events
    document.getElementById('admin-login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const usernameBtn = document.getElementById('btn-login');
        usernameBtn.textContent = 'Authenticating...';

        try {
            const data = await apiFetch('api/auth.php?action=login', 'POST', {
                username: document.getElementById('login-username').value,
                password: document.getElementById('login-password').value
            });
            if (data.must_change_password) {
                document.getElementById('admin-login-form').classList.add('hidden');
                document.getElementById('force-password-form').classList.remove('hidden');
            } else {
                showMainApp();
                init(); // reload data
            }
        } catch (err) {
            showError(err.message);
        } finally {
            usernameBtn.textContent = 'Login via Secure Portal';
        }
    });

    document.getElementById('force-password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await apiFetch('api/auth.php?action=change_default_password', 'POST', {
                new_password: document.getElementById('new-password').value
            });
            showMainApp();
            init();
        } catch (err) {
            showError(err.message);
        }
    });

    document.getElementById('btn-logout').addEventListener('click', async () => {
        try {
            await apiFetch('api/auth.php?action=logout', 'POST');
            location.reload();
        } catch (err) {
            location.reload();
        }
    });

    function showMainApp() {
        document.getElementById('auth-app').classList.add('hidden');
        document.getElementById('main-app').classList.remove('hidden');
    }

    // Navigation View Switcher
    const navLinks = document.querySelectorAll('.nav-link');
    const views = document.querySelectorAll('.view-container');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const target = link.dataset.view;

            navLinks.forEach(l => l.classList.remove('active'));
            link.classList.add('active');

            views.forEach(v => v.classList.add('hidden'));
            document.getElementById('view-' + target).classList.remove('hidden');
            document.getElementById('current-view-title').textContent = link.textContent;

            // Trigger Loads
            if (target === 'dashboard') init();
            if (target === 'tenants') loadTenants();
        });
    });

    // Load Data Functions
    const chartCtx = document.getElementById('growthChart')?.getContext('2d');
    let chartInstance = null;

    function loadDashboard(stats) {
        document.getElementById('kpi-active-tenants').textContent = stats.active || 0;
        document.getElementById('kpi-trialing').textContent = stats.trialing || 0;
        document.getElementById('kpi-expired').textContent = stats.expired || 0;

        if (chartCtx) {
            if (chartInstance) chartInstance.destroy();
            chartInstance = new Chart(chartCtx, {
                type: 'bar',
                data: {
                    labels: ['Active', 'Trialing', 'Expired'],
                    datasets: [{
                        label: 'Tenant Distribution',
                        data: [stats.active, stats.trialing, stats.expired],
                        backgroundColor: ['rgba(16, 185, 129, 0.5)', 'rgba(59, 130, 246, 0.5)', 'rgba(239, 68, 68, 0.5)'],
                        borderColor: ['#10b981', '#3b82f6', '#ef4444'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false }, tooltip: { theme: 'dark' } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } },
                        x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
                    }
                }
            });
        }
    }

    async function loadTenants(searchTerm = '') {
        try {
            const data = await apiFetch('api/admin.php?action=tenants_list&search=' + encodeURIComponent(searchTerm), 'GET');
            const tbody = document.querySelector('#tenants-table tbody');
            tbody.innerHTML = '';

            data.tenants.forEach(t => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>#${t.id}</td>
                    <td><strong>${t.name}</strong><br><small class="text-muted">Since: ${t.created_at.split(' ')[0]}</small></td>
                    <td><span class="badge ${t.status}">${t.status}</span></td>
                    <td>${t.subscription_ends_at ? t.subscription_ends_at.split(' ')[0] : (t.trial_ends_at ? 'Trial:' + t.trial_ends_at.split(' ')[0] : 'N/A')}</td>
                    <td>
                        <button class="btn btn-primary btn-manage" data-id="${t.id}" data-name="${t.name}">Manage Sub</button>
                    </td>
                `;
                tbody.appendChild(row);
            });

            document.querySelectorAll('.btn-manage').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    openManageModal(e.target.dataset.id, e.target.dataset.name);
                });
            });

        } catch (err) {
            console.error(err);
        }
    }

    document.getElementById('tenant-search').addEventListener('input', (e) => {
        loadTenants(e.target.value);
    });

    // Modals
    const modalManage = document.getElementById('modal-manage-sub');
    const modalClose = document.querySelectorAll('.close-modal');

    modalClose.forEach(c => {
        c.addEventListener('click', () => {
            modalManage.classList.add('hidden');
        });
    });

    function openManageModal(id, name) {
        document.getElementById('manage-tenant-id').value = id;
        document.getElementById('modal-tenant-name').textContent = name;
        document.getElementById('modal-sub-msg').classList.add('hidden');
        document.getElementById('temp-pass-display').classList.add('hidden');
        modalManage.classList.remove('hidden');
    }

    document.getElementById('form-manage-sub').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await apiFetch('api/admin.php?action=manage_subscription', 'POST', {
                tenant_id: document.getElementById('manage-tenant-id').value,
                duration: document.getElementById('sub-duration').value
            });

            const msg = document.getElementById('modal-sub-msg');
            msg.textContent = res.message;
            msg.className = 'alert success';
            loadTenants(); // refresh bg
        } catch (err) {
            const msg = document.getElementById('modal-sub-msg');
            msg.textContent = err.message;
            msg.className = 'alert error';
        }
    });

    document.getElementById('btn-gen-temp-pass').addEventListener('click', async () => {
        if (!confirm("Warning: Existing password will be erased. Proceed?")) return;
        try {
            const res = await apiFetch('api/admin.php?action=generate_temp_password', 'POST', {
                tenant_id: document.getElementById('manage-tenant-id').value,
            });
            const dsp = document.getElementById('temp-pass-display');
            dsp.textContent = 'Temp Pass: ' + res.temp_password + '\n' + res.message;
            dsp.classList.remove('hidden');
        } catch (err) {
            alert(err.message);
        }
    });

    // Start
    init();
});
