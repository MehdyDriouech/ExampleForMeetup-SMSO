/**
 * Sprint 7 - E7-FOCUS: Mode Focus
 *
 * Mini-sessions ciblées de 5-10 minutes pour révision rapide
 * Basées sur les erreurs fréquentes et les thèmes à renforcer
 */

/**
 * Types de sessions Focus
 */
const FOCUS_MODES = {
    quick_review: {
        id: 'quick_review',
        name: '⚡ Révision éclair',
        duration: 5,
        questionCount: 5,
        description: '5 questions en 5 minutes',
        icon: '⚡',
        targetScore: 80
    },
    error_focus: {
        id: 'error_focus',
        name: '🎯 Focus Erreurs',
        duration: 10,
        questionCount: 10,
        description: 'Rejouer tes erreurs récentes',
        icon: '🎯',
        targetScore: 70
    },
    mastery_boost: {
        id: 'mastery_boost',
        name: '💪 Boost Maîtrise',
        duration: 10,
        questionCount: 8,
        description: 'Renforcer un concept spécifique',
        icon: '💪',
        targetScore: 85
    },
    daily_challenge: {
        id: 'daily_challenge',
        name: '🌟 Défi du jour',
        duration: 8,
        questionCount: 7,
        description: 'Challenge quotidien adapté',
        icon: '🌟',
        targetScore: 75
    }
};

/**
 * Créer une session Focus pour un élève
 * @param {string} studentId - ID de l'élève
 * @param {string} focusModeId - Type de session focus
 * @param {string} themeId - ID du thème (optionnel)
 * @returns {Promise<Object>} Configuration de la session
 */
async function createFocusSession(studentId, focusModeId, themeId = null) {
    const mode = FOCUS_MODES[focusModeId];

    if (!mode) {
        throw new Error('Invalid focus mode: ' + focusModeId);
    }

    try {
        // Récupérer les données nécessaires selon le mode
        let sessionConfig;

        switch (focusModeId) {
            case 'quick_review':
                sessionConfig = await buildQuickReviewSession(studentId, themeId);
                break;
            case 'error_focus':
                sessionConfig = await buildErrorFocusSession(studentId, themeId);
                break;
            case 'mastery_boost':
                sessionConfig = await buildMasteryBoostSession(studentId, themeId);
                break;
            case 'daily_challenge':
                sessionConfig = await buildDailyChallengeSession(studentId);
                break;
            default:
                throw new Error('Mode not implemented: ' + focusModeId);
        }

        // Enrichir avec les infos du mode
        sessionConfig.mode = mode;
        sessionConfig.studentId = studentId;
        sessionConfig.createdAt = new Date().toISOString();

        // Sauvegarder la session
        saveFocusSession(sessionConfig);

        return sessionConfig;

    } catch (error) {
        console.error('Failed to create focus session:', error);
        throw error;
    }
}

/**
 * Construire une session Révision Éclair
 */
async function buildQuickReviewSession(studentId, themeId) {
    // Récupérer des questions variées sur les thèmes récents
    const recentThemes = await getRecentThemes(studentId, 3);

    return {
        type: 'quick_review',
        questions: await selectQuestionsFromThemes(recentThemes, 5),
        timeLimit: 5 * 60, // 5 minutes en secondes
        config: {
            showHints: false,
            allowReview: false,
            showExplanationsImmediately: true
        }
    };
}

/**
 * Construire une session Focus Erreurs
 */
async function buildErrorFocusSession(studentId, themeId) {
    // Récupérer les erreurs récentes de l'élève
    const errors = await apiCall(`/api/student/${studentId}/review${themeId ? '?theme_id=' + themeId : ''}`);

    if (!errors || !errors.review_items || errors.review_items.length === 0) {
        throw new Error('Pas d\'erreurs récentes trouvées');
    }

    // Sélectionner les 10 erreurs les plus fréquentes
    const topErrors = errors.review_items.slice(0, 10);

    return {
        type: 'error_focus',
        questions: topErrors.map(item => item.error),
        timeLimit: 10 * 60,
        config: {
            showHints: true,
            allowReview: true,
            showExplanationsImmediately: true,
            showPreviousAttempt: true
        },
        metadata: {
            errorCount: errors.count,
            themesWithErrors: errors.errors_by_theme
        }
    };
}

/**
 * Construire une session Boost Maîtrise
 */
async function buildMasteryBoostSession(studentId, themeId) {
    if (!themeId) {
        // Sélectionner le thème avec la plus faible maîtrise
        const weaknesses = await getStudentWeaknesses(studentId);
        themeId = weaknesses[0]?.theme_id;

        if (!themeId) {
            throw new Error('Aucun thème à améliorer trouvé');
        }
    }

    // Sélectionner des questions ciblées sur ce thème
    const questions = await selectQuestionsFromTheme(themeId, 8, 'progressive');

    return {
        type: 'mastery_boost',
        questions: questions,
        themeId: themeId,
        timeLimit: 10 * 60,
        config: {
            showHints: false,
            allowReview: false,
            progressiveDifficulty: true
        }
    };
}

/**
 * Construire le Défi du Jour
 */
async function buildDailyChallengeSession(studentId) {
    // Vérifier si le défi du jour a déjà été fait
    const today = new Date().toISOString().split('T')[0];
    const existingChallenge = localStorage.getItem(`daily_challenge_${studentId}_${today}`);

    if (existingChallenge) {
        return JSON.parse(existingChallenge);
    }

    // Créer un nouveau défi adapté
    const difficulty = await calculateAdaptiveDifficulty(studentId);
    const themes = await getRecentThemes(studentId, 2);

    const config = {
        type: 'daily_challenge',
        questions: await selectQuestionsFromThemes(themes, 7, difficulty.currentLevel),
        timeLimit: 8 * 60,
        config: {
            showHints: difficulty.currentLevel === 'easy',
            allowReview: false,
            bonusPoints: true
        },
        challenge: {
            date: today,
            targetScore: FOCUS_MODES.daily_challenge.targetScore,
            rewardBadge: 'daily_champion'
        }
    };

    // Sauvegarder pour aujourd'hui
    localStorage.setItem(`daily_challenge_${studentId}_${today}`, JSON.stringify(config));

    return config;
}

/**
 * Récupérer les thèmes récents d'un élève
 */
async function getRecentThemes(studentId, limit = 3) {
    try {
        const progress = await apiCall(`/api/student/${studentId}/progress`);

        if (!progress || !progress.recent_activity) {
            return [];
        }

        // Extraire les thèmes uniques des activités récentes
        const themeIds = [...new Set(
            progress.recent_activity
                .slice(0, limit * 2)
                .map(act => act.theme_id)
                .filter(Boolean)
        )].slice(0, limit);

        return themeIds;

    } catch (error) {
        console.error('Failed to get recent themes:', error);
        return [];
    }
}

/**
 * Récupérer les faiblesses d'un élève
 */
async function getStudentWeaknesses(studentId) {
    try {
        const progress = await apiCall(`/api/student/${studentId}/progress`);

        if (!progress || !progress.analysis || !progress.analysis.weaknesses) {
            return [];
        }

        return progress.analysis.weaknesses;

    } catch (error) {
        console.error('Failed to get weaknesses:', error);
        return [];
    }
}

/**
 * Sélectionner des questions depuis une liste de thèmes
 * (Placeholder - à implémenter selon votre logique de questions)
 */
async function selectQuestionsFromThemes(themeIds, count, difficulty = 'normal') {
    // TODO: Implémenter la sélection réelle de questions depuis ErgoMate ou la base
    // Pour l'instant, retourner des questions mock
    return Array.from({ length: count }, (_, i) => ({
        id: `q_${i + 1}`,
        theme_id: themeIds[i % themeIds.length],
        text: `Question ${i + 1} (${difficulty})`,
        type: 'mcq',
        difficulty: difficulty
    }));
}

/**
 * Sélectionner des questions depuis un thème
 */
async function selectQuestionsFromTheme(themeId, count, strategy = 'random') {
    // TODO: Implémenter selon votre logique
    return Array.from({ length: count }, (_, i) => ({
        id: `q_theme_${i + 1}`,
        theme_id: themeId,
        text: `Question ${i + 1}`,
        type: 'mcq',
        strategy: strategy
    }));
}

/**
 * Sauvegarder une session Focus
 */
function saveFocusSession(sessionConfig) {
    const sessionId = `focus_${sessionConfig.studentId}_${Date.now()}`;
    sessionConfig.id = sessionId;

    localStorage.setItem(`focus_session_${sessionId}`, JSON.stringify(sessionConfig));
    localStorage.setItem('current_focus_session', sessionId);

    return sessionId;
}

/**
 * Récupérer la session Focus actuelle
 */
function getCurrentFocusSession() {
    const sessionId = localStorage.getItem('current_focus_session');
    if (!sessionId) return null;

    const sessionData = localStorage.getItem(`focus_session_${sessionId}`);
    return sessionData ? JSON.parse(sessionData) : null;
}

/**
 * Afficher le menu de sélection du mode Focus
 * @param {string} studentId
 * @param {string} containerId
 */
function renderFocusModeSelector(studentId, containerId = 'focus-mode-selector') {
    const container = document.getElementById(containerId);
    if (!container) {
        console.error('Focus mode container not found');
        return;
    }

    const html = `
        <div class="focus-mode-selector">
            <h3>🎯 Mode Focus</h3>
            <p class="subtitle">Mini-sessions ciblées pour révision rapide</p>

            <div class="focus-modes-grid">
                ${Object.values(FOCUS_MODES).map(mode => `
                    <div class="focus-mode-card" onclick="selectFocusMode('${studentId}', '${mode.id}')">
                        <div class="mode-icon">${mode.icon}</div>
                        <h4>${mode.name}</h4>
                        <p class="mode-desc">${mode.description}</p>
                        <div class="mode-stats">
                            <span>⏱️ ${mode.duration} min</span>
                            <span>📝 ${mode.questionCount} questions</span>
                        </div>
                        <div class="mode-target">
                            Objectif: ${mode.targetScore}%
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;

    container.innerHTML = html;
}

/**
 * Sélectionner et démarrer un mode Focus
 */
async function selectFocusMode(studentId, modeId) {
    const mode = FOCUS_MODES[modeId];

    if (!confirm(`Démarrer "${mode.name}" ?\n${mode.description}`)) {
        return;
    }

    try {
        // Afficher un loader
        showLoadingOverlay(`Préparation de ta session ${mode.name}...`);

        // Créer la session
        const sessionConfig = await createFocusSession(studentId, modeId);

        hideLoadingOverlay();

        // Démarrer la session
        startFocusSession(sessionConfig);

    } catch (error) {
        hideLoadingOverlay();
        alert('Erreur lors de la création de la session: ' + error.message);
        console.error(error);
    }
}

/**
 * Démarrer une session Focus
 */
function startFocusSession(sessionConfig) {
    // TODO: Implémenter l'interface de session Focus
    // Pour l'instant, afficher un message
    alert(`Session ${sessionConfig.mode.name} créée!\n` +
          `${sessionConfig.questions.length} questions, ${Math.floor(sessionConfig.timeLimit / 60)} minutes.\n\n` +
          `Cette fonctionnalité sera complètement intégrée avec ErgoMate dans une prochaine version.`);

    console.log('Focus session config:', sessionConfig);

    // Rediriger vers l'interface de session (à implémenter)
    // window.location.href = `/session/focus/${sessionConfig.id}`;
}

/**
 * Afficher/masquer l'overlay de chargement
 */
function showLoadingOverlay(message) {
    let overlay = document.getElementById('loading-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.className = 'loading-overlay';
        document.body.appendChild(overlay);
    }

    overlay.innerHTML = `
        <div class="loading-content">
            <div class="spinner"></div>
            <p>${message}</p>
        </div>
    `;
    overlay.style.display = 'flex';
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

/**
 * Enregistrer les résultats d'une session Focus
 */
async function completeFocusSession(sessionId, results) {
    const session = localStorage.getItem(`focus_session_${sessionId}`);
    if (!session) {
        throw new Error('Session not found');
    }

    const sessionData = JSON.parse(session);

    // Enrichir avec les résultats
    sessionData.results = results;
    sessionData.completedAt = new Date().toISOString();

    // Sauvegarder dans l'historique
    const history = JSON.parse(localStorage.getItem('focus_sessions_history') || '[]');
    history.unshift(sessionData);
    localStorage.setItem('focus_sessions_history', JSON.stringify(history.slice(0, 50)));

    // Nettoyer la session courante
    localStorage.removeItem('current_focus_session');

    // Synchroniser avec le backend si possible
    try {
        await apiCall('/api/student/sync/push', {
            method: 'POST',
            body: JSON.stringify({
                student_id: sessionData.studentId,
                session_data: {
                    type: 'focus_mode',
                    mode: sessionData.type,
                    score: results.score,
                    time_spent: results.timeSpent,
                    ended_at: sessionData.completedAt
                }
            })
        });
    } catch (error) {
        console.warn('Failed to sync focus session:', error);
    }

    return sessionData;
}

// Export pour usage global
window.createFocusSession = createFocusSession;
window.renderFocusModeSelector = renderFocusModeSelector;
window.selectFocusMode = selectFocusMode;
window.startFocusSession = startFocusSession;
window.completeFocusSession = completeFocusSession;
window.getCurrentFocusSession = getCurrentFocusSession;
window.FOCUS_MODES = FOCUS_MODES;
