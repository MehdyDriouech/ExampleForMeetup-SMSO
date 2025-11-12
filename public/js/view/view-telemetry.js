/**
 * Telemetry View - ErgoMate School Orchestrator
 * Sprint 4: API Rate Limiting & Telemetry
 */

/**
 * Initialize Telemetry View
 * Displays API performance metrics, error rates, and observability data
 */
async function initTelemetryView() {
    const content = document.getElementById('dashboard-content');

    if (!authToken || !currentUser) {
        content.innerHTML = '<p>Veuillez vous connecter pour accéder à cette page</p>';
        return;
    }

    // Check permissions (admin or direction only)
    if (!['admin', 'direction'].includes(currentUser.role)) {
        content.innerHTML = `
            <div class="error-message">
                <h2>⛔ Accès refusé</h2>
                <p>Vous n'avez pas les permissions nécessaires pour accéder à cette page.</p>
                <p>Rôle requis: Administrateur ou Direction</p>
            </div>
        `;
        return;
    }

    content.innerHTML = `
        <div class="telemetry-header">
            <h2>📊 Télémétrie API</h2>
            <p class="subtitle">Observabilité, performance et analyse des erreurs</p>
        </div>

        <div class="telemetry-tabs">
            <button class="tab-btn active" onclick="switchTelemetryView('overview')">📈 Vue d'ensemble</button>
            <button class="tab-btn" onclick="switchTelemetryView('endpoints')">📍 Endpoints</button>
            <button class="tab-btn" onclick="switchTelemetryView('errors')">⚠️ Erreurs</button>
            <button class="tab-btn" onclick="switchTelemetryView('performance')">⚡ Performance</button>
        </div>

        <div class="filters-section">
            <div class="filter-group">
                <label for="telemetry-date-range">Période:</label>
                <select id="telemetry-date-range" onchange="loadTelemetryData()">
                    <option value="1">Dernier jour</option>
                    <option value="7" selected>7 derniers jours</option>
                    <option value="30">30 derniers jours</option>
                </select>
            </div>
            <button onclick="loadTelemetryData()" class="btn-refresh">🔄 Actualiser</button>
        </div>

        <div id="telemetry-content">
            <p>Chargement des données de télémétrie...</p>
        </div>
    `;

    // Set current view
    window.currentTelemetryView = 'overview';

    // Load initial data
    await loadTelemetryData();
}

/**
 * Switch between telemetry views
 */
function switchTelemetryView(view) {
    window.currentTelemetryView = view;

    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');

    // Load data for the view
    loadTelemetryData();
}

/**
 * Load telemetry data based on current view
 */
async function loadTelemetryData() {
    const contentDiv = document.getElementById('telemetry-content');
    const view = window.currentTelemetryView || 'overview';

    try {
        // Get selected date range
        const rangeSelect = document.getElementById('telemetry-date-range');
        const days = rangeSelect ? parseInt(rangeSelect.value) : 7;

        const endDate = new Date().toISOString().split('T')[0];
        const startDate = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        // Fetch telemetry data
        const data = await apiCall(`/api/telemetry/stats?view=${view}&start_date=${startDate}&end_date=${endDate}`);

        // Render based on view
        switch (view) {
            case 'overview':
                renderOverview(data, contentDiv);
                break;
            case 'endpoints':
                renderEndpoints(data, contentDiv);
                break;
            case 'errors':
                renderErrors(data, contentDiv);
                break;
            case 'performance':
                renderPerformance(data, contentDiv);
                break;
        }

    } catch (error) {
        contentDiv.innerHTML = `
            <div class="error-message">
                <p>❌ Erreur lors du chargement des données</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Render overview telemetry
 */
function renderOverview(data, container) {
    const overview = data.overview || {};
    const successRate = overview.total_requests > 0
        ? ((overview.successful_requests || 0) / overview.total_requests * 100)
        : 0;
    const errorRate = overview.total_requests > 0
        ? (((overview.client_errors || 0) + (overview.server_errors || 0)) / overview.total_requests * 100)
        : 0;

    let html = `
        <div class="overview-cards">
            <div class="metric-card">
                <div class="metric-icon">📊</div>
                <div class="metric-content">
                    <h4>Total Requêtes</h4>
                    <p class="metric-value">${(overview.total_requests || 0).toLocaleString()}</p>
                    <p class="metric-subtitle">${overview.active_days || 0} jours actifs</p>
                </div>
            </div>

            <div class="metric-card ${successRate >= 99 ? 'success' : successRate >= 95 ? 'warning' : 'error'}">
                <div class="metric-icon">✅</div>
                <div class="metric-content">
                    <h4>Taux de Succès</h4>
                    <p class="metric-value">${successRate.toFixed(2)}%</p>
                    <p class="metric-subtitle">${(overview.successful_requests || 0).toLocaleString()} succès</p>
                </div>
            </div>

            <div class="metric-card ${errorRate < 1 ? 'success' : errorRate < 5 ? 'warning' : 'error'}">
                <div class="metric-icon">⚠️</div>
                <div class="metric-content">
                    <h4>Taux d'Erreur</h4>
                    <p class="metric-value">${errorRate.toFixed(2)}%</p>
                    <p class="metric-subtitle">
                        ${(overview.client_errors || 0).toLocaleString()} 4xx,
                        ${(overview.server_errors || 0).toLocaleString()} 5xx
                    </p>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon">⚡</div>
                <div class="metric-content">
                    <h4>Latence Moyenne</h4>
                    <p class="metric-value">${overview.avg_duration_ms ? overview.avg_duration_ms.toFixed(0) : '0'}ms</p>
                    <p class="metric-subtitle">Max: ${overview.max_duration_ms ? overview.max_duration_ms.toFixed(0) : '0'}ms</p>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon">💾</div>
                <div class="metric-content">
                    <h4>Requêtes DB</h4>
                    <p class="metric-value">${overview.avg_db_queries ? overview.avg_db_queries.toFixed(1) : '0'}</p>
                    <p class="metric-subtitle">${overview.avg_db_time_ms ? overview.avg_db_time_ms.toFixed(0) : '0'}ms moy</p>
                </div>
            </div>
        </div>
    `;

    // Daily trend chart (if Chart.js available)
    if (typeof Chart !== 'undefined' && data.daily_stats && data.daily_stats.length > 0) {
        html += `
            <div class="chart-section">
                <h3>📈 Évolution quotidienne</h3>
                <canvas id="daily-trend-chart" width="400" height="150"></canvas>
            </div>
        `;
    }

    // Hourly distribution (last 24h)
    if (data.hourly_stats && data.hourly_stats.length > 0) {
        html += `
            <div class="chart-section">
                <h3>🕐 Distribution horaire (24h)</h3>
                <canvas id="hourly-chart" width="400" height="150"></canvas>
            </div>
        `;
    }

    container.innerHTML = html;

    // Render charts
    if (typeof Chart !== 'undefined') {
        if (data.daily_stats && data.daily_stats.length > 0) {
            renderDailyTrendChart(data.daily_stats);
        }
        if (data.hourly_stats && data.hourly_stats.length > 0) {
            renderHourlyChart(data.hourly_stats);
        }
    }
}

/**
 * Render endpoints telemetry
 */
function renderEndpoints(data, container) {
    const endpoints = data.endpoints || [];

    if (endpoints.length === 0) {
        container.innerHTML = '<p class="empty-message">Aucune donnée d\'endpoint disponible</p>';
        return;
    }

    const endpointRows = endpoints.map(endpoint => {
        const errorRate = endpoint.total_requests > 0
            ? (endpoint.failed_requests / endpoint.total_requests * 100)
            : 0;
        const errorClass = errorRate < 1 ? 'success' : errorRate < 5 ? 'warning' : 'error';

        return `
            <tr>
                <td class="endpoint-path">${escapeHtml(endpoint.endpoint)}</td>
                <td class="text-center">${endpoint.total_requests.toLocaleString()}</td>
                <td class="text-center ${errorClass}">${errorRate.toFixed(2)}%</td>
                <td class="text-center">${endpoint.avg_duration_ms ? endpoint.avg_duration_ms.toFixed(0) : '0'}ms</td>
                <td class="text-center">${endpoint.max_duration_ms ? endpoint.max_duration_ms.toFixed(0) : '0'}ms</td>
                <td class="text-center">${endpoint.avg_db_queries ? endpoint.avg_db_queries.toFixed(1) : '0'}</td>
            </tr>
        `;
    }).join('');

    container.innerHTML = `
        <div class="endpoints-section">
            <h3>📍 Statistiques par Endpoint</h3>
            <div class="table-responsive">
                <table class="telemetry-table">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th class="text-center">Requêtes</th>
                            <th class="text-center">Taux d'Erreur</th>
                            <th class="text-center">Latence Moy</th>
                            <th class="text-center">Latence Max</th>
                            <th class="text-center">DB Queries</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${endpointRows}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

/**
 * Render errors telemetry
 */
function renderErrors(data, container) {
    const errorGroups = data.error_groups || [];
    const recentErrors = data.recent_errors || [];

    let html = '<div class="errors-section">';

    // Error groups
    if (errorGroups.length > 0) {
        html += `
            <h3>⚠️ Groupes d'Erreurs</h3>
            <div class="table-responsive">
                <table class="telemetry-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Code Erreur</th>
                            <th>Endpoint</th>
                            <th class="text-center">Occurrences</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${errorGroups.map(group => `
                            <tr>
                                <td><span class="status-badge error">${group.status_code}</span></td>
                                <td>${escapeHtml(group.error_code || 'N/A')}</td>
                                <td class="endpoint-path">${escapeHtml(group.endpoint)}</td>
                                <td class="text-center"><strong>${group.occurrences}</strong></td>
                                <td class="error-msg">${escapeHtml(group.error_message || 'N/A').substring(0, 100)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // Recent errors
    if (recentErrors.length > 0) {
        html += `
            <h3>🔴 Erreurs Récentes</h3>
            <div class="recent-errors-list">
                ${recentErrors.map(error => `
                    <div class="error-card">
                        <div class="error-header">
                            <span class="status-badge error">${error.status_code}</span>
                            <span class="error-method">${escapeHtml(error.method)}</span>
                            <span class="error-endpoint">${escapeHtml(error.endpoint)}</span>
                            <span class="error-time">${formatDateTime(error.created_at)}</span>
                        </div>
                        <div class="error-body">
                            <p><strong>Request ID:</strong> ${escapeHtml(error.request_id)}</p>
                            ${error.error_code ? `<p><strong>Code:</strong> ${escapeHtml(error.error_code)}</p>` : ''}
                            ${error.error_message ? `<p><strong>Message:</strong> ${escapeHtml(error.error_message)}</p>` : ''}
                            <p><strong>IP:</strong> ${escapeHtml(error.ip_address || 'N/A')}</p>
                            <p><strong>Duration:</strong> ${error.duration_ms ? error.duration_ms.toFixed(0) : '0'}ms</p>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    if (errorGroups.length === 0 && recentErrors.length === 0) {
        html += '<p class="empty-message success">✅ Aucune erreur dans la période sélectionnée</p>';
    }

    html += '</div>';
    container.innerHTML = html;
}

/**
 * Render performance telemetry
 */
function renderPerformance(data, container) {
    const slowQueries = data.slow_queries || [];
    const endpointPerf = data.endpoint_performance || [];

    let html = '<div class="performance-section">';

    // Slow queries
    if (slowQueries.length > 0) {
        html += `
            <h3>🐌 Requêtes Lentes (> 1s)</h3>
            <div class="table-responsive">
                <table class="telemetry-table">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Endpoint</th>
                            <th>Method</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Duration</th>
                            <th class="text-center">DB Queries</th>
                            <th class="text-center">DB Time</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${slowQueries.map(query => `
                            <tr>
                                <td class="request-id">${escapeHtml(query.request_id).substring(0, 8)}...</td>
                                <td class="endpoint-path">${escapeHtml(query.endpoint)}</td>
                                <td>${escapeHtml(query.method)}</td>
                                <td class="text-center"><span class="status-badge ${query.status_code >= 400 ? 'error' : 'success'}">${query.status_code}</span></td>
                                <td class="text-center"><strong>${query.duration_ms.toFixed(0)}ms</strong></td>
                                <td class="text-center">${query.db_queries}</td>
                                <td class="text-center">${query.db_time_ms ? query.db_time_ms.toFixed(0) : '0'}ms</td>
                                <td>${formatDateTime(query.created_at)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // Endpoint performance ranking
    if (endpointPerf.length > 0) {
        html += `
            <h3>⚡ Performance par Endpoint</h3>
            <div class="table-responsive">
                <table class="telemetry-table">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th class="text-center">Requêtes</th>
                            <th class="text-center">Moy</th>
                            <th class="text-center">Max</th>
                            <th class="text-center">DB Queries</th>
                            <th class="text-center">DB Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${endpointPerf.map(perf => {
                            const perfClass = perf.avg_duration_ms < 100 ? 'success' : perf.avg_duration_ms < 500 ? 'warning' : 'error';
                            return `
                                <tr>
                                    <td class="endpoint-path">${escapeHtml(perf.endpoint)}</td>
                                    <td class="text-center">${perf.total_requests.toLocaleString()}</td>
                                    <td class="text-center ${perfClass}">${perf.avg_duration_ms ? perf.avg_duration_ms.toFixed(0) : '0'}ms</td>
                                    <td class="text-center">${perf.max_duration_ms ? perf.max_duration_ms.toFixed(0) : '0'}ms</td>
                                    <td class="text-center">${perf.avg_db_queries ? perf.avg_db_queries.toFixed(1) : '0'}</td>
                                    <td class="text-center">${perf.avg_db_time_ms ? perf.avg_db_time_ms.toFixed(0) : '0'}ms</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    if (slowQueries.length === 0 && endpointPerf.length === 0) {
        html += '<p class="empty-message">Aucune donnée de performance disponible</p>';
    }

    html += '</div>';
    container.innerHTML = html;
}

/**
 * Render daily trend chart
 */
function renderDailyTrendChart(dailyStats) {
    const ctx = document.getElementById('daily-trend-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: dailyStats.map(d => d.date),
            datasets: [
                {
                    label: 'Total Requêtes',
                    data: dailyStats.map(d => d.requests),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y'
                },
                {
                    label: 'Erreurs',
                    data: dailyStats.map(d => d.errors),
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y'
                },
                {
                    label: 'Latence Moyenne (ms)',
                    data: dailyStats.map(d => d.avg_duration_ms),
                    borderColor: 'rgb(255, 206, 86)',
                    backgroundColor: 'rgba(255, 206, 86, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Nombre de requêtes'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Latence (ms)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
}

/**
 * Render hourly distribution chart
 */
function renderHourlyChart(hourlyStats) {
    const ctx = document.getElementById('hourly-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hourlyStats.map(h => `${h.hour}h`),
            datasets: [{
                label: 'Requêtes',
                data: hourlyStats.map(h => h.requests),
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Nombre de requêtes'
                    }
                }
            }
        }
    });
}

/**
 * Helper: Format date/time
 */
function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleString('fr-FR');
}

/**
 * Helper: Escape HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
