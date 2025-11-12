/**
 * Partners Usage View - ErgoMate School Orchestrator
 * Sprint 4: API Rate Limiting & Telemetry
 */

/**
 * Initialize Partners Usage View
 * Displays API key usage, rate limits, and partner statistics
 */
async function initPartnersView() {
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
        <div class="partners-header">
            <h2>👥 Gestion des Partenaires API</h2>
            <p class="subtitle">Surveillance des clés API, quotas et rate limiting</p>
        </div>

        <div class="filters-section">
            <div class="filter-group">
                <label for="date-range">Période:</label>
                <select id="date-range" onchange="loadPartnersUsage()">
                    <option value="7">7 derniers jours</option>
                    <option value="30" selected>30 derniers jours</option>
                    <option value="90">90 derniers jours</option>
                </select>
            </div>
            <button onclick="loadPartnersUsage()" class="btn-refresh">🔄 Actualiser</button>
        </div>

        <div class="summary-cards" id="partners-summary">
            <p>Chargement des statistiques...</p>
        </div>

        <div class="api-keys-list" id="api-keys-list">
            <h3>📋 Clés API</h3>
            <p>Chargement des clés API...</p>
        </div>
    `;

    // Load data
    await loadPartnersUsage();
}

/**
 * Load partners usage data
 */
async function loadPartnersUsage() {
    const summaryContainer = document.getElementById('partners-summary');
    const keysContainer = document.getElementById('api-keys-list');

    try {
        // Get selected date range
        const rangeSelect = document.getElementById('date-range');
        const days = rangeSelect ? parseInt(rangeSelect.value) : 30;

        const endDate = new Date().toISOString().split('T')[0];
        const startDate = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        // Fetch usage data
        const data = await apiCall(`/api/partners/usage?start_date=${startDate}&end_date=${endDate}`);

        // Render summary
        renderPartnersSummary(data.summary, summaryContainer);

        // Render API keys list
        renderApiKeysList(data.api_keys, keysContainer);

    } catch (error) {
        summaryContainer.innerHTML = `
            <div class="error-message">
                <p>❌ Erreur lors du chargement des données</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
        keysContainer.innerHTML = '';
    }
}

/**
 * Render partners summary cards
 */
function renderPartnersSummary(summary, container) {
    const successRate = summary.success_rate || 0;
    const statusClass = successRate >= 95 ? 'success' : successRate >= 90 ? 'warning' : 'error';

    container.innerHTML = `
        <div class="summary-card">
            <div class="card-icon">🔑</div>
            <div class="card-content">
                <h4>Total Clés API</h4>
                <p class="card-value">${summary.total_api_keys || 0}</p>
                <p class="card-subtitle">${summary.active_api_keys || 0} actives</p>
            </div>
        </div>

        <div class="summary-card">
            <div class="card-icon">📊</div>
            <div class="card-content">
                <h4>Total Requêtes</h4>
                <p class="card-value">${(summary.total_requests || 0).toLocaleString()}</p>
                <p class="card-subtitle">sur la période</p>
            </div>
        </div>

        <div class="summary-card ${statusClass}">
            <div class="card-icon">✅</div>
            <div class="card-content">
                <h4>Taux de Succès</h4>
                <p class="card-value">${successRate.toFixed(1)}%</p>
                <p class="card-subtitle">${summary.successful_requests || 0} / ${summary.total_requests || 0}</p>
            </div>
        </div>

        <div class="summary-card">
            <div class="card-icon">⚡</div>
            <div class="card-content">
                <h4>Latence Moyenne</h4>
                <p class="card-value">${summary.avg_duration_ms ? summary.avg_duration_ms.toFixed(0) : 'N/A'}</p>
                <p class="card-subtitle">milliseconds</p>
            </div>
        </div>
    `;
}

/**
 * Render API keys list with details
 */
function renderApiKeysList(apiKeys, container) {
    if (!apiKeys || apiKeys.length === 0) {
        container.innerHTML = `
            <h3>📋 Clés API</h3>
            <p class="empty-message">Aucune clé API trouvée</p>
        `;
        return;
    }

    const keyCards = apiKeys.map(key => renderApiKeyCard(key)).join('');

    container.innerHTML = `
        <h3>📋 Clés API (${apiKeys.length})</h3>
        <div class="api-keys-grid">
            ${keyCards}
        </div>
    `;
}

/**
 * Render individual API key card
 */
function renderApiKeyCard(key) {
    const statusBadge = getStatusBadge(key.status);
    const successRate = key.usage.success_rate || 0;
    const successClass = successRate >= 95 ? 'success' : successRate >= 90 ? 'warning' : 'error';

    // Rate limit indicators
    const rateLimitDay = key.rate_limit_status?.remaining?.day || 0;
    const rateLimitHour = key.rate_limit_status?.remaining?.hour || 0;
    const rateLimitMinute = key.rate_limit_status?.remaining?.minute || 0;

    const dayUsage = key.quotas.daily > 0 ? ((key.quotas.daily - rateLimitDay) / key.quotas.daily * 100) : 0;
    const hourUsage = key.quotas.per_hour > 0 ? ((key.quotas.per_hour - rateLimitHour) / key.quotas.per_hour * 100) : 0;

    return `
        <div class="api-key-card">
            <div class="key-header">
                <div>
                    <h4>${escapeHtml(key.owner)}</h4>
                    <p class="key-id">${escapeHtml(key.id)}</p>
                </div>
                ${statusBadge}
            </div>

            <div class="key-section">
                <h5>📊 Usage (période sélectionnée)</h5>
                <div class="usage-stats">
                    <div class="stat-item">
                        <span class="stat-label">Total requêtes:</span>
                        <span class="stat-value">${key.usage.total_requests.toLocaleString()}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Taux de succès:</span>
                        <span class="stat-value ${successClass}">${successRate.toFixed(1)}%</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Latence moyenne:</span>
                        <span class="stat-value">${key.usage.avg_duration_ms ? key.usage.avg_duration_ms.toFixed(0) + 'ms' : 'N/A'}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Dernière utilisation:</span>
                        <span class="stat-value">${formatDateTime(key.last_used_at)}</span>
                    </div>
                </div>
            </div>

            <div class="key-section">
                <h5>🚦 Rate Limits</h5>
                <div class="rate-limits">
                    <div class="rate-limit-item">
                        <span class="limit-label">Quotidien:</span>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${Math.min(dayUsage, 100)}%"></div>
                        </div>
                        <span class="limit-value">${rateLimitDay.toLocaleString()} / ${key.quotas.daily.toLocaleString()}</span>
                    </div>
                    <div class="rate-limit-item">
                        <span class="limit-label">Horaire:</span>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${Math.min(hourUsage, 100)}%"></div>
                        </div>
                        <span class="limit-value">${rateLimitHour.toLocaleString()} / ${key.quotas.per_hour.toLocaleString()}</span>
                    </div>
                    <div class="rate-limit-item">
                        <span class="limit-label">Par minute:</span>
                        <span class="limit-value">${rateLimitMinute} / ${key.quotas.per_minute}</span>
                    </div>
                </div>
            </div>

            <div class="key-section">
                <h5>🔐 Scopes</h5>
                <div class="scopes-list">
                    ${key.scopes.map(scope => `<span class="scope-badge">${escapeHtml(scope)}</span>`).join('')}
                </div>
            </div>

            ${key.top_endpoints && key.top_endpoints.length > 0 ? `
                <div class="key-section">
                    <h5>📍 Top Endpoints</h5>
                    <div class="endpoints-list">
                        ${key.top_endpoints.slice(0, 5).map(endpoint => `
                            <div class="endpoint-item">
                                <span class="endpoint-path">${escapeHtml(endpoint.endpoint)}</span>
                                <span class="endpoint-count">${endpoint.requests} req</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : ''}

            ${key.recent_errors && key.recent_errors.length > 0 ? `
                <div class="key-section errors">
                    <h5>⚠️ Erreurs Récentes (${key.recent_errors.length})</h5>
                    <button onclick="toggleErrors('${key.id}')" class="btn-toggle">
                        Voir les erreurs
                    </button>
                    <div id="errors-${key.id}" class="errors-list" style="display: none;">
                        ${key.recent_errors.slice(0, 5).map(error => `
                            <div class="error-item">
                                <span class="error-code">${error.status_code}</span>
                                <span class="error-endpoint">${escapeHtml(error.endpoint)}</span>
                                <span class="error-time">${formatDateTime(error.created_at)}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            ` : ''}

            <div class="key-actions">
                <button onclick="viewApiKeyDetails('${key.id}')" class="btn-details">
                    📊 Détails complets
                </button>
            </div>
        </div>
    `;
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status) {
    const badges = {
        'active': '<span class="status-badge success">✅ Active</span>',
        'suspended': '<span class="status-badge warning">⏸️ Suspendue</span>',
        'revoked': '<span class="status-badge error">⛔ Révoquée</span>'
    };
    return badges[status] || '<span class="status-badge">❓ Inconnu</span>';
}

/**
 * Toggle errors visibility
 */
function toggleErrors(keyId) {
    const errorsDiv = document.getElementById(`errors-${keyId}`);
    if (errorsDiv) {
        errorsDiv.style.display = errorsDiv.style.display === 'none' ? 'block' : 'none';
    }
}

/**
 * View API key details (could open modal or navigate to detail page)
 */
function viewApiKeyDetails(keyId) {
    alert(`Voir les détails de la clé: ${keyId}\n\nCette fonctionnalité ouvrira une vue détaillée avec graphiques et historique.`);
    // TODO: Implement detailed view with charts
}

/**
 * Helper: Format date/time
 */
function formatDateTime(dateStr) {
    if (!dateStr) return 'Jamais';
    const date = new Date(dateStr);
    return date.toLocaleString('fr-FR');
}

/**
 * Helper: Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
