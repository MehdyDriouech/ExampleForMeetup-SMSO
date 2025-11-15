/**
 * Demo Tour - Parcours guidé interactif (Sprint 17)
 * Guide l'utilisateur à travers les fonctionnalités principales
 */

class DemoTour {
    constructor() {
        this.currentStep = 0;
        this.steps = [
            {
                title: "Bienvenue dans StudyMate Orchestrator !",
                content: "Ce parcours guidé va vous faire découvrir les principales fonctionnalités de la plateforme. Cliquez sur 'Suivant' pour continuer.",
                target: null,
                action: null
            },
            {
                title: "Dashboard Enseignant",
                content: "Le dashboard vous donne une vue d'ensemble de vos classes : nombre d'élèves, scores moyens, taux de maîtrise. Toutes ces données sont mises à jour en temps réel.",
                target: "#dashboard-content",
                action: () => window.navigateTo('dashboard')
            },
            {
                title: "Suivi des élèves",
                content: "Consultez la liste de vos élèves par classe, suivez leurs progrès individuels et identifiez ceux qui ont besoin d'aide.",
                target: "#students-content",
                action: () => window.navigateTo('students')
            },
            {
                title: "Gestion des affectations",
                content: "Créez et planifiez des activités (quiz, flashcards) pour vos classes. Suivez le taux de complétion et les scores moyens.",
                target: "#assignments-content",
                action: () => window.navigateTo('assignments')
            },
            {
                title: "Synchronisation ErgoMate",
                content: "Synchronisez les données avec l'application élève ErgoMate. Les statistiques sont rapatriées automatiquement.",
                target: "#sync-content",
                action: () => window.navigateTo('sync')
            },
            {
                title: "Curriculum Builder (Sprint 18)",
                content: "Créez des parcours d'apprentissage personnalisés avec le Curriculum Builder. Organisez vos programmes en séquences pédagogiques et suivez la progression de chaque élève de manière individuelle.",
                target: "#curriculum-content",
                action: () => window.navigateTo('curriculum')
            },
            {
                title: "Workflow Multi-acteurs (Sprint 19)",
                content: "Collaborez avec vos collègues grâce au workflow de validation à plusieurs niveaux. Ajoutez des annotations, consultez l'historique des versions et assurez la qualité pédagogique de vos contenus.",
                target: "#theme-validation-content",
                action: () => window.navigateTo('theme-validation')
            },
            {
                title: "Onboarding Tenant (Sprint 20)",
                content: "Les administrateurs peuvent facilement créer de nouveaux établissements, importer des élèves et enseignants via CSV, et configurer les paramètres institutionnels en quelques clics.",
                target: "#admin-onboarding-content",
                action: () => window.navigateTo('admin-onboarding')
            },
            {
                title: "Analytics & Qualité",
                content: "StudyMate intègre des analytics avancés : KPI enseignants, détection des élèves à risque, analyse de qualité des contenus, gouvernance IA et RGPD.",
                target: null,
                action: null
            },
            {
                title: "C'est terminé !",
                content: "Vous pouvez maintenant explorer librement l'application. Toutes les données que vous voyez sont fictives. Pour quitter le mode démo, cliquez sur le lien dans le bandeau orange en haut de l'écran.",
                target: "#demo-banner",
                action: null
            }
        ];

        this.overlay = null;
        this.tooltip = null;
    }

    /**
     * Démarre le tour
     */
    start() {
        if (this.overlay) {
            return; // Tour déjà en cours
        }

        this.currentStep = 0;
        this.createOverlay();
        this.showStep();
    }

    /**
     * Crée l'overlay et le tooltip
     */
    createOverlay() {
        // Overlay semi-transparent
        this.overlay = document.createElement('div');
        this.overlay.id = 'demo-tour-overlay';
        this.overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9998;
        `;
        document.body.appendChild(this.overlay);

        // Tooltip
        this.tooltip = document.createElement('div');
        this.tooltip.id = 'demo-tour-tooltip';
        this.tooltip.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 12px;
            padding: 24px;
            max-width: 500px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            z-index: 9999;
        `;
        document.body.appendChild(this.tooltip);
    }

    /**
     * Affiche une étape
     */
    showStep() {
        const step = this.steps[this.currentStep];

        // Exécuter l'action si définie
        if (step.action) {
            step.action();
            // Attendre que la vue se charge
            setTimeout(() => {
                this.renderStep(step);
            }, 300);
        } else {
            this.renderStep(step);
        }
    }

    /**
     * Affiche le contenu d'une étape
     */
    renderStep(step) {
        // Mise en surbrillance de l'élément ciblé
        if (step.target) {
            const element = document.querySelector(step.target);
            if (element) {
                element.style.position = 'relative';
                element.style.zIndex = '10000';
                element.style.background = 'white';
                element.style.boxShadow = '0 0 0 4px #3b82f6';
            }
        }

        // Contenu du tooltip
        this.tooltip.innerHTML = `
            <div style="margin-bottom: 16px;">
                <h3 style="margin: 0 0 8px 0; color: #1f2937; font-size: 20px;">
                    ${step.title}
                </h3>
                <div style="color: #6b7280; font-size: 14px; line-height: 1.6;">
                    ${step.content}
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="color: #9ca3af; font-size: 12px;">
                    Étape ${this.currentStep + 1} sur ${this.steps.length}
                </div>
                <div style="display: flex; gap: 8px;">
                    <button onclick="window.demoTour.skip()" style="
                        padding: 8px 16px;
                        background: #e5e7eb;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        color: #374151;
                    ">
                        Passer
                    </button>
                    ${this.currentStep > 0 ? `
                        <button onclick="window.demoTour.previous()" style="
                            padding: 8px 16px;
                            background: #e5e7eb;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 14px;
                            color: #374151;
                        ">
                            Précédent
                        </button>
                    ` : ''}
                    <button onclick="window.demoTour.next()" style="
                        padding: 8px 16px;
                        background: #3b82f6;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-size: 14px;
                        color: white;
                        font-weight: 500;
                    ">
                        ${this.currentStep === this.steps.length - 1 ? 'Terminer' : 'Suivant'}
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Nettoie la mise en surbrillance
     */
    clearHighlight() {
        const step = this.steps[this.currentStep];
        if (step.target) {
            const element = document.querySelector(step.target);
            if (element) {
                element.style.position = '';
                element.style.zIndex = '';
                element.style.background = '';
                element.style.boxShadow = '';
            }
        }
    }

    /**
     * Étape suivante
     */
    next() {
        this.clearHighlight();

        if (this.currentStep < this.steps.length - 1) {
            this.currentStep++;
            this.showStep();
        } else {
            this.finish();
        }
    }

    /**
     * Étape précédente
     */
    previous() {
        this.clearHighlight();

        if (this.currentStep > 0) {
            this.currentStep--;
            this.showStep();
        }
    }

    /**
     * Passer le tour
     */
    skip() {
        if (confirm('Voulez-vous vraiment quitter le parcours guidé ?')) {
            this.finish();
        }
    }

    /**
     * Termine le tour
     */
    finish() {
        this.clearHighlight();

        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }

        if (this.tooltip) {
            this.tooltip.remove();
            this.tooltip = null;
        }

        // Marquer le tour comme complété
        localStorage.setItem('DEMO_TOUR_COMPLETED', 'true');
    }

    /**
     * Vérifie si le tour a déjà été complété
     */
    static hasCompleted() {
        return localStorage.getItem('DEMO_TOUR_COMPLETED') === 'true';
    }

    /**
     * Réinitialise le tour
     */
    static reset() {
        localStorage.removeItem('DEMO_TOUR_COMPLETED');
    }
}

// Instance singleton
window.demoTour = new DemoTour();

// Export
export default window.demoTour;
