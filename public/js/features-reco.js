/**
 * Sprint 7 - E7-RECO: Recommandations IA
 *
 * Widget "Pour toi" - 3 suggestions personnalisées avec explicabilité
 */

/**
 * Charger et afficher les recommandations pour un élève
 * @param {string} studentId - ID de l'élève
 * @param {string} containerId - ID du conteneur HTML
 */
async function loadStudentRecommendations(studentId, containerId = 'recommendations-widget') {
    const container = document.getElementById(containerId);

    if (!container) {
        console.error('Recommendations container not found:', containerId);
        return;
    }

    container.innerHTML = `
        <div class="widget-header">
            <h3>💡 Pour toi</h3>
            <p class="widget-subtitle">Recommandations personnalisées</p>
        </div>
        <div class="recommendations-loading">
            <p>⏳ Génération de tes recommandations...</p>
        </div>
    `;

    try {
        const data = await apiCall(`/api/reco?studentId=${studentId}`);

        if (!data || !data.recommendations || data.recommendations.length === 0) {
            container.innerHTML = `
                <div class="widget-header">
                    <h3>💡 Pour toi</h3>
                    <p class="widget-subtitle">Recommandations personnalisées</p>
                </div>
                <div class="recommendations-empty">
                    <p>📚 Continue à travailler pour obtenir des recommandations personnalisées !</p>
                </div>
            `;
            return;
        }

        renderRecommendations(container, data);

    } catch (error) {
        console.error('Failed to load recommendations:', error);
        container.innerHTML = `
            <div class="widget-header">
                <h3>💡 Pour toi</h3>
            </div>
            <div class="error-message">
                <p>❌ Impossible de charger les recommandations</p>
                <p class="error-details">${error.message}</p>
            </div>
        `;
    }
}

/**
 * Afficher les recommandations dans le conteneur
 */
function renderRecommendations(container, data) {
    const { recommendations, profile_summary } = data;

    let html = `
        <div class="widget-header">
            <h3>💡 Pour toi</h3>
            <p class="widget-subtitle">
                Basé sur ${profile_summary.total_sessions} sessions
                (score moyen: ${profile_summary.avg_score}%)
            </p>
        </div>
        <div class="recommendations-list">
    `;

    recommendations.forEach((rec, index) => {
        const rankEmoji = ['🥇', '🥈', '🥉'][index];
        const difficultyLabel = getDifficultyLabel(rec.theme_difficulty);
        const difficultyClass = `difficulty-${rec.theme_difficulty}`;

        html += `
            <div class="recommendation-card" data-theme-id="${rec.theme_id}">
                <div class="rec-rank">${rankEmoji}</div>
                <div class="rec-content">
                    <h4 class="rec-title">${rec.theme_title}</h4>
                    <span class="rec-difficulty ${difficultyClass}">${difficultyLabel}</span>

                    <p class="rec-description">${rec.theme_description || ''}</p>

                    <!-- Explicabilité -->
                    <div class="rec-reasons">
                        ${rec.reasons.map(reason => `
                            <div class="rec-reason">
                                <span class="reason-icon">${getReasonIcon(reason.type)}</span>
                                <div class="reason-content">
                                    <strong>${reason.label}</strong>
                                    <p>${reason.description}</p>
                                </div>
                            </div>
                        `).join('')}
                    </div>

                    <!-- Score de recommandation (debug si nécessaire) -->
                    <div class="rec-meta">
                        <span class="rec-score-label">Score: ${rec.recommendation_score.toFixed(1)}/100</span>
                        ${rec.attempt_count > 0 ? `<span class="rec-attempts">${rec.attempt_count} tentative(s)</span>` : '<span class="rec-new-badge">✨ Nouveau</span>'}
                    </div>

                    <!-- Actions -->
                    <div class="rec-actions">
                        <button class="btn btn-primary btn-start-theme" onclick="startThemeFromRecommendation('${rec.theme_id}', '${rec.theme_title}')">
                            🚀 Commencer
                        </button>
                        <button class="btn btn-secondary btn-feedback" onclick="recordRecommendationFeedback('${data.student_id}', '${rec.theme_id}', 'not_relevant')">
                            👎 Pas pertinent
                        </button>
                    </div>
                </div>
            </div>
        `;
    });

    html += `
        </div>
        <div class="recommendations-footer">
            <p class="rec-info">
                ℹ️ Ces recommandations sont basées sur tes performances passées et ton rythme d'apprentissage.
                Elles sont mises à jour automatiquement.
            </p>
        </div>
    `;

    container.innerHTML = html;
}

/**
 * Obtenir le label de difficulté
 */
function getDifficultyLabel(difficulty) {
    const labels = {
        'beginner': '🟢 Débutant',
        'intermediate': '🟡 Intermédiaire',
        'advanced': '🔴 Avancé'
    };
    return labels[difficulty] || difficulty;
}

/**
 * Obtenir l'icône pour un type de raison
 */
function getReasonIcon(reasonType) {
    const icons = {
        'new_content': '🆕',
        'needs_review': '📝',
        'adaptive_difficulty': '🎯',
        'optimal_timing': '⏰',
        'weakness_focus': '💪',
        'strength_building': '🌟'
    };
    return icons[reasonType] || '✅';
}

/**
 * Démarrer un thème depuis une recommandation
 */
async function startThemeFromRecommendation(themeId, themeTitle) {
    if (!confirm(`Commencer le thème "${themeTitle}" ?`)) {
        return;
    }

    try {
        // Cette fonction doit être implémentée selon votre logique de démarrage de session
        // Pour l'instant, on affiche un message
        alert(`Fonctionnalité à venir: Démarrage de la session pour le thème "${themeTitle}"`);

        // TODO: Intégrer avec ErgoMate ou la logique de session existante
        // await startErgoMateSession(themeId);

        // Enregistrer que la recommandation a été suivie
        await recordRecommendationFeedback(currentUser.id, themeId, 'completed');

    } catch (error) {
        console.error('Failed to start theme:', error);
        alert('Erreur lors du démarrage: ' + error.message);
    }
}

/**
 * Enregistrer le feedback sur une recommandation
 */
async function recordRecommendationFeedback(studentId, themeId, feedback) {
    try {
        await apiCall('/api/reco/feedback', {
            method: 'POST',
            body: JSON.stringify({
                studentId: studentId,
                themeId: themeId,
                feedback: feedback
            })
        });

        // Feedback visuel
        if (feedback === 'not_relevant') {
            // Retirer la carte de recommandation de l'affichage
            const card = document.querySelector(`[data-theme-id="${themeId}"]`);
            if (card) {
                card.style.opacity = '0.5';
                card.innerHTML += '<div class="feedback-overlay">✅ Merci pour ton feedback !</div>';
                setTimeout(() => {
                    card.remove();
                }, 2000);
            }
        } else if (feedback === 'completed') {
            console.log('Recommendation completed feedback recorded');
        }

    } catch (error) {
        console.error('Failed to record feedback:', error);
        // Ne pas bloquer l'expérience utilisateur
    }
}

/**
 * Ajouter le widget de recommandations au dashboard élève
 * Appelé depuis initDashboardView si l'utilisateur est un élève
 */
function addRecommendationsWidget() {
    if (!currentUser || currentUser.role !== 'student') {
        return;
    }

    const dashboardContent = document.getElementById('dashboard-content');
    if (!dashboardContent) return;

    // Insérer le widget avant les autres sections
    const widgetHTML = `
        <div class="recommendations-section" id="recommendations-widget">
            <!-- Le contenu sera chargé par loadStudentRecommendations() -->
        </div>
    `;

    // Insérer après le header
    const header = dashboardContent.querySelector('.dashboard-header');
    if (header) {
        header.insertAdjacentHTML('afterend', widgetHTML);
    } else {
        dashboardContent.insertAdjacentHTML('afterbegin', widgetHTML);
    }

    // Charger les recommandations
    loadStudentRecommendations(currentUser.id);
}

// Export pour usage global
window.loadStudentRecommendations = loadStudentRecommendations;
window.startThemeFromRecommendation = startThemeFromRecommendation;
window.recordRecommendationFeedback = recordRecommendationFeedback;
window.addRecommendationsWidget = addRecommendationsWidget;
