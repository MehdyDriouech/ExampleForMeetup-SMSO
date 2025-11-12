/**
 * Dashboard View - StudyMate School Orchestrator
 * Integration with ErgoMate learning platform
 * Sprint 3: Multi-tenant & RBAC
 */

/**
 * Initialize Dashboard View
 * Displays KPIs, charts, and ErgoMate sync status
 */
async function initDashboardView() {
    const content = document.getElementById('dashboard-content');

    if (!authToken || !currentUser) {
        content.innerHTML = '<p>Veuillez vous connecter pour accéder au tableau de bord</p>';
        return;
    }

    content.innerHTML = `
        <div class="dashboard-header">
            <h2>Tableau de bord - ${currentUser.firstname} ${currentUser.lastname}</h2>
            <p class="role-badge">Rôle: ${getRoleDisplayName(currentUser.role)}</p>
            <p class="tenant-info">Établissement: ${currentUser.tenantName || currentUser.tenantId}</p>
        </div>

        <div class="sync-status" id="ergomate-sync-status">
            <h3>📊 Statut ErgoMate</h3>
            <p>Chargement du statut de synchronisation...</p>
        </div>

        <div class="dashboard-kpis" id="dashboard-kpis">
            <p>Chargement des statistiques...</p>
        </div>

        <div class="dashboard-charts" id="dashboard-charts">
            <!-- Charts will be rendered here if Chart.js is available -->
        </div>

        <div class="recent-activity" id="recent-activity">
            <h3>📋 Activité récente</h3>
            <p>Chargement des activités...</p>
        </div>
    `;

    // Load dashboard data
    await Promise.all([
        loadDashboardKPIs(),
        loadErgoMateSyncStatus(),
        loadRecentActivity()
    ]);

    // Load charts if Chart.js is available
    if (typeof Chart !== 'undefined') {
        await renderDashboardCharts();
    }
}

/**
 * Load Dashboard KPIs
 */
async function loadDashboardKPIs() {
    const kpisContainer = document.getElementById('dashboard-kpis');

    try {
        const data = await apiCall('/api/dashboard/summary');

        // Build KPI cards based on user role
        let kpiCards = '';

        // Common KPIs for all roles
        kpiCards += `
            <div class="kpi-card">
                <div class="kpi-icon">👥</div>
                <div class="kpi-content">
                    <h4>Total Élèves</h4>
                    <p class="kpi-value">${data.kpis?.totalStudents || 0}</p>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">✅</div>
                <div class="kpi-content">
                    <h4>Élèves Actifs</h4>
                    <p class="kpi-value">${data.kpis?.activeStudents || 0}</p>
                    <p class="kpi-subtitle">sur ErgoMate</p>
                </div>
            </div>
        `;

        // Stats KPIs (if user has permission)
        if (canViewStats(currentUser.role)) {
            kpiCards += `
                <div class="kpi-card">
                    <div class="kpi-icon">📈</div>
                    <div class="kpi-content">
                        <h4>Score Moyen</h4>
                        <p class="kpi-value">${(data.kpis?.avgScore || 0).toFixed(1)}%</p>
                        <p class="kpi-subtitle">données ErgoMate</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">🎯</div>
                    <div class="kpi-content">
                        <h4>Maîtrise Moyenne</h4>
                        <p class="kpi-value">${((data.kpis?.avgMastery || 0) * 100).toFixed(1)}%</p>
                        <p class="kpi-subtitle">niveau atteint</p>
                    </div>
                </div>
            `;
        }

        // Assignment KPIs for teachers
        if (currentUser.role === 'teacher' || currentUser.role === 'admin' || currentUser.role === 'direction') {
            kpiCards += `
                <div class="kpi-card">
                    <div class="kpi-icon">📝</div>
                    <div class="kpi-content">
                        <h4>Affectations</h4>
                        <p class="kpi-value">${data.kpis?.totalAssignments || 0}</p>
                        <p class="kpi-subtitle">${data.kpis?.activeAssignments || 0} actives</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">🔄</div>
                    <div class="kpi-content">
                        <h4>En attente</h4>
                        <p class="kpi-value">${data.kpis?.pendingAssignments || 0}</p>
                        <p class="kpi-subtitle">push vers ErgoMate</p>
                    </div>
                </div>
            `;
        }

        kpisContainer.innerHTML = `<div class="kpi-grid">${kpiCards}</div>`;

    } catch (error) {
        kpisContainer.innerHTML = `
            <div class="error-message">
                <p>❌ Erreur lors du chargement des statistiques</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Load ErgoMate synchronization status
 */
async function loadErgoMateSyncStatus() {
    const statusContainer = document.getElementById('ergomate-sync-status');

    try {
        const syncData = await apiCall('/api/sync/status');

        const lastSync = syncData.lastSync ? new Date(syncData.lastSync).toLocaleString('fr-FR') : 'Jamais';
        const nextSync = syncData.nextSync ? new Date(syncData.nextSync).toLocaleString('fr-FR') : 'Non planifié';

        statusContainer.innerHTML = `
            <h3>📊 Synchronisation ErgoMate</h3>
            <div class="sync-info">
                <div class="sync-item">
                    <strong>Dernière sync:</strong> ${lastSync}
                </div>
                <div class="sync-item">
                    <strong>Prochaine sync:</strong> ${nextSync}
                </div>
                <div class="sync-item">
                    <strong>Statut:</strong>
                    <span class="status-badge ${syncData.status || 'unknown'}">${syncData.status || 'inconnu'}</span>
                </div>
                <div class="sync-item">
                    <strong>Stats synchronisées:</strong> ${syncData.syncedStats || 0}
                </div>
            </div>
            ${canTriggerSync(currentUser.role) ? `
                <button onclick="triggerManualSync()" class="btn-sync">
                    🔄 Synchroniser maintenant
                </button>
            ` : ''}
            <p class="sync-note">
                ℹ️ Les données sont synchronisées automatiquement depuis ErgoMate toutes les 15-30 secondes.
                En cas d'indisponibilité WebSocket, un fallback polling est activé.
            </p>
        `;

    } catch (error) {
        statusContainer.innerHTML = `
            <h3>📊 Synchronisation ErgoMate</h3>
            <div class="error-message">
                <p>⚠️ Impossible de récupérer le statut de synchronisation</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Load recent activity
 */
async function loadRecentActivity() {
    const activityContainer = document.getElementById('recent-activity');

    try {
        const activities = await apiCall('/api/sync/logs?limit=10');

        if (!activities.data || activities.data.length === 0) {
            activityContainer.innerHTML = `
                <h3>📋 Activité récente</h3>
                <p class="empty-message">Aucune activité récente</p>
            `;
            return;
        }

        const activityList = activities.data.map(activity => {
            const timestamp = new Date(activity.created_at).toLocaleString('fr-FR');
            const icon = getActivityIcon(activity.type, activity.direction);
            const statusClass = activity.status === 'ok' ? 'success' : activity.status === 'error' ? 'error' : 'pending';

            return `
                <div class="activity-item ${statusClass}">
                    <span class="activity-icon">${icon}</span>
                    <div class="activity-details">
                        <p class="activity-title">${formatActivityType(activity.type, activity.direction)}</p>
                        <p class="activity-time">${timestamp}</p>
                        ${activity.error_message ? `<p class="activity-error">${activity.error_message}</p>` : ''}
                    </div>
                    <span class="activity-status ${statusClass}">${activity.status}</span>
                </div>
            `;
        }).join('');

        activityContainer.innerHTML = `
            <h3>📋 Activité récente (ErgoMate)</h3>
            <div class="activity-list">${activityList}</div>
        `;

    } catch (error) {
        activityContainer.innerHTML = `
            <h3>📋 Activité récente</h3>
            <div class="error-message">
                <p>❌ Erreur lors du chargement des activités</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Render charts using Chart.js (if available)
 */
async function renderDashboardCharts() {
    const chartsContainer = document.getElementById('dashboard-charts');

    // Only render charts for roles with access to aggregated data
    if (!canViewAggregatedData(currentUser.role)) {
        chartsContainer.innerHTML = '';
        return;
    }

    try {
        const chartData = await apiCall('/api/dashboard/charts');

        chartsContainer.innerHTML = `
            <h3>📊 Graphiques (données ErgoMate)</h3>
            <div class="charts-grid">
                <div class="chart-container">
                    <canvas id="score-trend-chart"></canvas>
                </div>
                <div class="chart-container">
                    <canvas id="mastery-distribution-chart"></canvas>
                </div>
            </div>
        `;

        // Render score trend chart
        if (chartData.scoreTrend) {
            renderScoreTrendChart(chartData.scoreTrend);
        }

        // Render mastery distribution chart
        if (chartData.masteryDistribution) {
            renderMasteryDistributionChart(chartData.masteryDistribution);
        }

    } catch (error) {
        chartsContainer.innerHTML = `
            <h3>📊 Graphiques</h3>
            <div class="info-message">
                <p>ℹ️ Graphiques non disponibles</p>
                <p class="info-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Trigger manual sync with ErgoMate
 */
async function triggerManualSync() {
    if (!confirm('Déclencher une synchronisation manuelle avec ErgoMate ?')) {
        return;
    }

    const button = event.target;
    button.disabled = true;
    button.textContent = '⏳ Synchronisation en cours...';

    try {
        await apiCall('/api/sync/pull-stats', { method: 'POST' });
        alert('✅ Synchronisation déclenchée avec succès');

        // Reload dashboard data
        await loadDashboardKPIs();
        await loadErgoMateSyncStatus();
        await loadRecentActivity();

    } catch (error) {
        alert('❌ Erreur lors de la synchronisation: ' + error.message);
    } finally {
        button.disabled = false;
        button.textContent = '🔄 Synchroniser maintenant';
    }
}

/**
 * Helper: Get role display name
 */
function getRoleDisplayName(role) {
    const roleNames = {
        'admin': 'Administrateur',
        'direction': 'Direction',
        'teacher': 'Enseignant(e)',
        'inspector': 'Inspecteur',
        'intervenant': 'Intervenant'
    };
    return roleNames[role] || role;
}

/**
 * Helper: Check if user can view stats
 */
function canViewStats(role) {
    return ['admin', 'direction', 'teacher', 'inspector'].includes(role);
}

/**
 * Helper: Check if user can view aggregated data
 */
function canViewAggregatedData(role) {
    return ['admin', 'direction', 'inspector'].includes(role);
}

/**
 * Helper: Check if user can trigger sync
 */
function canTriggerSync(role) {
    return ['admin', 'direction', 'teacher'].includes(role);
}

/**
 * Helper: Get activity icon
 */
function getActivityIcon(type, direction) {
    if (direction === 'pull') {
        return '⬇️';
    } else if (direction === 'push') {
        return '⬆️';
    }
    return '🔄';
}

/**
 * Helper: Format activity type
 */
function formatActivityType(type, direction) {
    const types = {
        'stats': direction === 'pull' ? 'Import stats ErgoMate' : 'Export stats',
        'assignment': direction === 'push' ? 'Push affectation vers ErgoMate' : 'Pull affectation',
        'webhook': 'Webhook ErgoMate reçu'
    };
    return types[type] || `${type} (${direction})`;
}

/**
 * Render score trend chart (Chart.js)
 */
function renderScoreTrendChart(data) {
    const ctx = document.getElementById('score-trend-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Score moyen (%)',
                data: data.values,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Évolution du score moyen (ErgoMate)'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
}

/**
 * Render mastery distribution chart (Chart.js)
 */
function renderMasteryDistributionChart(data) {
    const ctx = document.getElementById('mastery-distribution-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Nombre d\'élèves',
                data: data.values,
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Répartition du niveau de maîtrise (ErgoMate)'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}
