/**
 * Student Missions View - Sprint 5: E5-MISSIONS
 * Displays student missions inbox with status tracking
 */

/**
 * Initialize Student Missions View
 */
async function initStudentMissionsView(studentId) {
    const content = document.getElementById('student-missions-content');

    if (!studentId) {
        content.innerHTML = '<p>⚠️ ID élève requis</p>';
        return;
    }

    content.innerHTML = `
        <div class="missions-header">
            <h2>📋 Mes Missions ErgoMate</h2>
            <div class="missions-controls">
                <button onclick="refreshMissions('${studentId}')" class="btn-refresh">
                    🔄 Actualiser
                </button>
                <select id="mission-filter" onchange="filterMissions(this.value)">
                    <option value="all">Toutes les missions</option>
                    <option value="a_faire">À faire</option>
                    <option value="en_cours">En cours</option>
                    <option value="terminee">Terminées</option>
                </select>
            </div>
        </div>

        <div id="missions-stats" class="missions-stats">
            <p>Chargement des statistiques...</p>
        </div>

        <div id="missions-list" class="missions-list">
            <p>Chargement des missions...</p>
        </div>
    `;

    await loadStudentMissions(studentId);
}

/**
 * Load student missions from API
 */
async function loadStudentMissions(studentId) {
    const missionsList = document.getElementById('missions-list');
    const missionsStats = document.getElementById('missions-stats');

    try {
        const data = await apiCall(`/api/student/missions/pull?student_id=${studentId}`);

        // Store missions globally for filtering
        window.currentMissions = data.missions || [];
        window.currentStudentId = studentId;

        // Display stats
        const stats = calculateMissionStats(data.missions);
        missionsStats.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📝</div>
                    <div class="stat-content">
                        <h4>Total</h4>
                        <p class="stat-value">${stats.total}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-content">
                        <h4>À faire</h4>
                        <p class="stat-value">${stats.a_faire}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🚀</div>
                    <div class="stat-content">
                        <h4>En cours</h4>
                        <p class="stat-value">${stats.en_cours}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h4>Terminées</h4>
                        <p class="stat-value">${stats.terminee}</p>
                    </div>
                </div>
            </div>
        `;

        // Display missions
        displayMissions(data.missions);

    } catch (error) {
        missionsList.innerHTML = `
            <div class="error-message">
                <p>❌ Erreur lors du chargement des missions</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Calculate mission statistics
 */
function calculateMissionStats(missions) {
    return {
        total: missions.length,
        a_faire: missions.filter(m => m.local_status === 'a_faire').length,
        en_cours: missions.filter(m => m.local_status === 'en_cours').length,
        terminee: missions.filter(m => m.local_status === 'terminee').length
    };
}

/**
 * Display missions in list
 */
function displayMissions(missions) {
    const missionsList = document.getElementById('missions-list');

    if (missions.length === 0) {
        missionsList.innerHTML = `
            <div class="empty-state">
                <p>🎉 Aucune mission en attente</p>
                <p class="empty-subtitle">Vos professeurs vous attribueront bientôt de nouvelles missions</p>
            </div>
        `;
        return;
    }

    const missionsHtml = missions.map(mission => {
        const statusBadge = getStatusBadge(mission.local_status);
        const typeBadge = getTypeBadge(mission.type);
        const dueDate = mission.due_at ? new Date(mission.due_at).toLocaleDateString('fr-FR') : 'Pas de date limite';
        const isOverdue = mission.is_overdue;
        const overdueClass = isOverdue ? 'overdue' : '';

        return `
            <div class="mission-card ${overdueClass}" data-mission-id="${mission.id}">
                <div class="mission-header">
                    <div class="mission-title-group">
                        <h3 class="mission-title">${mission.title}</h3>
                        <div class="mission-badges">
                            ${typeBadge}
                            ${statusBadge}
                            ${isOverdue ? '<span class="badge badge-danger">⚠️ En retard</span>' : ''}
                        </div>
                    </div>
                    <div class="mission-meta">
                        <p><strong>Thème:</strong> ${mission.theme_title}</p>
                        <p><strong>Prof:</strong> ${mission.teacher_name}</p>
                        <p><strong>Échéance:</strong> ${dueDate}</p>
                    </div>
                </div>

                ${mission.instructions ? `
                    <div class="mission-instructions">
                        <p>${mission.instructions}</p>
                    </div>
                ` : ''}

                <div class="mission-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${mission.progress_percent}%"></div>
                    </div>
                    <span class="progress-text">${mission.progress_percent}%</span>
                </div>

                ${mission.local_status === 'terminee' && mission.score !== null ? `
                    <div class="mission-result">
                        <p><strong>Score:</strong> ${mission.score}%</p>
                        <p><strong>Temps:</strong> ${formatTime(mission.time_spent)}</p>
                    </div>
                ` : ''}

                <div class="mission-actions">
                    ${getMissionActions(mission)}
                </div>
            </div>
        `;
    }).join('');

    missionsList.innerHTML = missionsHtml;
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status) {
    const badges = {
        'a_faire': '<span class="badge badge-warning">⏳ À faire</span>',
        'en_cours': '<span class="badge badge-info">🚀 En cours</span>',
        'terminee': '<span class="badge badge-success">✅ Terminée</span>'
    };
    return badges[status] || '';
}

/**
 * Get type badge HTML
 */
function getTypeBadge(type) {
    const badges = {
        'quiz': '<span class="badge badge-primary">📝 Quiz</span>',
        'flashcards': '<span class="badge badge-primary">🃏 Flashcards</span>',
        'fiche': '<span class="badge badge-primary">📄 Fiche</span>',
        'annales': '<span class="badge badge-primary">📚 Annales</span>'
    };
    return badges[type] || '';
}

/**
 * Get mission action buttons based on status
 */
function getMissionActions(mission) {
    const studentId = window.currentStudentId;

    if (mission.local_status === 'a_faire') {
        return `
            <button onclick="startMission('${mission.id}', '${studentId}')" class="btn-primary">
                ▶️ Démarrer
            </button>
        `;
    } else if (mission.local_status === 'en_cours') {
        return `
            <button onclick="continueMission('${mission.id}', '${studentId}')" class="btn-primary">
                ⏯️ Continuer
            </button>
            <button onclick="completeMission('${mission.id}', '${studentId}')" class="btn-success">
                ✅ Marquer comme terminée
            </button>
        `;
    } else if (mission.local_status === 'terminee') {
        return `
            <button onclick="reviewMission('${mission.id}', '${studentId}')" class="btn-secondary">
                🔄 Revoir
            </button>
        `;
    }
    return '';
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
        return `${minutes}m ${secs}s`;
    } else {
        return `${secs}s`;
    }
}

/**
 * Start a mission
 */
async function startMission(missionId, studentId) {
    try {
        await updateMissionStatus(missionId, studentId, 'en_cours');

        // In a real app, this would open the ErgoMate module
        alert('Mission démarrée ! Vous seriez redirigé vers ErgoMate.');

        await refreshMissions(studentId);
    } catch (error) {
        alert('Erreur: ' + error.message);
    }
}

/**
 * Continue a mission
 */
async function continueMission(missionId, studentId) {
    // In a real app, this would open the ErgoMate module
    alert('Reprise de la mission ! Vous seriez redirigé vers ErgoMate.');
}

/**
 * Complete a mission (manual)
 */
async function completeMission(missionId, studentId) {
    if (!confirm('Marquer cette mission comme terminée ?')) {
        return;
    }

    try {
        await updateMissionStatus(missionId, studentId, 'terminee');
        await refreshMissions(studentId);
    } catch (error) {
        alert('Erreur: ' + error.message);
    }
}

/**
 * Review a completed mission
 */
async function reviewMission(missionId, studentId) {
    alert('Révision de la mission ! Vous seriez redirigé vers ErgoMate.');
}

/**
 * Update mission status via API
 */
async function updateMissionStatus(missionId, studentId, status) {
    await apiCall(`/api/student/missions/${missionId}/status`, {
        method: 'PATCH',
        body: JSON.stringify({
            student_id: studentId,
            status: status
        })
    });
}

/**
 * Refresh missions
 */
async function refreshMissions(studentId) {
    await loadStudentMissions(studentId);
}

/**
 * Filter missions by status
 */
function filterMissions(filter) {
    const missions = window.currentMissions || [];

    let filtered = missions;
    if (filter !== 'all') {
        filtered = missions.filter(m => m.local_status === filter);
    }

    displayMissions(filtered);
}

// Export functions for global use
window.initStudentMissionsView = initStudentMissionsView;
window.startMission = startMission;
window.continueMission = continueMission;
window.completeMission = completeMission;
window.reviewMission = reviewMission;
window.refreshMissions = refreshMissions;
window.filterMissions = filterMissions;
