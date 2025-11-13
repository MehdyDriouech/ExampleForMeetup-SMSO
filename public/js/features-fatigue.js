/**
 * Sprint 7 - E7-FAT: Détection de Fatigue et Charge Cognitive
 *
 * Système de détection et prévention de la fatigue cognitive:
 * - Analyse du temps de session
 * - Patterns de réponse (vitesse, hésitation)
 * - Taux d'erreurs en hausse
 * - Recommandations de pause
 */

/**
 * Niveaux de fatigue
 */
const FATIGUE_LEVELS = {
    fresh: {
        level: 0,
        label: '😊 Frais',
        color: '#28a745',
        description: 'Tu es en forme, continue !',
        action: null
    },
    mild: {
        level: 1,
        label: '😐 Légère fatigue',
        color: '#ffc107',
        description: 'Attention à la fatigue',
        action: 'suggest_break'
    },
    moderate: {
        level: 2,
        label: '😓 Fatigue modérée',
        color: '#fd7e14',
        description: 'Une pause serait bénéfique',
        action: 'encourage_break'
    },
    high: {
        level: 3,
        label: '😴 Très fatigué',
        color: '#dc3545',
        description: 'Temps de faire une pause !',
        action: 'force_break'
    }
};

/**
 * Détecteur de fatigue
 */
class FatigueDetector {
    constructor() {
        this.sessionStartTime = null;
        this.metrics = {
            totalTime: 0,
            questionsAnswered: 0,
            averageResponseTime: 0,
            responseTimes: [],
            errorRate: 0,
            consecutiveErrors: 0,
            slowResponses: 0,
            lastBreak: null
        };
        this.fatigueScore = 0;
        this.currentLevel = FATIGUE_LEVELS.fresh;
    }

    /**
     * Démarrer le tracking de session
     */
    startSession() {
        this.sessionStartTime = Date.now();
        this.metrics.lastBreak = Date.now();
        this.metrics = {
            totalTime: 0,
            questionsAnswered: 0,
            averageResponseTime: 0,
            responseTimes: [],
            errorRate: 0,
            consecutiveErrors: 0,
            slowResponses: 0,
            lastBreak: Date.now()
        };
    }

    /**
     * Enregistrer une réponse à une question
     * @param {boolean} isCorrect - La réponse est-elle correcte
     * @param {number} responseTime - Temps de réponse en ms
     */
    recordResponse(isCorrect, responseTime) {
        this.metrics.questionsAnswered++;
        this.metrics.responseTimes.push(responseTime);

        // Calculer le temps moyen de réponse
        this.metrics.averageResponseTime =
            this.metrics.responseTimes.reduce((a, b) => a + b, 0) / this.metrics.responseTimes.length;

        // Tracker les erreurs
        if (!isCorrect) {
            this.metrics.consecutiveErrors++;
            this.metrics.errorRate =
                (this.metrics.responseTimes.length - this.calculateCorrectCount()) /
                this.metrics.responseTimes.length;
        } else {
            this.metrics.consecutiveErrors = 0;
        }

        // Tracker les réponses lentes (>2x la moyenne)
        if (responseTime > this.metrics.averageResponseTime * 2) {
            this.metrics.slowResponses++;
        }

        // Calculer le temps total
        this.metrics.totalTime = (Date.now() - this.sessionStartTime) / 1000 / 60; // en minutes

        // Recalculer le score de fatigue
        this.calculateFatigueScore();
    }

    /**
     * Calculer le nombre de réponses correctes
     */
    calculateCorrectCount() {
        // Approximation basée sur le taux d'erreur
        return Math.floor(
            this.metrics.questionsAnswered * (1 - this.metrics.errorRate)
        );
    }

    /**
     * Calculer le score de fatigue (0-100)
     */
    calculateFatigueScore() {
        let score = 0;

        // 1. Facteur temps (max 30 points)
        // Augmente après 20 minutes, significatif après 45 minutes
        if (this.metrics.totalTime > 45) {
            score += 30;
        } else if (this.metrics.totalTime > 30) {
            score += 20;
        } else if (this.metrics.totalTime > 20) {
            score += 10;
        }

        // 2. Facteur taux d'erreurs (max 25 points)
        score += Math.min(25, this.metrics.errorRate * 100 * 0.5);

        // 3. Facteur erreurs consécutives (max 20 points)
        score += Math.min(20, this.metrics.consecutiveErrors * 5);

        // 4. Facteur réponses lentes (max 15 points)
        const slowResponseRate = this.metrics.slowResponses / Math.max(1, this.metrics.questionsAnswered);
        score += Math.min(15, slowResponseRate * 100 * 0.3);

        // 5. Facteur temps depuis dernière pause (max 10 points)
        const timeSinceBreak = (Date.now() - this.metrics.lastBreak) / 1000 / 60;
        if (timeSinceBreak > 30) {
            score += 10;
        } else if (timeSinceBreak > 15) {
            score += 5;
        }

        this.fatigueScore = Math.min(100, Math.round(score));

        // Déterminer le niveau
        this.updateFatigueLevel();

        return this.fatigueScore;
    }

    /**
     * Mettre à jour le niveau de fatigue
     */
    updateFatigueLevel() {
        if (this.fatigueScore >= 70) {
            this.currentLevel = FATIGUE_LEVELS.high;
        } else if (this.fatigueScore >= 45) {
            this.currentLevel = FATIGUE_LEVELS.moderate;
        } else if (this.fatigueScore >= 25) {
            this.currentLevel = FATIGUE_LEVELS.mild;
        } else {
            this.currentLevel = FATIGUE_LEVELS.fresh;
        }
    }

    /**
     * Obtenir le niveau de fatigue actuel
     */
    getCurrentLevel() {
        return {
            level: this.currentLevel,
            score: this.fatigueScore,
            metrics: this.metrics,
            recommendation: this.getRecommendation()
        };
    }

    /**
     * Obtenir une recommandation
     */
    getRecommendation() {
        switch (this.currentLevel.action) {
            case 'suggest_break':
                return {
                    type: 'suggestion',
                    message: '💡 Une petite pause de 2-3 minutes te ferait du bien !',
                    action: 'suggest'
                };
            case 'encourage_break':
                return {
                    type: 'encouragement',
                    message: '⏸️ Tu as bien travaillé ! Prends une pause de 5 minutes pour recharger les batteries.',
                    action: 'encourage'
                };
            case 'force_break':
                return {
                    type: 'mandatory',
                    message: '🛑 Pause obligatoire ! Tu as besoin de te reposer. On reprend dans 10 minutes.',
                    action: 'force',
                    duration: 600000 // 10 minutes en ms
                };
            default:
                return null;
        }
    }

    /**
     * Enregistrer une pause
     */
    recordBreak(duration) {
        this.metrics.lastBreak = Date.now();
        // Réduire le score de fatigue après une pause
        this.fatigueScore = Math.max(0, this.fatigueScore - (duration / 60000) * 10);
        this.updateFatigueLevel();
    }

    /**
     * Obtenir un résumé des métriques
     */
    getSummary() {
        return {
            sessionDuration: Math.round(this.metrics.totalTime),
            questionsAnswered: this.metrics.questionsAnswered,
            averageResponseTime: Math.round(this.metrics.averageResponseTime / 1000), // en secondes
            errorRate: Math.round(this.metrics.errorRate * 100),
            fatigueScore: this.fatigueScore,
            fatigueLevel: this.currentLevel.label
        };
    }
}

/**
 * Instance globale du détecteur
 */
let globalFatigueDetector = null;

/**
 * Initialiser le détecteur de fatigue pour une session
 */
function initFatigueDetection() {
    globalFatigueDetector = new FatigueDetector();
    globalFatigueDetector.startSession();

    // Démarrer le monitoring périodique
    startFatigueMonitoring();

    return globalFatigueDetector;
}

/**
 * Démarrer le monitoring périodique
 */
function startFatigueMonitoring() {
    // Vérifier le niveau de fatigue toutes les 2 minutes
    setInterval(() => {
        if (!globalFatigueDetector) return;

        const status = globalFatigueDetector.getCurrentLevel();
        const recommendation = status.recommendation;

        if (recommendation) {
            handleFatigueRecommendation(recommendation);
        }

        // Mettre à jour l'indicateur UI
        updateFatigueIndicator(status);
    }, 120000); // 2 minutes
}

/**
 * Gérer une recommandation de fatigue
 */
function handleFatigueRecommendation(recommendation) {
    switch (recommendation.action) {
        case 'suggest':
            // Notification légère
            showFatigueNotification(recommendation.message, 'info');
            break;

        case 'encourage':
            // Notification plus insistante
            showFatigueNotification(recommendation.message, 'warning');
            // Proposer un bouton pause
            showBreakButton();
            break;

        case 'force':
            // Pause forcée
            forceMandatoryBreak(recommendation.duration, recommendation.message);
            break;
    }
}

/**
 * Afficher une notification de fatigue
 */
function showFatigueNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fatigue-notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <p>${message}</p>
            <button onclick="takeFatigueBreak()" class="btn btn-primary btn-sm">
                Prendre une pause
            </button>
            <button onclick="dismissFatigueNotification()" class="btn btn-secondary btn-sm">
                Continuer
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    // Auto-dismiss après 30 secondes si pas d'action
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 30000);
}

/**
 * Afficher le bouton pause
 */
function showBreakButton() {
    let breakButton = document.getElementById('fatigue-break-button');

    if (!breakButton) {
        breakButton = document.createElement('button');
        breakButton.id = 'fatigue-break-button';
        breakButton.className = 'btn-floating fatigue-break-btn';
        breakButton.innerHTML = '⏸️ Pause';
        breakButton.onclick = takeFatigueBreak;
        document.body.appendChild(breakButton);
    }

    breakButton.style.display = 'block';
}

/**
 * Forcer une pause obligatoire
 */
function forceMandatoryBreak(duration, message) {
    const overlay = document.createElement('div');
    overlay.id = 'mandatory-break-overlay';
    overlay.className = 'break-overlay';

    const endTime = Date.now() + duration;

    overlay.innerHTML = `
        <div class="break-content">
            <h2>🛑 Pause obligatoire</h2>
            <p>${message}</p>
            <div class="break-timer" id="break-timer">
                <span class="timer-display">10:00</span>
            </div>
            <p class="break-tips">
                💡 Conseils pendant la pause :<br>
                • Regarde au loin pour reposer tes yeux<br>
                • Étire-toi un peu<br>
                • Bois de l'eau<br>
                • Fais quelques respirations profondes
            </p>
        </div>
    `;

    document.body.appendChild(overlay);

    // Timer countdown
    const timerInterval = setInterval(() => {
        const remaining = endTime - Date.now();

        if (remaining <= 0) {
            clearInterval(timerInterval);
            endMandatoryBreak(overlay, duration);
        } else {
            const minutes = Math.floor(remaining / 60000);
            const seconds = Math.floor((remaining % 60000) / 1000);
            const timerDisplay = document.querySelector('#break-timer .timer-display');
            if (timerDisplay) {
                timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
        }
    }, 1000);
}

/**
 * Terminer la pause obligatoire
 */
function endMandatoryBreak(overlay, duration) {
    if (globalFatigueDetector) {
        globalFatigueDetector.recordBreak(duration);
    }

    overlay.innerHTML = `
        <div class="break-content">
            <h2>✅ Pause terminée !</h2>
            <p>Tu es prêt à reprendre. Bon courage !</p>
            <button onclick="resumeAfterBreak()" class="btn btn-primary">
                Reprendre
            </button>
        </div>
    `;
}

/**
 * Reprendre après une pause
 */
function resumeAfterBreak() {
    const overlay = document.getElementById('mandatory-break-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Prendre une pause volontaire
 */
function takeFatigueBreak() {
    const duration = prompt('Durée de la pause (en minutes) :', '5');

    if (!duration || isNaN(duration)) return;

    const durationMs = parseInt(duration) * 60000;

    if (globalFatigueDetector) {
        globalFatigueDetector.recordBreak(durationMs);
    }

    alert(`Pause de ${duration} minutes enregistrée. Bon repos ! 😊`);

    dismissFatigueNotification();
}

/**
 * Dismisser la notification
 */
function dismissFatigueNotification() {
    const notifications = document.querySelectorAll('.fatigue-notification');
    notifications.forEach(n => n.remove());
}

/**
 * Mettre à jour l'indicateur de fatigue dans l'UI
 */
function updateFatigueIndicator(status) {
    let indicator = document.getElementById('fatigue-indicator');

    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'fatigue-indicator';
        indicator.className = 'fatigue-indicator';
        document.body.appendChild(indicator);
    }

    indicator.style.backgroundColor = status.level.color;
    indicator.innerHTML = `
        <span class="fatigue-label">${status.level.label}</span>
        <span class="fatigue-score">Score: ${status.score}/100</span>
    `;
    indicator.title = status.level.description;
}

/**
 * Obtenir le détecteur global
 */
function getFatigueDetector() {
    if (!globalFatigueDetector) {
        globalFatigueDetector = initFatigueDetection();
    }
    return globalFatigueDetector;
}

// Export pour usage global
window.FatigueDetector = FatigueDetector;
window.initFatigueDetection = initFatigueDetection;
window.getFatigueDetector = getFatigueDetector;
window.takeFatigueBreak = takeFatigueBreak;
window.dismissFatigueNotification = dismissFatigueNotification;
window.resumeAfterBreak = resumeAfterBreak;
window.FATIGUE_LEVELS = FATIGUE_LEVELS;
