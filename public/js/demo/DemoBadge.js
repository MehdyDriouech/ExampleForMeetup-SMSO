/**
 * DemoBadge.js - Sprint 20B
 * Composant pour afficher un badge "DEMO DATA" sur les écrans en mode démo
 */

class DemoBadge {
    constructor() {
        this.isDemoMode = localStorage.getItem('DEMO_SESSION') === 'true';
        this.badges = new Map();
    }

    /**
     * Vérifie si le mode démo est actif
     */
    isActive() {
        return this.isDemoMode;
    }

    /**
     * Crée et ajoute un badge à un élément
     * @param {string} elementId - ID de l'élément auquel ajouter le badge
     * @param {object} options - Options du badge (position, style, etc.)
     */
    addBadge(elementId, options = {}) {
        if (!this.isDemoMode) return;

        const element = document.getElementById(elementId);
        if (!element) {
            console.warn(`[DemoBadge] Element #${elementId} not found`);
            return;
        }

        // Options par défaut
        const config = {
            position: 'top-right', // top-right, top-left, bottom-right, bottom-left, inline
            text: 'DEMO DATA',
            color: '#f59e0b', // Orange
            size: 'small', // small, medium, large
            style: 'badge', // badge, banner, corner
            ...options
        };

        const badge = this.createBadgeElement(config);

        // Positionner le badge selon la configuration
        if (config.position === 'inline') {
            element.insertBefore(badge, element.firstChild);
        } else {
            // Assurer que l'élément parent a position: relative
            if (window.getComputedStyle(element).position === 'static') {
                element.style.position = 'relative';
            }
            element.appendChild(badge);
        }

        this.badges.set(elementId, badge);
    }

    /**
     * Crée l'élément HTML du badge
     */
    createBadgeElement(config) {
        const badge = document.createElement('div');
        badge.className = `demo-badge demo-badge-${config.style} demo-badge-${config.position} demo-badge-${config.size}`;

        // Styles de base
        const baseStyles = {
            backgroundColor: config.color,
            color: 'white',
            fontWeight: '600',
            fontSize: this.getFontSize(config.size),
            padding: this.getPadding(config.size),
            borderRadius: '6px',
            zIndex: '1000',
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px',
            boxShadow: '0 2px 4px rgba(0, 0, 0, 0.1)'
        };

        // Styles de positionnement
        const positionStyles = this.getPositionStyles(config.position, config.style);

        // Appliquer tous les styles
        Object.assign(badge.style, baseStyles, positionStyles);

        // Icône et texte
        if (config.style === 'badge' || config.style === 'banner') {
            badge.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 1L10.5 6L16 7L12 11L13 16L8 13.5L3 16L4 11L0 7L5.5 6L8 1Z" fill="currentColor"/>
                </svg>
                <span>${config.text}</span>
            `;
        } else if (config.style === 'corner') {
            badge.innerHTML = `<span>${config.text}</span>`;
        }

        return badge;
    }

    /**
     * Retourne les styles de positionnement selon la position
     */
    getPositionStyles(position, style) {
        if (position === 'inline') {
            return {
                position: 'relative',
                marginBottom: '12px'
            };
        }

        // Pour les badges absolus
        const styles = {
            position: 'absolute'
        };

        switch (position) {
            case 'top-right':
                styles.top = '12px';
                styles.right = '12px';
                break;
            case 'top-left':
                styles.top = '12px';
                styles.left = '12px';
                break;
            case 'bottom-right':
                styles.bottom = '12px';
                styles.right = '12px';
                break;
            case 'bottom-left':
                styles.bottom = '12px';
                styles.left = '12px';
                break;
        }

        return styles;
    }

    /**
     * Retourne la taille de police selon la taille du badge
     */
    getFontSize(size) {
        switch (size) {
            case 'small':
                return '11px';
            case 'medium':
                return '13px';
            case 'large':
                return '15px';
            default:
                return '11px';
        }
    }

    /**
     * Retourne le padding selon la taille du badge
     */
    getPadding(size) {
        switch (size) {
            case 'small':
                return '4px 8px';
            case 'medium':
                return '6px 12px';
            case 'large':
                return '8px 16px';
            default:
                return '4px 8px';
        }
    }

    /**
     * Supprime un badge
     */
    removeBadge(elementId) {
        const badge = this.badges.get(elementId);
        if (badge) {
            badge.remove();
            this.badges.delete(elementId);
        }
    }

    /**
     * Supprime tous les badges
     */
    removeAllBadges() {
        this.badges.forEach(badge => badge.remove());
        this.badges.clear();
    }

    /**
     * Ajoute des badges automatiquement aux sections de nouveaux sprints
     */
    autoAddBadges() {
        if (!this.isDemoMode) return;

        // Liste des éléments à badger automatiquement
        const elementsToTag = [
            { id: 'curriculum-content', text: 'CURRICULUM DÉMO', position: 'top-right' },
            { id: 'curriculum-builder', text: 'DONNÉES DÉMO', position: 'inline' },
            { id: 'student-path', text: 'PARCOURS DÉMO', position: 'inline' },
            { id: 'theme-validation-content', text: 'WORKFLOW DÉMO', position: 'top-right' },
            { id: 'theme-versions', text: 'VERSIONS DÉMO', position: 'inline' },
            { id: 'annotations-panel', text: 'ANNOTATIONS DÉMO', position: 'inline' },
            { id: 'admin-onboarding-content', text: 'ONBOARDING DÉMO', position: 'top-right' },
            { id: 'tenant-config', text: 'CONFIG DÉMO', position: 'inline' },
            { id: 'import-preview', text: 'IMPORT DÉMO', position: 'inline' }
        ];

        // Attendre que le DOM soit chargé
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.applyAutoBadges(elementsToTag);
            });
        } else {
            this.applyAutoBadges(elementsToTag);
        }
    }

    /**
     * Applique les badges automatiques
     */
    applyAutoBadges(elements) {
        elements.forEach(({ id, text, position }) => {
            // Utiliser un observer pour détecter quand l'élément apparaît
            const observer = new MutationObserver(() => {
                const element = document.getElementById(id);
                if (element && !this.badges.has(id)) {
                    this.addBadge(id, { text, position, size: 'small' });
                }
            });

            // Observer le body pour détecter les nouveaux éléments
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            // Essayer immédiatement
            if (document.getElementById(id)) {
                this.addBadge(id, { text, position, size: 'small' });
            }
        });
    }

    /**
     * Affiche un bandeau de démonstration global
     */
    showGlobalBanner() {
        if (!this.isDemoMode) return;

        const existingBanner = document.getElementById('global-demo-banner');
        if (existingBanner) return; // Déjà présent

        const banner = document.createElement('div');
        banner.id = 'global-demo-banner';
        banner.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            color: white;
            padding: 12px 24px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            z-index: 10000;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        `;

        banner.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: center; gap: 16px;">
                <span>⚠️ MODE DÉMONSTRATION - Toutes les données affichées sont fictives</span>
                <button id="exit-demo-btn" style="
                    background: rgba(255, 255, 255, 0.2);
                    border: 1px solid white;
                    color: white;
                    padding: 6px 12px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 13px;
                    font-weight: 500;
                ">
                    Quitter le mode démo
                </button>
            </div>
        `;

        document.body.appendChild(banner);

        // Décaler le contenu pour ne pas masquer le bandeau
        document.body.style.paddingTop = '48px';

        // Gérer le bouton de sortie
        document.getElementById('exit-demo-btn').addEventListener('click', () => {
            if (confirm('Voulez-vous vraiment quitter le mode démonstration ?')) {
                localStorage.removeItem('DEMO_SESSION');
                window.location.reload();
            }
        });
    }
}

// Instance singleton
window.demoBadge = new DemoBadge();

// Auto-activation si en mode démo
if (window.demoBadge.isActive()) {
    window.demoBadge.showGlobalBanner();
    window.demoBadge.autoAddBadges();
}

export default window.demoBadge;
