/**
 * Filename: dashboard.js
 * Description: Frontend JavaScript for dashboard interactivity and AJAX operations
 * Version: 1.0.0
 * Created: 2026-02-16 11:30:00 SAST
 * Modified: 2026-02-16 11:30:00 SAST
 */

// Global state
let charts = {};
let currentTab = 'dashboard';

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    initializeTabs();
    initializeModals();
    initializeEventListeners();
    loadDashboardData();
});

/**
 * Tab Management
 */
function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabId = button.getAttribute('data-tab');

            // Update active states
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            button.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');

            currentTab = tabId;

            // Load tab-specific data
            loadTabData(tabId);
        });
    });
}

/**
 * Load data for specific tab
 */
function loadTabData(tabId) {
    switch (tabId) {
        case 'dashboard':
            loadDashboardData();
            break;
        case 'competitors':
            loadCompetitors();
            break;
        case 'price-explorer':
            loadPriceMatrix();
            break;
        case 'trends':
            loadTrendsData();
            break;
        case 'audit':
            loadCronLogs();
            break;
        case 'settings':
            loadSettings();
            break;
    }
}

/**
 * Dashboard Tab
 */
function loadDashboardData() {
    ajax('get_dashboard_data', {}, (response) => {
        if (response.success) {
            const data = response.data;

            // Update stats
            if (data.our_position) {
                document.getElementById('stat-position').textContent = '#' + data.our_position.position;
            }

            if (data.high_demand_count !== undefined) {
                document.getElementById('stat-high-demand').textContent = data.high_demand_count;
            }

            if (data.price_comparison) {
                const ourPrice = parseFloat(data.price_comparison.our_avg_price);
                const marketPrice = parseFloat(data.price_comparison.avg_competitor_price);

                if (ourPrice && marketPrice) {
                    const difference = ourPrice - marketPrice;
                    const percentage = ((difference / marketPrice) * 100).toFixed(1);
                    const sign = difference > 0 ? '+' : '';
                    document.getElementById('stat-price-gap').textContent = sign + percentage + '%';
                }
            }

            if (data.last_cron) {
                const date = new Date(data.last_cron.started_at);
                document.getElementById('stat-last-update').textContent = formatRelativeTime(date);
            }

            // Load demand calendar
            loadDemandCalendar();
        }
    });
}

function loadDemandCalendar() {
    ajax('get_demand_calendar', {}, (response) => {
        if (response.success) {
            renderDemandCalendar(response.data);
        }
    });
}

function renderDemandCalendar(data) {
    const calendar = document.getElementById('demand-calendar');
    calendar.innerHTML = '';

    data.forEach(day => {
        const dayEl = document.createElement('div');
        dayEl.className = 'calendar-day demand-' + day.demand_signal;

        const dateEl = document.createElement('div');
        dateEl.className = 'calendar-day-date';
        dateEl.textContent = formatCalendarDate(day.check_date);

        const scoreEl = document.createElement('div');
        scoreEl.className = 'calendar-day-score';
        scoreEl.textContent = day.demand_score;

        const signalEl = document.createElement('div');
        signalEl.className = 'calendar-day-signal';
        signalEl.textContent = day.demand_signal;

        dayEl.appendChild(dateEl);
        dayEl.appendChild(scoreEl);
        dayEl.appendChild(signalEl);

        dayEl.onclick = () => showDayDetails(day);

        calendar.appendChild(dayEl);
    });
}

function showDayDetails(day) {
    alert(`Date: ${day.check_date}\n` +
          `Demand Score: ${day.demand_score}/10\n` +
          `Signal: ${day.demand_signal}\n` +
          `Market Average: R${day.avg_market_price}\n` +
          `Our Price: R${day.our_price}`);
}

/**
 * Competitors Tab
 */
function loadCompetitors() {
    ajax('get_competitors', {}, (response) => {
        if (response.success) {
            renderCompetitorsTable(response.data);
        }
    });
}

function renderCompetitorsTable(competitors) {
    const container = document.getElementById('competitors-table');

    let html = '<table><thead><tr>';
    html += '<th>Property Name</th>';
    html += '<th>Type</th>';
    html += '<th>Hotel ID</th>';
    html += '<th>Status</th>';
    html += '<th>Actions</th>';
    html += '</tr></thead><tbody>';

    competitors.forEach(comp => {
        html += '<tr>';
        html += `<td><strong>${escapeHtml(comp.name)}</strong></td>`;
        html += `<td>${comp.is_ours ? '<span class="badge badge-success">Our Property</span>' : '<span class="badge badge-info">Competitor</span>'}</td>`;
        html += `<td><code>${comp.hotel_identifier || 'Not found'}</code></td>`;
        html += `<td>${comp.active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">Inactive</span>'}</td>`;
        html += '<td>';

        if (!comp.is_ours) {
            html += `<button class="btn-secondary" onclick="toggleCompetitor(${comp.id})">${comp.active ? 'Deactivate' : 'Activate'}</button> `;
            html += `<button class="btn-secondary" onclick="removeCompetitor(${comp.id})">Remove</button>`;
        }

        html += '</td>';
        html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

function toggleCompetitor(id) {
    if (confirm('Toggle this competitor\'s active status?')) {
        ajax('toggle_competitor', { id: id }, (response) => {
            if (response.success) {
                loadCompetitors();
                showNotification('Competitor status updated', 'success');
            } else {
                showNotification(response.error, 'error');
            }
        });
    }
}

function removeCompetitor(id) {
    if (confirm('Remove this competitor? This will delete all associated data.')) {
        ajax('remove_competitor', { id: id }, (response) => {
            if (response.success) {
                loadCompetitors();
                showNotification('Competitor removed', 'success');
            } else {
                showNotification(response.error, 'error');
            }
        });
    }
}

/**
 * Price Explorer Tab
 */
function loadPriceMatrix() {
    const checkIn = document.getElementById('price-check-in').value;

    ajax('get_price_matrix', { check_in: checkIn }, (response) => {
        if (response.success) {
            renderPriceMatrix(response.data);
        }
    }, 'GET');
}

function renderPriceMatrix(data) {
    const container = document.getElementById('price-matrix');

    // Get all unique OTA sources
    const otaSources = new Set();
    Object.values(data).forEach(property => {
        Object.keys(property.prices).forEach(ota => otaSources.add(ota));
    });

    const otaArray = Array.from(otaSources).sort();

    let html = '<div class="price-matrix-table"><table><thead><tr>';
    html += '<th>Property</th>';
    otaArray.forEach(ota => {
        html += `<th>${escapeHtml(ota)}</th>`;
    });
    html += '</tr></thead><tbody>';

    Object.entries(data).forEach(([propertyName, propertyData]) => {
        const rowClass = propertyData.is_ours ? 'price-our' : '';
        html += `<tr class="${rowClass}">`;
        html += `<td><strong>${escapeHtml(propertyName)}</strong></td>`;

        otaArray.forEach(ota => {
            if (propertyData.prices[ota]) {
                const price = propertyData.prices[ota].price;
                const link = propertyData.prices[ota].link;
                html += `<td class="price-cell">`;
                if (link) {
                    html += `<a href="${escapeHtml(link)}" target="_blank">R${price}</a>`;
                } else {
                    html += `R${price}`;
                }
                html += '</td>';
            } else {
                html += '<td class="price-cell">—</td>';
            }
        });

        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
}

/**
 * Trends Tab
 */
function loadTrendsData() {
    // Load position trend
    ajax('get_trends_data', { type: 'position', property_id: 1 }, (response) => {
        if (response.success) {
            renderPositionChart(response.data);
        }
    }, 'GET');

    // Load price trend
    ajax('get_trends_data', { type: 'price' }, (response) => {
        if (response.success) {
            renderPriceChart(response.data);
        }
    }, 'GET');

    // Load demand trend
    ajax('get_trends_data', { type: 'demand' }, (response) => {
        if (response.success) {
            renderDemandChart(response.data);
        }
    }, 'GET');
}

function renderPositionChart(data) {
    const ctx = document.getElementById('chart-position');

    if (charts.position) {
        charts.position.destroy();
    }

    charts.position = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'Position',
                data: data.map(d => d.avg_position),
                borderColor: '#D4A843',
                backgroundColor: 'rgba(212, 168, 67, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    reverse: true,
                    beginAtZero: false
                }
            }
        }
    });
}

function renderPriceChart(data) {
    const ctx = document.getElementById('chart-price');

    if (charts.price) {
        charts.price.destroy();
    }

    charts.price = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.date),
            datasets: [
                {
                    label: 'Market Average',
                    data: data.map(d => d.avg_market),
                    borderColor: '#999',
                    backgroundColor: 'rgba(153, 153, 153, 0.1)',
                    tension: 0.4
                },
                {
                    label: 'Our Price',
                    data: data.map(d => d.our_price),
                    borderColor: '#D4A843',
                    backgroundColor: 'rgba(212, 168, 67, 0.1)',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true }
            }
        }
    });
}

function renderDemandChart(data) {
    const ctx = document.getElementById('chart-demand');

    if (charts.demand) {
        charts.demand.destroy();
    }

    charts.demand = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.date),
            datasets: [{
                label: 'Demand Score',
                data: data.map(d => d.demand_score),
                backgroundColor: data.map(d => {
                    if (d.demand_score >= 9) return '#c62828';
                    if (d.demand_score >= 7) return '#d84315';
                    if (d.demand_score >= 5) return '#e65100';
                    if (d.demand_score >= 3) return '#f57f17';
                    return '#2e7d32';
                })
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 10
                }
            }
        }
    });
}

/**
 * Audit Tab
 */
function loadCronLogs() {
    ajax('get_cron_logs', {}, (response) => {
        if (response.success) {
            renderCronLogs(response.data);
        }
    });
}

function renderCronLogs(logs) {
    const container = document.getElementById('cron-log-table');

    let html = '<table><thead><tr>';
    html += '<th>Started</th>';
    html += '<th>Status</th>';
    html += '<th>API Calls</th>';
    html += '<th>Cost</th>';
    html += '<th>Duration</th>';
    html += '</tr></thead><tbody>';

    logs.forEach(log => {
        html += '<tr>';
        html += `<td>${formatDateTime(log.started_at)}</td>`;

        let statusBadge = 'badge-info';
        if (log.status === 'completed') statusBadge = 'badge-success';
        if (log.status === 'failed') statusBadge = 'badge-danger';
        if (log.status === 'running') statusBadge = 'badge-warning';

        html += `<td><span class="badge ${statusBadge}">${log.status}</span></td>`;
        html += `<td>${log.api_calls_made || 0}</td>`;
        html += `<td>$${(log.api_cost_estimate || 0).toFixed(4)}</td>`;

        if (log.completed_at) {
            const duration = (new Date(log.completed_at) - new Date(log.started_at)) / 1000;
            html += `<td>${duration.toFixed(1)}s</td>`;
        } else {
            html += '<td>—</td>';
        }

        html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

/**
 * Settings Tab
 */
function loadSettings() {
    ajax('get_settings', {}, (response) => {
        if (response.success) {
            const settings = response.data;
            document.getElementById('settings-check-dates').value = settings.check_dates || '1,7,14,21';
            document.getElementById('settings-months-ahead').value = settings.months_ahead || '6';
            document.getElementById('settings-search-keyword').value = settings.search_keyword || '';
            document.getElementById('settings-location').value = settings.location_name || '';
            document.getElementById('settings-adults').value = settings.default_adults || '2';
        }
    });
}

/**
 * Modal Management
 */
function initializeModals() {
    const modals = document.querySelectorAll('.modal');

    modals.forEach(modal => {
        const closeButtons = modal.querySelectorAll('.modal-close');

        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                modal.classList.remove('active');
            });
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
}

function showModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function hideModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

/**
 * Event Listeners
 */
function initializeEventListeners() {
    // Add Competitor
    document.getElementById('btn-add-competitor')?.addEventListener('click', () => {
        showModal('add-competitor-modal');
    });

    document.getElementById('btn-save-competitor')?.addEventListener('click', () => {
        const name = document.getElementById('competitor-name').value.trim();

        if (!name) {
            showNotification('Please enter a property name', 'error');
            return;
        }

        ajax('add_competitor', { name: name }, (response) => {
            if (response.success) {
                hideModal('add-competitor-modal');
                document.getElementById('competitor-name').value = '';
                loadCompetitors();
                showNotification('Competitor added successfully', 'success');
            } else {
                showNotification(response.error, 'error');
            }
        });
    });

    // Load Prices
    document.getElementById('btn-load-prices')?.addEventListener('click', () => {
        loadPriceMatrix();
    });

    // Run Audit
    document.getElementById('btn-run-audit')?.addEventListener('click', () => {
        runManualAudit();
    });

    // Test API
    document.getElementById('btn-test-api')?.addEventListener('click', () => {
        testApiConnection();
    });

    // Settings Form
    document.getElementById('settings-form')?.addEventListener('submit', (e) => {
        e.preventDefault();
        saveSettings();
    });
}

/**
 * Manual Audit
 */
function runManualAudit() {
    const checkIn = document.getElementById('audit-check-in').value;
    const checkOut = document.getElementById('audit-check-out').value;

    const progressEl = document.getElementById('audit-progress');
    const resultsEl = document.getElementById('audit-results');

    progressEl.style.display = 'block';
    resultsEl.style.display = 'none';

    ajax('run_manual_audit', { check_in: checkIn, check_out: checkOut }, (response) => {
        progressEl.style.display = 'none';

        if (response.success) {
            resultsEl.style.display = 'block';
            resultsEl.innerHTML = `
                <h4>Audit Complete</h4>
                <ul>
                    <li><strong>Snapshots Collected:</strong> ${response.snapshots}</li>
                    <li><strong>API Calls:</strong> ${response.api_calls}</li>
                    <li><strong>Estimated Cost:</strong> $${response.cost.toFixed(4)}</li>
                </ul>
            `;

            if (response.errors && response.errors.length > 0) {
                resultsEl.innerHTML += '<p><strong>Errors:</strong></p><ul>';
                response.errors.forEach(error => {
                    resultsEl.innerHTML += `<li>${escapeHtml(error)}</li>`;
                });
                resultsEl.innerHTML += '</ul>';
            }

            loadCronLogs();
        } else {
            showNotification('Audit failed: ' + response.error, 'error');
        }
    });
}

/**
 * Test API Connection
 */
function testApiConnection() {
    const resultEl = document.getElementById('api-test-result');
    resultEl.innerHTML = '<p>Testing connection...</p>';

    ajax('test_api', {}, (response) => {
        if (response.success) {
            resultEl.innerHTML = `<p class="badge badge-success">${response.message}</p>`;
        } else {
            resultEl.innerHTML = `<p class="badge badge-danger">${response.message || response.error}</p>`;
        }
    });
}

/**
 * Save Settings
 */
function saveSettings() {
    const settings = {
        check_dates: document.getElementById('settings-check-dates').value,
        months_ahead: document.getElementById('settings-months-ahead').value,
        search_keyword: document.getElementById('settings-search-keyword').value,
        location_name: document.getElementById('settings-location').value,
        default_adults: document.getElementById('settings-adults').value
    };

    ajax('update_settings', settings, (response) => {
        if (response.success) {
            showNotification('Settings saved successfully', 'success');
        } else {
            showNotification(response.error, 'error');
        }
    });
}

/**
 * AJAX Helper
 */
function ajax(action, data, callback, method = 'POST') {
    const url = method === 'GET'
        ? `api/ajax-handlers.php?action=${action}&${new URLSearchParams(data).toString()}`
        : `api/ajax-handlers.php?action=${action}`;

    const options = {
        method: method,
        headers: {
            'X-CSRF-Token': window.CSRF_TOKEN
        }
    };

    if (method === 'POST') {
        const formData = new FormData();
        formData.append('csrf_token', window.CSRF_TOKEN);
        for (let key in data) {
            formData.append(key, data[key]);
        }
        options.body = formData;
    }

    fetch(url, options)
        .then(response => response.json())
        .then(callback)
        .catch(error => {
            console.error('AJAX Error:', error);
            showNotification('Network error occurred', 'error');
        });
}

/**
 * Utility Functions
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-ZA', { dateStyle: 'short', timeStyle: 'short' });
}

function formatRelativeTime(date) {
    const now = new Date();
    const diff = now - date;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) return `${days}d ago`;
    if (hours > 0) return `${hours}h ago`;
    if (minutes > 0) return `${minutes}m ago`;
    return 'Just now';
}

function formatCalendarDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' });
}

function showNotification(message, type) {
    alert(message);
}
