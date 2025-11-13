/**
 * Sprint 13 - US13-1: Teacher Onboarding Module
 * Provides interactive walkthrough for first-time users
 *
 * @module onboarding
 * @requires ../lib/auth.php (for user_first_login flag)
 */

class OnboardingManager {
    constructor() {
        this.steps = [
            {
                id: 'welcome',
                title: 'Bienvenue sur StudyMate',
                description: 'Créez des quiz intelligents en quelques clics grâce à l\'IA',
                target: null,
                content: this.renderWelcome()
            },
            {
                id: 'create-theme',
                title: 'Créer un thème',
                description: 'Générez automatiquement des questions à partir de vos documents',
                target: '#btn-create-theme',
                content: this.renderThemeCreation()
            },
            {
                id: 'validate-theme',
                title: 'Valider le contenu',
                description: 'Vérifiez et corrigez les questions générées par l\'IA',
                target: '#theme-validation-area',
                content: this.renderValidation()
            },
            {
                id: 'assign-theme',
                title: 'Affecter aux élèves',
                description: 'Distribuez le quiz à vos classes',
                target: '#btn-assign',
                content: this.renderAssignment()
            },
            {
                id: 'monitor',
                title: 'Suivre les résultats',
                description: 'Consultez les statistiques en temps réel',
                target: '#stats-dashboard',
                content: this.renderMonitoring()
            }
        ];

        this.currentStep = 0;
        this.overlay = null;
        this.tooltip = null;
    }

    /**
     * Check if user needs onboarding
     * @returns {Promise<boolean>}
     */
    async shouldShowOnboarding() {
        try {
            const response = await apiCall('/api/user/profile', 'GET');
            return response.user.first_login === true || response.user.onboarding_completed === false;
        } catch (error) {
            console.error('Error checking onboarding status:', error);
            return false;
        }
    }

    /**
     * Start the onboarding workflow
     */
    async start() {
        const shouldShow = await this.shouldShowOnboarding();
        if (!shouldShow) return;

        this.createOverlay();
        this.showStep(0);

        // Track onboarding start
        this.trackEvent('onboarding_started');
    }

    /**
     * Create overlay and tooltip elements
     */
    createOverlay() {
        // Dark overlay
        this.overlay = document.createElement('div');
        this.overlay.className = 'onboarding-overlay';
        this.overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9998;
            transition: opacity 0.3s;
        `;
        document.body.appendChild(this.overlay);

        // Tooltip container
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'onboarding-tooltip';
        this.tooltip.style.cssText = `
            position: fixed;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            padding: 24px;
            max-width: 420px;
            z-index: 9999;
            transition: all 0.3s;
        `;
        document.body.appendChild(this.tooltip);
    }

    /**
     * Show specific onboarding step
     * @param {number} stepIndex
     */
    showStep(stepIndex) {
        if (stepIndex < 0 || stepIndex >= this.steps.length) {
            this.complete();
            return;
        }

        this.currentStep = stepIndex;
        const step = this.steps[stepIndex];

        // Clear spotlight
        this.clearSpotlight();

        // Highlight target element
        if (step.target) {
            const targetEl = document.querySelector(step.target);
            if (targetEl) {
                this.spotlight(targetEl);
            }
        }

        // Render tooltip content
        this.tooltip.innerHTML = `
            <div class="onboarding-header">
                <h3 style="margin: 0 0 8px 0; font-size: 20px; color: #1a1a1a;">
                    ${step.title}
                </h3>
                <p style="margin: 0; color: #666; font-size: 14px;">
                    Étape ${stepIndex + 1} sur ${this.steps.length}
                </p>
            </div>
            <div class="onboarding-body" style="margin: 16px 0;">
                <p style="font-size: 16px; line-height: 1.5; color: #333;">
                    ${step.description}
                </p>
                ${step.content}
            </div>
            <div class="onboarding-footer" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
                <button id="onboarding-skip" style="background: transparent; border: none; color: #999; cursor: pointer; padding: 8px 16px;">
                    Passer le tutoriel
                </button>
                <div style="display: flex; gap: 12px;">
                    ${stepIndex > 0 ? `
                        <button id="onboarding-prev" style="padding: 10px 20px; background: #f0f0f0; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                            Précédent
                        </button>
                    ` : ''}
                    <button id="onboarding-next" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                        ${stepIndex === this.steps.length - 1 ? 'Terminer' : 'Suivant'}
                    </button>
                </div>
            </div>
        `;

        // Position tooltip
        this.positionTooltip(step.target);

        // Bind events
        this.bindStepEvents();

        // Track step view
        this.trackEvent('onboarding_step_viewed', { step: step.id, index: stepIndex });
    }

    /**
     * Highlight target element with spotlight effect
     * @param {HTMLElement} element
     */
    spotlight(element) {
        const rect = element.getBoundingClientRect();

        // Create spotlight cutout effect
        element.style.position = 'relative';
        element.style.zIndex = '9999';
        element.style.boxShadow = '0 0 0 4px rgba(37, 99, 235, 0.5), 0 0 0 9999px rgba(0, 0, 0, 0.7)';
        element.style.transition = 'box-shadow 0.3s';

        element.classList.add('onboarding-spotlight');
    }

    /**
     * Remove spotlight effect
     */
    clearSpotlight() {
        const spotlighted = document.querySelectorAll('.onboarding-spotlight');
        spotlighted.forEach(el => {
            el.style.boxShadow = '';
            el.classList.remove('onboarding-spotlight');
        });
    }

    /**
     * Position tooltip relative to target or center of screen
     * @param {string|null} target
     */
    positionTooltip(target) {
        if (!target) {
            // Center on screen
            this.tooltip.style.top = '50%';
            this.tooltip.style.left = '50%';
            this.tooltip.style.transform = 'translate(-50%, -50%)';
            return;
        }

        const targetEl = document.querySelector(target);
        if (!targetEl) {
            this.positionTooltip(null);
            return;
        }

        const rect = targetEl.getBoundingClientRect();
        const tooltipWidth = 420;
        const tooltipHeight = this.tooltip.offsetHeight || 300;
        const padding = 20;

        // Try to position below target
        let top = rect.bottom + padding;
        let left = rect.left + (rect.width / 2) - (tooltipWidth / 2);

        // Adjust if off-screen
        if (top + tooltipHeight > window.innerHeight) {
            top = rect.top - tooltipHeight - padding;
        }
        if (left < padding) {
            left = padding;
        }
        if (left + tooltipWidth > window.innerWidth - padding) {
            left = window.innerWidth - tooltipWidth - padding;
        }

        this.tooltip.style.top = `${top}px`;
        this.tooltip.style.left = `${left}px`;
        this.tooltip.style.transform = 'none';
    }

    /**
     * Bind navigation events for current step
     */
    bindStepEvents() {
        const nextBtn = document.getElementById('onboarding-next');
        const prevBtn = document.getElementById('onboarding-prev');
        const skipBtn = document.getElementById('onboarding-skip');

        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.next());
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.previous());
        }
        if (skipBtn) {
            skipBtn.addEventListener('click', () => this.skip());
        }
    }

    /**
     * Navigate to next step
     */
    next() {
        this.showStep(this.currentStep + 1);
    }

    /**
     * Navigate to previous step
     */
    previous() {
        this.showStep(this.currentStep - 1);
    }

    /**
     * Skip onboarding
     */
    async skip() {
        this.trackEvent('onboarding_skipped', { at_step: this.currentStep });
        await this.markCompleted();
        this.cleanup();
    }

    /**
     * Complete onboarding
     */
    async complete() {
        this.trackEvent('onboarding_completed');
        await this.markCompleted();
        this.cleanup();

        // Show success message
        this.showSuccessMessage();
    }

    /**
     * Mark onboarding as completed in backend
     */
    async markCompleted() {
        try {
            await apiCall('/api/user/onboarding-complete', 'POST');
        } catch (error) {
            console.error('Error marking onboarding complete:', error);
        }
    }

    /**
     * Clean up overlay and tooltip
     */
    cleanup() {
        this.clearSpotlight();

        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }
    }

    /**
     * Show success message
     */
    showSuccessMessage() {
        const message = document.createElement('div');
        message.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            animation: slideIn 0.3s ease-out;
        `;
        message.innerHTML = `
            <strong>✓ Tutoriel terminé !</strong><br>
            <span style="font-size: 14px;">Vous êtes prêt à créer vos premiers quiz.</span>
        `;
        document.body.appendChild(message);

        setTimeout(() => {
            message.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => message.remove(), 300);
        }, 4000);
    }

    /**
     * Track analytics event
     * @param {string} eventName
     * @param {Object} data
     */
    trackEvent(eventName, data = {}) {
        if (typeof window.trackEvent === 'function') {
            window.trackEvent(eventName, data);
        }
    }

    // ========== Content Renderers ==========

    renderWelcome() {
        return `
            <div style="text-align: center; padding: 20px 0;">
                <div style="font-size: 48px; margin-bottom: 16px;">🎓</div>
                <p style="color: #666; margin: 0;">
                    Découvrez comment créer des quiz personnalisés<br>
                    en utilisant l'intelligence artificielle.
                </p>
            </div>
        `;
    }

    renderThemeCreation() {
        return `
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; margin-top: 12px;">
                <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #475569;">Formats supportés :</h4>
                <ul style="margin: 0; padding-left: 20px; color: #64748b; font-size: 14px;">
                    <li>Documents PDF</li>
                    <li>Texte libre</li>
                    <li>Contenus pédagogiques structurés</li>
                </ul>
            </div>
        `;
    }

    renderValidation() {
        return `
            <div style="background: #fef3c7; padding: 16px; border-radius: 8px; margin-top: 12px; border-left: 4px solid #f59e0b;">
                <p style="margin: 0; font-size: 14px; color: #92400e;">
                    <strong>💡 Conseil :</strong> Vérifiez toujours les questions générées par l'IA.
                    Corrigez les erreurs factuelles et ajustez la difficulté selon votre classe.
                </p>
            </div>
        `;
    }

    renderAssignment() {
        return `
            <div style="background: #f0fdf4; padding: 16px; border-radius: 8px; margin-top: 12px; border-left: 4px solid #10b981;">
                <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #065f46;">Statuts des thèmes :</h4>
                <ul style="margin: 0; padding-left: 20px; color: #047857; font-size: 14px; line-height: 1.6;">
                    <li><strong>Brouillon :</strong> En cours de création</li>
                    <li><strong>Validé :</strong> Prêt à être assigné</li>
                    <li><strong>Assigné :</strong> Distribué aux élèves</li>
                </ul>
            </div>
        `;
    }

    renderMonitoring() {
        return `
            <div style="background: #eff6ff; padding: 16px; border-radius: 8px; margin-top: 12px; border-left: 4px solid #2563eb;">
                <p style="margin: 0; font-size: 14px; color: #1e40af;">
                    <strong>📊 Tableau de bord :</strong> Suivez les performances de vos élèves,
                    identifiez les difficultés et adaptez votre pédagogie en temps réel.
                </p>
            </div>
        `;
    }
}

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', async () => {
    const onboarding = new OnboardingManager();
    await onboarding.start();
});

// Export for manual triggering
window.OnboardingManager = OnboardingManager;
