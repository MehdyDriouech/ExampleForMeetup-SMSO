/**
 * Student Progress View - Sprint 5: E5-PROGRESS
 * Displays student progress dashboard with scores, time, and radar charts
 */

/**
 * Initialize Student Progress View
 */
async function initStudentProgressView(studentId) {
    const content = document.getElementById('student-progress-content');

    if (!studentId) {
        content.innerHTML = '<p>⚠️ ID élève requis</p>';
        return;
    }

    content.innerHTML = `
        <div class="progress-header">
            <h2>📊 Ma Progression</h2>
            <div class="progress-controls">
                <button onclick="refreshProgress('${studentId}')" class="btn-refresh">
                    🔄 Actualiser
                </button>
                <button onclick="viewBadges('${studentId}')" class="btn-badges">
                    🏆 Mes Badges
                </button>
            </div>
        </div>

        <div id="progress-student-info" class="student-info">
            <p>Chargement des informations...</p>
        </div>

        <div id="progress-kpis" class="progress-kpis">
            <p>Chargement des statistiques...</p>
        </div>

        <div id="progress-charts" class="progress-charts">
            <p>Chargement des graphiques...</p>
        </div>

        <div id="progress-analysis" class="progress-analysis">
            <h3>🎯 Analyse</h3>
            <p>Chargement de l'analyse...</p>
        </div>

        <div id="progress-activity" class="progress-activity">
            <h3>📋 Activité récente</h3>
            <p>Chargement de l'activité...</p>
        </div>
    `;

    await loadStudentProgress(studentId);
}

/**
 * Load student progress from API
 */
async function loadStudentProgress(studentId) {
    const studentInfo = document.getElementById('progress-student-info');
    const kpis = document.getElementById('progress-kpis');
    const charts = document.getElementById('progress-charts');
    const analysis = document.getElementById('progress-analysis');
    const activity = document.getElementById('progress-activity');

    try {
        const data = await apiCall(`/api/student/${studentId}/progress`);

        // Store data globally
        window.currentProgressData = data;
        window.currentStudentId = studentId;

        // Display student info
        studentInfo.innerHTML = `
            <div class="student-card">
                <div class="student-avatar">👤</div>
                <div class="student-details">
                    <h3>${data.student.firstname} ${data.student.lastname}</h3>
                    <p>${data.student.email_scolaire}</p>
                    <p><strong>Classe:</strong> ${data.student.class_name}</p>
                </div>
            </div>
        `;

        // Display KPIs
        kpis.innerHTML = `
            <div class="kpis-grid">
                <div class="kpi-card">
                    <div class="kpi-icon">📝</div>
                    <div class="kpi-content">
                        <h4>Sessions</h4>
                        <p class="kpi-value">${data.kpis.total_sessions}</p>
                        <p class="kpi-subtitle">${data.kpis.sessions_last_7_days} cette semaine</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">✅</div>
                    <div class="kpi-content">
                        <h4>Missions complétées</h4>
                        <p class="kpi-value">${data.kpis.completed_assignments}</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">📈</div>
                    <div class="kpi-content">
                        <h4>Score moyen</h4>
                        <p class="kpi-value">${data.kpis.avg_score}%</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">🎯</div>
                    <div class="kpi-content">
                        <h4>Maîtrise globale</h4>
                        <p class="kpi-value">${data.kpis.overall_mastery}%</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">⏱️</div>
                    <div class="kpi-content">
                        <h4>Temps total</h4>
                        <p class="kpi-value">${formatTimeHours(data.kpis.total_time_spent)}</p>
                    </div>
                </div>
            </div>
        `;

        // Display charts
        renderCharts(data.charts);

        // Display analysis
        renderAnalysis(data.analysis);

        // Display recent activity
        renderRecentActivity(data.recent_activity);

    } catch (error) {
        kpis.innerHTML = `
            <div class="error-message">
                <p>❌ Erreur lors du chargement de la progression</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Format time in seconds to hours and minutes
 */
function formatTimeHours(seconds) {
    if (!seconds) return '0h';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else {
        return `${minutes}m`;
    }
}

/**
 * Render charts using Chart.js
 */
function renderCharts(chartsData) {
    const chartsContainer = document.getElementById('progress-charts');

    if (typeof Chart === 'undefined') {
        chartsContainer.innerHTML = `
            <div class="info-message">
                <p>ℹ️ Chart.js non disponible. Installez Chart.js pour voir les graphiques.</p>
            </div>
        `;
        return;
    }

    chartsContainer.innerHTML = `
        <div class="charts-grid">
            <div class="chart-wrapper">
                <h4>📈 Évolution du Score</h4>
                <canvas id="score-trend-chart"></canvas>
            </div>
            <div class="chart-wrapper">
                <h4>⏱️ Temps par Thème</h4>
                <canvas id="time-by-theme-chart"></canvas>
            </div>
            <div class="chart-wrapper full-width">
                <h4>🎯 Radar de Maîtrise</h4>
                <canvas id="mastery-radar-chart"></canvas>
            </div>
        </div>
    `;

    // Score trend chart
    renderScoreTrendChart(chartsData.score_trend);

    // Time by theme chart
    renderTimeByThemeChart(chartsData.time_by_theme);

    // Mastery radar chart
    renderMasteryRadarChart(chartsData.mastery_radar);
}

/**
 * Render score trend line chart
 */
function renderScoreTrendChart(data) {
    const ctx = document.getElementById('score-trend-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Score (%)',
                data: data.values,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render time by theme bar chart
 */
function renderTimeByThemeChart(data) {
    const ctx = document.getElementById('time-by-theme-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Temps (min)',
                data: data.values.map(v => Math.round(v / 60)), // Convert to minutes
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + ' min';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render mastery radar chart
 */
function renderMasteryRadarChart(data) {
    const ctx = document.getElementById('mastery-radar-chart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Niveau de maîtrise (%)',
                data: data.values,
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 2,
                pointBackgroundColor: 'rgba(255, 99, 132, 1)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgba(255, 99, 132, 1)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

/**
 * Render analysis (strengths and weaknesses)
 */
function renderAnalysis(analysisData) {
    const analysisContainer = document.getElementById('progress-analysis');

    const strengthsHtml = analysisData.strengths.map(s => `
        <div class="analysis-item strength">
            <div class="item-icon">💪</div>
            <div class="item-content">
                <h4>${s.theme_title}</h4>
                <p>Maîtrise: ${Math.round(s.mastery * 100)}% | Score: ${s.score}%</p>
                <p class="item-subtitle">${s.attempts} tentative(s)</p>
            </div>
        </div>
    `).join('');

    const weaknessesHtml = analysisData.weaknesses.map(w => `
        <div class="analysis-item weakness">
            <div class="item-icon">📚</div>
            <div class="item-content">
                <h4>${w.theme_title}</h4>
                <p>Maîtrise: ${Math.round(w.mastery * 100)}% | Score: ${w.score}%</p>
                <p class="item-subtitle">${w.attempts} tentative(s)</p>
                <button onclick="suggestReview('${w.theme_title}')" class="btn-sm">
                    🔄 Revoir ce thème
                </button>
            </div>
        </div>
    `).join('');

    analysisContainer.innerHTML = `
        <h3>🎯 Analyse de votre progression</h3>
        <div class="analysis-grid">
            <div class="analysis-column">
                <h4>💪 Points forts</h4>
                ${strengthsHtml || '<p class="empty-message">Continuez à travailler pour identifier vos points forts!</p>'}
            </div>
            <div class="analysis-column">
                <h4>📚 À améliorer</h4>
                ${weaknessesHtml || '<p class="empty-message">Excellent! Aucun point faible détecté.</p>'}
            </div>
        </div>
    `;
}

/**
 * Render recent activity
 */
function renderRecentActivity(activities) {
    const activityContainer = document.getElementById('progress-activity');

    if (!activities || activities.length === 0) {
        activityContainer.innerHTML = `
            <h3>📋 Activité récente</h3>
            <p class="empty-message">Aucune activité récente</p>
        `;
        return;
    }

    const activitiesHtml = activities.map(a => {
        const date = new Date(a.completed_at).toLocaleDateString('fr-FR');
        const time = new Date(a.completed_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        const scoreClass = a.score >= 80 ? 'high-score' : a.score >= 60 ? 'medium-score' : 'low-score';

        return `
            <div class="activity-card">
                <div class="activity-header">
                    <h4>${a.assignment_title}</h4>
                    <span class="badge badge-${a.assignment_type}">${a.assignment_type}</span>
                </div>
                <div class="activity-body">
                    <p><strong>Thème:</strong> ${a.theme_title}</p>
                    <p><strong>Date:</strong> ${date} à ${time}</p>
                    <div class="activity-stats">
                        <span class="score ${scoreClass}">Score: ${a.score}%</span>
                        <span class="time">⏱️ ${formatTime(a.time_spent)}</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    activityContainer.innerHTML = `
        <h3>📋 Activité récente</h3>
        <div class="activity-list">
            ${activitiesHtml}
        </div>
    `;
}

/**
 * Format time in seconds to human readable
 */
function formatTime(seconds) {
    if (!seconds) return '0s';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m`;
    } else {
        return `${secs}s`;
    }
}

/**
 * Suggest review for a theme
 */
function suggestReview(themeTitle) {
    alert(`Suggestion: Réviser le thème "${themeTitle}". Vous seriez redirigé vers la page de révision.`);
}

/**
 * View badges
 */
function viewBadges(studentId) {
    // Navigate to badges view
    navigateTo('student-badges');
    initStudentBadgesView(studentId);
}

/**
 * Refresh progress
 */
async function refreshProgress(studentId) {
    await loadStudentProgress(studentId);
}

// Export functions for global use
window.initStudentProgressView = initStudentProgressView;
window.refreshProgress = refreshProgress;
window.viewBadges = viewBadges;
window.suggestReview = suggestReview;
