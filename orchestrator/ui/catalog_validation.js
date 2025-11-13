/**
 * Sprint 12 - Pedagogical Library
 * UI Component: Catalog Validation Workflow (E12-VALIDATION)
 * Interface de validation pour référents pédagogiques
 * Actions: Valider, Rejeter, Commenter
 */

class CatalogValidation {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.options = {
            apiBaseUrl: options.apiBaseUrl || '/api',
            tenantId: options.tenantId,
            userRole: options.userRole || 'referent',
            onThemeView: options.onThemeView || (() => {}),
            ...options
        };

        this.pendingThemes = [];
        this.selectedTheme = null;

        this.init();
    }

    init() {
        this.render();
        this.attachEventListeners();
        this.loadPendingThemes();
    }

    render() {
        this.container.innerHTML = `
            <div class="validation-container">
                <!-- Header -->
                <div class="validation-header">
                    <h2>⚖️ Validation des thèmes</h2>
                    <p class="subtitle">Examinez et validez les thèmes proposés par les enseignants</p>
                    <div class="header-stats">
                        <div class="stat-badge">
                            <span id="pending-count" class="stat-value">0</span>
                            <span class="stat-label">En attente</span>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="validation-tabs">
                    <button class="tab-btn active" data-status="proposed">
                        À valider
                    </button>
                    <button class="tab-btn" data-status="validated">
                        Validés
                    </button>
                    <button class="tab-btn" data-status="rejected">
                        Rejetés
                    </button>
                </div>

                <!-- Main Layout -->
                <div class="validation-layout">
                    <!-- Themes List -->
                    <aside class="themes-queue">
                        <div class="queue-header">
                            <h3>File d'attente</h3>
                            <div class="queue-filters">
                                <select id="filter-subject">
                                    <option value="">Toutes matières</option>
                                    <option value="mathematiques">Mathématiques</option>
                                    <option value="francais">Français</option>
                                    <option value="histoire_geo">Histoire-Géo</option>
                                    <option value="sciences">Sciences</option>
                                </select>
                            </div>
                        </div>
                        <div id="themes-queue-list" class="queue-list">
                            <div class="loading-state">Chargement...</div>
                        </div>
                    </aside>

                    <!-- Theme Preview & Actions -->
                    <main class="validation-main">
                        <div id="validation-content" class="validation-content">
                            <div class="empty-selection">
                                <i class="icon-select"></i>
                                <p>Sélectionnez un thème pour commencer la validation</p>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        `;
    }

    attachEventListeners() {
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const status = e.currentTarget.dataset.status;
                this.switchStatus(status);
            });
        });

        // Filter
        document.getElementById('filter-subject').addEventListener('change', () => {
            this.loadPendingThemes();
        });
    }

    switchStatus(status) {
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.status === status);
        });

        // Reload with new status
        this.loadPendingThemes(status);
    }

    async loadPendingThemes(status = 'proposed') {
        try {
            const subject = document.getElementById('filter-subject').value;
            const params = new URLSearchParams({
                status: status,
                ...(subject && { subject })
            });

            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/list?${params}`,
                {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load pending themes');
            }

            const data = await response.json();
            this.pendingThemes = data.catalog_entries || [];

            this.renderThemesList();
            this.updatePendingCount();

        } catch (error) {
            console.error('Error loading pending themes:', error);
            this.showError('Erreur lors du chargement des thèmes');
        }
    }

    renderThemesList() {
        const queueList = document.getElementById('themes-queue-list');

        if (this.pendingThemes.length === 0) {
            queueList.innerHTML = `
                <div class="empty-queue">
                    <i class="icon-check-all"></i>
                    <p>Aucun thème en attente</p>
                </div>
            `;
            return;
        }

        queueList.innerHTML = this.pendingThemes.map(theme => `
            <div class="queue-item ${this.selectedTheme?.id === theme.id ? 'selected' : ''}"
                 data-id="${theme.id}">
                <div class="queue-item-header">
                    <h4>${this.escapeHtml(theme.title)}</h4>
                    ${this.getStatusBadge(theme.workflow_status)}
                </div>
                <div class="queue-item-meta">
                    <span class="meta-author">${theme.author_name || 'Anonyme'}</span>
                    <span class="meta-subject">${theme.subject || 'N/A'}</span>
                </div>
                <div class="queue-item-date">
                    Proposé le ${this.formatDate(theme.updated_at)}
                </div>
            </div>
        `).join('');

        // Attach click events
        document.querySelectorAll('.queue-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                this.selectTheme(id);
            });
        });
    }

    async selectTheme(themeId) {
        try {
            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/${themeId}`,
                {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load theme details');
            }

            const data = await response.json();
            this.selectedTheme = data.catalog_entry;

            this.renderValidationPanel();

            // Update selected state in list
            document.querySelectorAll('.queue-item').forEach(item => {
                item.classList.toggle('selected', item.dataset.id === themeId);
            });

        } catch (error) {
            console.error('Error loading theme:', error);
            this.showError('Erreur lors du chargement du thème');
        }
    }

    renderValidationPanel() {
        const content = document.getElementById('validation-content');

        content.innerHTML = `
            <div class="validation-panel">
                <!-- Theme Summary -->
                <div class="theme-summary">
                    <h2>${this.escapeHtml(this.selectedTheme.title)}</h2>
                    <div class="summary-badges">
                        ${this.getStatusBadge(this.selectedTheme.workflow_status)}
                        ${this.getDifficultyBadge(this.selectedTheme.difficulty)}
                    </div>
                    <p class="summary-description">${this.escapeHtml(this.selectedTheme.description || '')}</p>

                    <div class="summary-metadata">
                        <div class="meta-item">
                            <strong>Auteur:</strong> ${this.selectedTheme.author_name || 'Anonyme'}
                        </div>
                        <div class="meta-item">
                            <strong>Matière:</strong> ${this.selectedTheme.subject || 'N/A'}
                        </div>
                        <div class="meta-item">
                            <strong>Niveau:</strong> ${this.selectedTheme.level || 'N/A'}
                        </div>
                        <div class="meta-item">
                            <strong>Version:</strong> ${this.selectedTheme.version_label || 'v1.0'}
                        </div>
                    </div>

                    <div class="summary-tags">
                        ${this.selectedTheme.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                    </div>
                </div>

                <!-- Theme Content Preview -->
                <div class="content-preview">
                    <h3>Aperçu du contenu</h3>
                    ${this.renderContentStats()}
                </div>

                <!-- Workflow History -->
                ${this.renderWorkflowHistory()}

                <!-- Validation Actions -->
                ${this.renderValidationActions()}
            </div>
        `;

        this.attachValidationEvents();
    }

    renderContentStats() {
        const content = this.selectedTheme.theme_content || {};

        return `
            <div class="content-stats">
                ${content.questions ? `
                    <div class="stat-card">
                        <div class="stat-icon">📝</div>
                        <div class="stat-info">
                            <div class="stat-value">${content.questions.length}</div>
                            <div class="stat-label">Questions</div>
                        </div>
                    </div>
                ` : ''}
                ${content.flashcards ? `
                    <div class="stat-card">
                        <div class="stat-icon">🃏</div>
                        <div class="stat-info">
                            <div class="stat-value">${content.flashcards.length}</div>
                            <div class="stat-label">Flashcards</div>
                        </div>
                    </div>
                ` : ''}
                ${content.fiche ? `
                    <div class="stat-card">
                        <div class="stat-icon">📄</div>
                        <div class="stat-info">
                            <div class="stat-value">${content.fiche.sections?.length || 0}</div>
                            <div class="stat-label">Sections fiche</div>
                        </div>
                    </div>
                ` : ''}
                ${content.annales ? `
                    <div class="stat-card">
                        <div class="stat-icon">📚</div>
                        <div class="stat-info">
                            <div class="stat-value">${content.annales.length}</div>
                            <div class="stat-label">Annales</div>
                        </div>
                    </div>
                ` : ''}
            </div>
            <button class="btn btn-secondary btn-full-preview" id="btn-full-preview">
                <i class="icon-eye"></i> Voir le contenu complet
            </button>
        `;
    }

    renderWorkflowHistory() {
        if (!this.selectedTheme.workflow_history || this.selectedTheme.workflow_history.length === 0) {
            return '';
        }

        return `
            <div class="workflow-section">
                <h3>Historique des actions</h3>
                <div class="workflow-timeline">
                    ${this.selectedTheme.workflow_history.slice(0, 3).map(h => `
                        <div class="timeline-item">
                            <div class="timeline-marker ${h.to_status}"></div>
                            <div class="timeline-content">
                                <div class="timeline-status">${this.getStatusLabel(h.from_status)} → ${this.getStatusLabel(h.to_status)}</div>
                                <div class="timeline-user">${h.user_name || 'Anonyme'} - ${this.formatDate(h.created_at)}</div>
                                ${h.comment ? `<div class="timeline-comment">${this.escapeHtml(h.comment)}</div>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    renderValidationActions() {
        const isProposed = this.selectedTheme.workflow_status === 'proposed';

        if (!isProposed) {
            return `
                <div class="validation-info">
                    <i class="icon-info"></i>
                    <p>Ce thème a déjà été traité (statut: ${this.getStatusLabel(this.selectedTheme.workflow_status)})</p>
                </div>
            `;
        }

        return `
            <div class="validation-actions">
                <h3>Actions de validation</h3>

                <div class="action-form">
                    <div class="form-group">
                        <label for="validation-comment">Commentaire:</label>
                        <textarea id="validation-comment" rows="4"
                                  placeholder="Ajoutez un commentaire (obligatoire en cas de rejet)..."></textarea>
                    </div>

                    <div class="action-buttons">
                        <button class="btn btn-danger" id="btn-reject">
                            <i class="icon-close"></i> Rejeter
                        </button>
                        <button class="btn btn-success" id="btn-validate">
                            <i class="icon-check"></i> Valider
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    attachValidationEvents() {
        // Full preview button
        const btnPreview = document.getElementById('btn-full-preview');
        if (btnPreview) {
            btnPreview.addEventListener('click', () => {
                this.options.onThemeView(this.selectedTheme.id);
            });
        }

        // Validation buttons
        const btnValidate = document.getElementById('btn-validate');
        if (btnValidate) {
            btnValidate.addEventListener('click', () => {
                this.validateTheme();
            });
        }

        const btnReject = document.getElementById('btn-reject');
        if (btnReject) {
            btnReject.addEventListener('click', () => {
                this.rejectTheme();
            });
        }
    }

    async validateTheme() {
        const comment = document.getElementById('validation-comment').value;

        if (!confirm('Êtes-vous sûr de vouloir valider ce thème ?')) {
            return;
        }

        try {
            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/validate`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    },
                    body: JSON.stringify({
                        catalog_entry_id: this.selectedTheme.id,
                        action: 'validate',
                        comment: comment || 'Thème validé'
                    })
                }
            );

            if (!response.ok) {
                throw new Error('Validation failed');
            }

            this.showSuccess('Thème validé avec succès');
            this.loadPendingThemes();
            this.selectedTheme = null;
            document.getElementById('validation-content').innerHTML = `
                <div class="empty-selection">
                    <i class="icon-check"></i>
                    <p>Thème validé avec succès</p>
                </div>
            `;

        } catch (error) {
            console.error('Error validating theme:', error);
            this.showError('Erreur lors de la validation du thème');
        }
    }

    async rejectTheme() {
        const comment = document.getElementById('validation-comment').value;

        if (!comment || comment.trim() === '') {
            this.showError('Un commentaire est obligatoire pour rejeter un thème');
            return;
        }

        if (!confirm('Êtes-vous sûr de vouloir rejeter ce thème ?')) {
            return;
        }

        try {
            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/validate`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    },
                    body: JSON.stringify({
                        catalog_entry_id: this.selectedTheme.id,
                        action: 'reject',
                        comment: comment
                    })
                }
            );

            if (!response.ok) {
                throw new Error('Rejection failed');
            }

            this.showSuccess('Thème rejeté');
            this.loadPendingThemes();
            this.selectedTheme = null;
            document.getElementById('validation-content').innerHTML = `
                <div class="empty-selection">
                    <i class="icon-close"></i>
                    <p>Thème rejeté</p>
                </div>
            `;

        } catch (error) {
            console.error('Error rejecting theme:', error);
            this.showError('Erreur lors du rejet du thème');
        }
    }

    updatePendingCount() {
        const count = this.pendingThemes.filter(t => t.workflow_status === 'proposed').length;
        const countEl = document.getElementById('pending-count');
        if (countEl) {
            countEl.textContent = count;
        }
    }

    getStatusBadge(status) {
        const badges = {
            draft: '<span class="badge badge-gray">Brouillon</span>',
            proposed: '<span class="badge badge-blue">Proposé</span>',
            validated: '<span class="badge badge-green">Validé</span>',
            published: '<span class="badge badge-success">Publié</span>',
            rejected: '<span class="badge badge-red">Rejeté</span>',
            archived: '<span class="badge badge-dark">Archivé</span>'
        };

        return badges[status] || '';
    }

    getStatusLabel(status) {
        const labels = {
            draft: 'Brouillon',
            proposed: 'Proposé',
            validated: 'Validé',
            published: 'Publié',
            rejected: 'Rejeté',
            archived: 'Archivé'
        };

        return labels[status] || status;
    }

    getDifficultyBadge(difficulty) {
        const badges = {
            beginner: '<span class="badge badge-info">Débutant</span>',
            intermediate: '<span class="badge badge-warning">Intermédiaire</span>',
            advanced: '<span class="badge badge-danger">Avancé</span>'
        };

        return badges[difficulty] || '';
    }

    formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr);
        return date.toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    showSuccess(message) {
        // TODO: Implémenter notification toast
        alert(message);
    }

    showError(message) {
        // TODO: Implémenter notification toast
        alert(message);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CatalogValidation;
}
