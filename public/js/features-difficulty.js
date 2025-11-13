/**
 * Sprint 7 - E7-DIFF: Difficulté adaptative
 *
 * Système d'ajustement dynamique de la difficulté basé sur:
 * - Performances en temps réel
 * - Zone Proximale de Développement (ZPD)
 * - Règles adaptatives (easy/normal/expert)
 */

/**
 * Niveaux de difficulté
 */
const DIFFICULTY_LEVELS = {
    easy: {
        label: '🟢 Facile',
        color: '#28a745',
        description: 'Questions plus simples, plus de temps',
        modifiers: {
            timeMultiplier: 1.5,
            hintsEnabled: true,
            questionCount: 10
        }
    },
    normal: {
        label: '🟡 Normal',
        color: '#ffc107',
        description: 'Difficulté standard',
        modifiers: {
            timeMultiplier: 1.0,
            hintsEnabled: false,
            questionCount: 15
        }
    },
    expert: {
        label: '🔴 Expert',
        color: '#dc3545',
        description: 'Défis avancés, sans aide',
        modifiers: {
            timeMultiplier: 0.8,
            hintsEnabled: false,
            questionCount: 20
        }
    }
};

/**
 * Calculer le niveau de difficulté adaptatif pour un élève
 * @param {string} studentId - ID de l'élève
 * @param {string} themeId - ID du thème (optionnel)
 * @returns {Promise<Object>} Niveau recommandé avec justification
 */
async function calculateAdaptiveDifficulty(studentId, themeId = null) {
    try {
        // Récupérer les stats de l'élève
        const stats = await getStudentStats(studentId, themeId);

        // Calculer le niveau basé sur les performances
        const level = determineLevel(stats);

        return {
            currentLevel: level,
            levelInfo: DIFFICULTY_LEVELS[level],
            stats: stats,
            recommendation: generateLevelRecommendation(level, stats)
        };

    } catch (error) {
        console.error('Failed to calculate adaptive difficulty:', error);
        // Par défaut, retourner normal
        return {
            currentLevel: 'normal',
            levelInfo: DIFFICULTY_LEVELS.normal,
            stats: null,
            recommendation: 'Niveau normal par défaut'
        };
    }
}

/**
 * Récupérer les statistiques de performance de l'élève
 */
async function getStudentStats(studentId, themeId = null) {
    const endpoint = themeId
        ? `/api/student/${studentId}/progress?themeId=${themeId}`
        : `/api/student/${studentId}/progress`;

    try {
        const data = await apiCall(endpoint);

        return {
            avgScore: data.kpis?.avg_score || 0,
            avgMastery: data.kpis?.overall_mastery || 0,
            totalSessions: data.kpis?.total_sessions || 0,
            recentTrend: calculateTrend(data.recent_activity || []),
            successRate: calculateSuccessRate(data.recent_activity || [])
        };
    } catch (error) {
        console.warn('Could not fetch student stats, using defaults');
        return {
            avgScore: 50,
            avgMastery: 0.5,
            totalSessions: 0,
            recentTrend: 'stable',
            successRate: 50
        };
    }
}

/**
 * Calculer la tendance des performances récentes
 */
function calculateTrend(recentActivity) {
    if (recentActivity.length < 3) return 'stable';

    const recent = recentActivity.slice(0, 5);
    const older = recentActivity.slice(5, 10);

    if (recent.length === 0) return 'stable';

    const avgRecent = recent.reduce((sum, act) => sum + act.score, 0) / recent.length;
    const avgOlder = older.length > 0
        ? older.reduce((sum, act) => sum + act.score, 0) / older.length
        : avgRecent;

    const diff = avgRecent - avgOlder;

    if (diff > 10) return 'improving';
    if (diff < -10) return 'declining';
    return 'stable';
}

/**
 * Calculer le taux de réussite récent
 */
function calculateSuccessRate(recentActivity) {
    if (recentActivity.length === 0) return 50;

    const recent = recentActivity.slice(0, 10);
    const successCount = recent.filter(act => act.score >= 70).length;

    return (successCount / recent.length) * 100;
}

/**
 * Déterminer le niveau adaptatif basé sur les stats
 */
function determineLevel(stats) {
    const { avgScore, avgMastery, recentTrend, successRate } = stats;

    // Règles d'ajustement adaptatif
    // 1. Si excellent (score >= 80 et maîtrise >= 0.75) → expert
    if (avgScore >= 80 && avgMastery >= 0.75 && successRate >= 80) {
        return 'expert';
    }

    // 2. Si en difficulté (score < 50 ou maîtrise < 0.40) → easy
    if (avgScore < 50 || avgMastery < 0.40 || successRate < 40) {
        return 'easy';
    }

    // 3. Si en déclin → revenir à easy pour consolider
    if (recentTrend === 'declining' && avgScore < 65) {
        return 'easy';
    }

    // 4. Si en progression et bon niveau → expert pour challenge
    if (recentTrend === 'improving' && avgScore >= 75 && avgMastery >= 0.65) {
        return 'expert';
    }

    // 5. Sinon, rester en normal (ZPD)
    return 'normal';
}

/**
 * Générer une recommandation textuelle
 */
function generateLevelRecommendation(level, stats) {
    const templates = {
        easy: `Niveau Facile recommandé pour consolider tes bases. Score moyen: ${stats.avgScore.toFixed(1)}%. Continue comme ça !`,
        normal: `Niveau Normal parfait pour progresser. Tu es dans ta Zone Proximale de Développement. Taux de réussite: ${stats.successRate.toFixed(0)}%.`,
        expert: `Niveau Expert débloqué ! Tes performances sont excellentes (${stats.avgScore.toFixed(1)}%). Prêt pour le challenge ?`
    };

    return templates[level] || templates.normal;
}

/**
 * Afficher le badge de niveau dans l'interface
 * @param {string} containerId - ID du conteneur
 * @param {string} level - Niveau actuel
 * @param {Object} levelInfo - Infos du niveau
 */
function renderDifficultyBadge(containerId, level, levelInfo, canChange = true) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const html = `
        <div class="difficulty-badge-container">
            <div class="difficulty-badge" style="border-color: ${levelInfo.color};">
                <span class="difficulty-label">${levelInfo.label}</span>
                <span class="difficulty-desc">${levelInfo.description}</span>
            </div>
            ${canChange ? `
                <button class="btn-change-difficulty" onclick="showDifficultySelector()">
                    ⚙️ Changer
                </button>
            ` : ''}
        </div>
    `;

    container.innerHTML = html;
}

/**
 * Afficher le sélecteur de difficulté
 */
function showDifficultySelector() {
    const modal = document.createElement('div');
    modal.className = 'difficulty-modal-overlay';
    modal.innerHTML = `
        <div class="difficulty-modal">
            <h3>Choisir le niveau de difficulté</h3>
            <p class="modal-subtitle">Tu peux ajuster manuellement le niveau, mais le système s'adaptera automatiquement selon tes performances.</p>

            <div class="difficulty-options">
                ${Object.entries(DIFFICULTY_LEVELS).map(([key, info]) => `
                    <div class="difficulty-option" onclick="selectDifficulty('${key}')">
                        <h4>${info.label}</h4>
                        <p>${info.description}</p>
                        <ul class="modifiers-list">
                            <li>⏱️ Temps: x${info.modifiers.timeMultiplier}</li>
                            <li>❓ ${info.modifiers.questionCount} questions</li>
                            <li>${info.modifiers.hintsEnabled ? '💡 Indices activés' : '🚫 Pas d\'indices'}</li>
                        </ul>
                    </div>
                `).join('')}
            </div>

            <button class="btn btn-secondary" onclick="closeDifficultySelector()">
                Annuler
            </button>
        </div>
    `;

    document.body.appendChild(modal);
}

/**
 * Sélectionner un niveau de difficulté
 */
function selectDifficulty(level) {
    // Sauvegarder la préférence
    localStorage.setItem('preferred_difficulty', level);

    // Recharger l'interface
    const container = document.getElementById('difficulty-badge-container');
    if (container) {
        renderDifficultyBadge('difficulty-badge-container', level, DIFFICULTY_LEVELS[level]);
    }

    closeDifficultySelector();

    // Notification
    showNotification(`Niveau changé: ${DIFFICULTY_LEVELS[level].label}`, 'success');
}

/**
 * Fermer le sélecteur
 */
function closeDifficultySelector() {
    const modal = document.querySelector('.difficulty-modal-overlay');
    if (modal) {
        modal.remove();
    }
}

/**
 * Afficher une notification
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

/**
 * Initialiser le système de difficulté adaptative pour une session
 * @param {string} studentId
 * @param {string} themeId
 */
async function initAdaptiveDifficulty(studentId, themeId = null) {
    // Vérifier la préférence manuelle
    const preferredLevel = localStorage.getItem('preferred_difficulty');

    if (preferredLevel && DIFFICULTY_LEVELS[preferredLevel]) {
        // Utiliser la préférence manuelle
        return {
            level: preferredLevel,
            info: DIFFICULTY_LEVELS[preferredLevel],
            source: 'manual'
        };
    }

    // Sinon, calculer automatiquement
    const result = await calculateAdaptiveDifficulty(studentId, themeId);

    return {
        level: result.currentLevel,
        info: result.levelInfo,
        source: 'adaptive',
        recommendation: result.recommendation
    };
}

/**
 * Ajuster le niveau en temps réel pendant une session
 * @param {number} currentScore - Score actuel
 * @param {number} questionIndex - Index de la question
 * @param {number} totalQuestions - Total de questions
 */
function adjustDifficultyInSession(currentScore, questionIndex, totalQuestions) {
    // Ajustement dynamique uniquement après 5 questions
    if (questionIndex < 5) return null;

    const progressPercent = (questionIndex / totalQuestions) * 100;
    const scorePercent = currentScore;

    // Si l'élève excelle (>80%) et on est à mi-parcours, suggérer expert
    if (scorePercent > 80 && progressPercent >= 50) {
        return {
            suggested: 'expert',
            reason: 'Excellentes performances ! Prêt pour un challenge ?'
        };
    }

    // Si l'élève est en difficulté (<40%), suggérer easy
    if (scorePercent < 40 && progressPercent >= 30) {
        return {
            suggested: 'easy',
            reason: 'Passons en mode Facile pour mieux consolider.'
        };
    }

    return null;
}

// Export pour usage global
window.calculateAdaptiveDifficulty = calculateAdaptiveDifficulty;
window.renderDifficultyBadge = renderDifficultyBadge;
window.initAdaptiveDifficulty = initAdaptiveDifficulty;
window.adjustDifficultyInSession = adjustDifficultyInSession;
window.showDifficultySelector = showDifficultySelector;
window.selectDifficulty = selectDifficulty;
window.closeDifficultySelector = closeDifficultySelector;
window.DIFFICULTY_LEVELS = DIFFICULTY_LEVELS;
