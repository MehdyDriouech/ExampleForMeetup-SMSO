/**
 * Sprint 12 - Pedagogical Library
 * UI Component: Catalog Theme Viewer (E12-CATALOG)
 * Consultation d'un thème du catalogue en mode lecture seule
 * Affichage: contenu, versions, historique workflow
 */

class CatalogThemeViewer {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.options = {
            apiBaseUrl: options.apiBaseUrl || '/api',
            tenantId: options.tenantId,
            catalogEntryId: options.catalogEntryId,
            userRole: options.userRole || 'teacher',
            onClose: options.onClose || (() => {}),
            onAssign: options.onAssign || (() => {}),
            ...options
        };

        this.entry = null;
        this.currentTab = 'content'; // 'content', 'versions', 'workflow'

        this.init();
    }

    async init() {
        this.render();
        await this.loadEntry();
    }

    render() {
        this.container.innerHTML = `
            <div class="theme-viewer">
                <!-- Header -->
                <div class="viewer-header">
                    <button class="btn-back" id="btn-back">
                        <i class="icon-arrow-left"></i> Retour au catalogue
                    </button>
                    <div class="header-actions">
                        <button class="btn btn-secondary" id="btn-export">
                            <i class="icon-download"></i> Exporter
                        </button>
                        <button class="btn btn-primary" id="btn-assign">
                            <i class="icon-assign"></i> Affecter à une classe
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div id="viewer-content" class="viewer-content">
                    <div class="loading-state">
                        <div class="spinner"></div>
                        <p>Chargement du thème...</p>
                    </div>
                </div>
            </div>
        `;

        this.attachHeaderEvents();
    }

    attachHeaderEvents() {
        document.getElementById('btn-back').addEventListener('click', () => {
            this.options.onClose();
        });

        document.getElementById('btn-export').addEventListener('click', () => {
            this.exportTheme();
        });

        document.getElementById('btn-assign').addEventListener('click', () => {
            this.options.onAssign(this.entry);
        });
    }

    async loadEntry() {
        try {
            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/${this.options.catalogEntryId}`,
                {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load catalog entry');
            }

            const data = await response.json();
            this.entry = data.catalog_entry;

            this.renderEntry();

        } catch (error) {
            console.error('Error loading entry:', error);
            this.showError('Erreur lors du chargement du thème');
        }
    }

    renderEntry() {
        const content = document.getElementById('viewer-content');

        content.innerHTML = `
            <!-- Theme Info -->
            <div class="theme-info">
                <div class="theme-header-main">
                    <h1>${this.escapeHtml(this.entry.title)}</h1>
                    <div class="theme-badges">
                        ${this.getStatusBadge(this.entry.workflow_status)}
                        ${this.getDifficultyBadge(this.entry.difficulty)}
                        <span class="badge badge-info">${this.entry.version_label || 'v1.0'}</span>
                    </div>
                </div>
                <p class="theme-description">${this.escapeHtml(this.entry.description || '')}</p>

                <div class="theme-metadata">
                    <div class="meta-row">
                        <span class="meta-label">Auteur:</span>
                        <span class="meta-value">${this.entry.author_name || 'Anonyme'}</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Matière:</span>
                        <span class="meta-value">${this.entry.subject || 'N/A'}</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Niveau:</span>
                        <span class="meta-value">${this.entry.level || 'N/A'}</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Difficulté:</span>
                        <span class="meta-value">${this.entry.difficulty || 'N/A'}</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Dernière mise à jour:</span>
                        <span class="meta-value">${this.formatDate(this.entry.updated_at)}</span>
                    </div>
                </div>

                <div class="theme-tags">
                    ${this.entry.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                </div>
            </div>

            <!-- Tabs -->
            <div class="viewer-tabs">
                <button class="tab-btn active" data-tab="content">
                    <i class="icon-book"></i> Contenu
                </button>
                <button class="tab-btn" data-tab="versions">
                    <i class="icon-version"></i> Versions (${this.entry.versions.length})
                </button>
                <button class="tab-btn" data-tab="workflow">
                    <i class="icon-history"></i> Historique (${this.entry.workflow_history.length})
                </button>
            </div>

            <!-- Tab Content -->
            <div class="tab-content">
                <div id="tab-content" class="tab-pane active">
                    ${this.renderThemeContent()}
                </div>
                <div id="tab-versions" class="tab-pane hidden">
                    ${this.renderVersionsTab()}
                </div>
                <div id="tab-workflow" class="tab-pane hidden">
                    ${this.renderWorkflowTab()}
                </div>
            </div>
        `;

        this.attachTabEvents();
    }

    renderThemeContent() {
        const content = this.entry.theme_content || {};

        let html = '<div class="theme-content-view">';

        // Questions (Quiz)
        if (content.questions && content.questions.length > 0) {
            html += `
                <section class="content-section">
                    <h2><i class="icon-quiz"></i> Quiz (${content.questions.length} questions)</h2>
                    <div class="questions-list">
                        ${content.questions.map((q, i) => this.renderQuestion(q, i)).join('')}
                    </div>
                </section>
            `;
        }

        // Flashcards
        if (content.flashcards && content.flashcards.length > 0) {
            html += `
                <section class="content-section">
                    <h2><i class="icon-cards"></i> Flashcards (${content.flashcards.length})</h2>
                    <div class="flashcards-grid">
                        ${content.flashcards.map(fc => this.renderFlashcard(fc)).join('')}
                    </div>
                </section>
            `;
        }

        // Fiche de révision
        if (content.fiche && content.fiche.sections) {
            html += `
                <section class="content-section">
                    <h2><i class="icon-fiche"></i> Fiche de révision</h2>
                    <div class="fiche-content">
                        ${content.fiche.sections.map(s => this.renderFicheSection(s)).join('')}
                    </div>
                </section>
            `;
        }

        // Annales
        if (content.annales && content.annales.length > 0) {
            html += `
                <section class="content-section">
                    <h2><i class="icon-exam"></i> Annales (${content.annales.length})</h2>
                    <div class="annales-list">
                        ${content.annales.map(a => this.renderAnnale(a)).join('')}
                    </div>
                </section>
            `;
        }

        html += '</div>';

        return html;
    }

    renderQuestion(question, index) {
        return `
            <div class="question-item">
                <div class="question-header">
                    <span class="question-number">Q${index + 1}</span>
                    ${question.difficulty ? `<span class="badge badge-sm">${question.difficulty}</span>` : ''}
                </div>
                <div class="question-text">${this.escapeHtml(question.text)}</div>
                <div class="question-choices">
                    ${question.choices.map((choice, i) => `
                        <div class="choice ${i === question.correctAnswer ? 'correct' : ''}">
                            <span class="choice-letter">${String.fromCharCode(65 + i)}</span>
                            <span class="choice-text">${this.escapeHtml(choice)}</span>
                            ${i === question.correctAnswer ? '<i class="icon-check"></i>' : ''}
                        </div>
                    `).join('')}
                </div>
                ${question.explanation ? `
                    <div class="question-explanation">
                        <strong>Explication:</strong> ${this.escapeHtml(question.explanation)}
                    </div>
                ` : ''}
            </div>
        `;
    }

    renderFlashcard(flashcard) {
        return `
            <div class="flashcard">
                <div class="flashcard-front">
                    <div class="flashcard-label">Recto</div>
                    <div class="flashcard-content">${this.escapeHtml(flashcard.front)}</div>
                </div>
                <div class="flashcard-back">
                    <div class="flashcard-label">Verso</div>
                    <div class="flashcard-content">${this.escapeHtml(flashcard.back)}</div>
                </div>
            </div>
        `;
    }

    renderFicheSection(section, level = 0) {
        let html = `
            <div class="fiche-section level-${level}">
                <h${3 + level}>${this.escapeHtml(section.title)}</h${3 + level}>
                <div class="section-content">${this.escapeHtml(section.content)}</div>
        `;

        if (section.keyPoints && section.keyPoints.length > 0) {
            html += `
                <div class="key-points">
                    <strong>Points clés:</strong>
                    <ul>
                        ${section.keyPoints.map(p => `<li>${this.escapeHtml(p)}</li>`).join('')}
                    </ul>
                </div>
            `;
        }

        if (section.subsections && section.subsections.length > 0) {
            html += section.subsections.map(s => this.renderFicheSection(s, level + 1)).join('');
        }

        html += '</div>';
        return html;
    }

    renderAnnale(annale) {
        return `
            <div class="annale-item">
                <h3>${this.escapeHtml(annale.title)}</h3>
                ${annale.year ? `<div class="annale-year">Année: ${annale.year}</div>` : ''}
                ${annale.duration ? `<div class="annale-duration">Durée: ${annale.duration} min</div>` : ''}
                <div class="annale-questions">
                    ${annale.questions.length} question(s)
                </div>
            </div>
        `;
    }

    renderVersionsTab() {
        if (!this.entry.versions || this.entry.versions.length === 0) {
            return '<div class="empty-message">Aucune version disponible</div>';
        }

        return `
            <div class="versions-list">
                ${this.entry.versions.map(v => `
                    <div class="version-item ${v.id === this.entry.current_version_id ? 'current' : ''}">
                        <div class="version-header">
                            <div class="version-info">
                                <span class="version-label">${v.version_label}</span>
                                ${v.id === this.entry.current_version_id ? '<span class="badge badge-success">Actuelle</span>' : ''}
                            </div>
                            <div class="version-date">${this.formatDate(v.created_at)}</div>
                        </div>
                        <div class="version-summary">${this.escapeHtml(v.change_summary || 'Aucune description')}</div>
                        <div class="version-author">Par: ${v.user_name || 'Anonyme'}</div>
                        ${v.id !== this.entry.current_version_id ? `
                            <button class="btn btn-sm btn-secondary btn-restore" data-version="${v.id}">
                                <i class="icon-restore"></i> Restaurer
                            </button>
                        ` : ''}
                    </div>
                `).join('')}
            </div>
        `;
    }

    renderWorkflowTab() {
        if (!this.entry.workflow_history || this.entry.workflow_history.length === 0) {
            return '<div class="empty-message">Aucun historique disponible</div>';
        }

        return `
            <div class="workflow-timeline">
                ${this.entry.workflow_history.map(h => `
                    <div class="timeline-item">
                        <div class="timeline-marker ${h.to_status}"></div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-status">${this.getStatusLabel(h.from_status)} → ${this.getStatusLabel(h.to_status)}</span>
                                <span class="timeline-date">${this.formatDate(h.created_at)}</span>
                            </div>
                            <div class="timeline-user">Par: ${h.user_name || 'Anonyme'}</div>
                            ${h.comment ? `<div class="timeline-comment">${this.escapeHtml(h.comment)}</div>` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    attachTabEvents() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const tab = e.currentTarget.dataset.tab;
                this.switchTab(tab);
            });
        });
    }

    switchTab(tab) {
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });

        // Update panes
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.add('hidden');
        });

        document.getElementById(`tab-${tab}`).classList.remove('hidden');
        this.currentTab = tab;
    }

    async exportTheme() {
        const dataStr = JSON.stringify(this.entry.theme_content, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);

        const link = document.createElement('a');
        link.href = url;
        link.download = `${this.entry.title.replace(/[^a-z0-9]/gi, '_')}.json`;
        link.click();

        URL.revokeObjectURL(url);
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
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
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
    module.exports = CatalogThemeViewer;
}
