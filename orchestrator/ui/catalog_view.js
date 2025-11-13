/**
 * Sprint 12 - Pedagogical Library
 * UI Component: Internal Catalog View (E12-CATALOG)
 * Catalogue interne de thèmes validés et partagés au niveau établissement
 */

class InternalCatalog {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.options = {
            apiBaseUrl: options.apiBaseUrl || '/api',
            tenantId: options.tenantId,
            userRole: options.userRole || 'teacher',
            onThemeView: options.onThemeView || (() => {}),
            onThemeAssign: options.onThemeAssign || (() => {}),
            ...options
        };

        this.catalogEntries = [];
        this.currentView = 'grid'; // 'grid' ou 'list'
        this.filters = {
            search: '',
            subject: '',
            level: '',
            difficulty: '',
            status: 'published', // Par défaut: thèmes publiés
            tags: ''
        };

        this.init();
    }

    init() {
        this.render();
        this.attachEventListeners();
        this.loadCatalog();
        this.loadStats();
    }

    render() {
        this.container.innerHTML = `
            <div class="catalog-container">
                <!-- Header -->
                <div class="catalog-header">
                    <div class="header-title">
                        <h2>📚 Catalogue Pédagogique Interne</h2>
                        <p class="subtitle">Thèmes validés et partagés au niveau établissement</p>
                    </div>
                    <div class="header-actions">
                        ${this.renderHeaderActions()}
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="catalog-toolbar">
                    <div class="search-box">
                        <i class="icon-search"></i>
                        <input type="text" id="catalog-search" placeholder="Rechercher par titre, tags, matière..." />
                    </div>
                    <div class="toolbar-filters">
                        <select id="filter-subject">
                            <option value="">Toutes les matières</option>
                            <option value="mathematiques">Mathématiques</option>
                            <option value="francais">Français</option>
                            <option value="histoire_geo">Histoire-Géo</option>
                            <option value="sciences">Sciences</option>
                            <option value="anglais">Anglais</option>
                            <option value="physique_chimie">Physique-Chimie</option>
                            <option value="svt">SVT</option>
                        </select>
                        <select id="filter-level">
                            <option value="">Tous les niveaux</option>
                            <option value="6eme">6ème</option>
                            <option value="5eme">5ème</option>
                            <option value="4eme">4ème</option>
                            <option value="3eme">3ème</option>
                            <option value="seconde">Seconde</option>
                            <option value="premiere">Première</option>
                            <option value="terminale">Terminale</option>
                        </select>
                        <select id="filter-difficulty">
                            <option value="">Toutes les difficultés</option>
                            <option value="beginner">Débutant</option>
                            <option value="intermediate">Intermédiaire</option>
                            <option value="advanced">Avancé</option>
                        </select>
                        ${this.renderStatusFilter()}
                    </div>
                    <div class="view-toggle">
                        <button id="btn-view-grid" class="btn btn-icon active" title="Vue grille">
                            <i class="icon-grid"></i>
                        </button>
                        <button id="btn-view-list" class="btn btn-icon" title="Vue liste">
                            <i class="icon-list"></i>
                        </button>
                    </div>
                </div>

                <!-- Layout -->
                <div class="catalog-layout">
                    <!-- Sidebar Stats -->
                    <aside class="catalog-sidebar">
                        <div class="sidebar-section">
                            <h3>Statistiques</h3>
                            <div id="catalog-stats" class="stats-panel">
                                <div class="stat-loading">Chargement...</div>
                            </div>
                        </div>
                        <div class="sidebar-section">
                            <h3>Top Tags</h3>
                            <div id="top-tags" class="tags-cloud"></div>
                        </div>
                    </aside>

                    <!-- Main Content -->
                    <main class="catalog-main">
                        <div id="catalog-entries" class="catalog-grid"></div>
                        <div id="loading-indicator" class="loading hidden">Chargement...</div>
                        <div id="empty-state" class="empty-state hidden">
                            <i class="icon-empty"></i>
                            <p>Aucun thème disponible dans le catalogue</p>
                        </div>
                    </main>
                </div>
            </div>
        `;
    }

    renderHeaderActions() {
        const actions = [];

        // Bouton "Mes contributions" pour les enseignants
        if (['teacher', 'admin', 'direction'].includes(this.options.userRole)) {
            actions.push(`
                <button id="btn-my-contributions" class="btn btn-secondary">
                    <i class="icon-user"></i> Mes contributions
                </button>
            `);
        }

        // Bouton "Validation" pour les référents
        if (['referent', 'admin', 'direction'].includes(this.options.userRole)) {
            actions.push(`
                <button id="btn-validation-queue" class="btn btn-warning">
                    <i class="icon-check"></i> À valider <span id="validation-count" class="badge">0</span>
                </button>
            `);
        }

        return actions.join('');
    }

    renderStatusFilter() {
        // Filtres de statut selon le rôle
        if (['referent', 'admin', 'direction'].includes(this.options.userRole)) {
            return `
                <select id="filter-status">
                    <option value="published">Publiés</option>
                    <option value="proposed">Proposés</option>
                    <option value="validated">Validés</option>
                    <option value="draft">Brouillons</option>
                    <option value="rejected">Rejetés</option>
                    <option value="archived">Archivés</option>
                    <option value="">Tous les statuts</option>
                </select>
            `;
        } else {
            return `
                <select id="filter-status">
                    <option value="published">Publiés</option>
                    <option value="">Tous les statuts</option>
                </select>
            `;
        }
    }

    attachEventListeners() {
        // Recherche
        const searchInput = document.getElementById('catalog-search');
        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.filters.search = e.target.value;
                this.loadCatalog();
            }, 300);
        });

        // Filtres
        ['filter-subject', 'filter-level', 'filter-difficulty', 'filter-status'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', (e) => {
                    const filterKey = id.replace('filter-', '');
                    this.filters[filterKey] = e.target.value;
                    this.loadCatalog();
                });
            }
        });

        // Vue toggle
        document.getElementById('btn-view-grid').addEventListener('click', () => {
            this.setView('grid');
        });

        document.getElementById('btn-view-list').addEventListener('click', () => {
            this.setView('list');
        });

        // Actions header
        const btnMyContrib = document.getElementById('btn-my-contributions');
        if (btnMyContrib) {
            btnMyContrib.addEventListener('click', () => {
                this.showMyContributions();
            });
        }

        const btnValidation = document.getElementById('btn-validation-queue');
        if (btnValidation) {
            btnValidation.addEventListener('click', () => {
                this.showValidationQueue();
            });
        }
    }

    async loadCatalog() {
        this.showLoading(true);

        try {
            const params = new URLSearchParams();
            Object.entries(this.filters).forEach(([key, value]) => {
                if (value) params.append(key, value);
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
                throw new Error('Failed to load catalog');
            }

            const data = await response.json();
            this.catalogEntries = data.catalog_entries || [];

            this.renderCatalogEntries();

        } catch (error) {
            console.error('Error loading catalog:', error);
            this.showError('Erreur lors du chargement du catalogue');
        } finally {
            this.showLoading(false);
        }
    }

    async loadStats() {
        try {
            const response = await fetch(
                `${this.options.apiBaseUrl}/catalog/stats`,
                {
                    headers: {
                        'Authorization': `Bearer ${localStorage.getItem('jwt_token')}`,
                        'X-Tenant-Id': this.options.tenantId
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load stats');
            }

            const data = await response.json();
            this.renderStats(data);

        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }

    renderStats(data) {
        const statsContainer = document.getElementById('catalog-stats');
        const byStatus = data.by_status || {};

        statsContainer.innerHTML = `
            <div class="stat-item">
                <div class="stat-value">${data.total_entries || 0}</div>
                <div class="stat-label">Total thèmes</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${byStatus.published || 0}</div>
                <div class="stat-label">Publiés</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${byStatus.proposed || 0}</div>
                <div class="stat-label">En attente</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${byStatus.validated || 0}</div>
                <div class="stat-label">Validés</div>
            </div>
        `;

        // Mettre à jour le badge de validation
        const badge = document.getElementById('validation-count');
        if (badge) {
            badge.textContent = byStatus.proposed || 0;
        }
    }

    renderCatalogEntries() {
        const container = document.getElementById('catalog-entries');
        const emptyState = document.getElementById('empty-state');

        if (this.catalogEntries.length === 0) {
            container.innerHTML = '';
            emptyState.classList.remove('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        container.className = this.currentView === 'grid' ? 'catalog-grid' : 'catalog-list';

        container.innerHTML = this.catalogEntries.map(entry => {
            return this.renderCatalogCard(entry);
        }).join('');

        // Attacher les événements
        this.attachCardEvents();
    }

    renderCatalogCard(entry) {
        const statusBadge = this.getStatusBadge(entry.workflow_status);
        const difficultyBadge = this.getDifficultyBadge(entry.difficulty);
        const tags = JSON.parse(entry.tags || '[]');

        return `
            <div class="catalog-card" data-id="${entry.id}">
                <div class="card-header">
                    <div class="card-badges">
                        ${statusBadge}
                        ${difficultyBadge}
                    </div>
                    <div class="card-menu">
                        <button class="btn-icon btn-menu" data-id="${entry.id}">⋮</button>
                    </div>
                </div>
                <div class="card-body">
                    <h3 class="card-title">${this.escapeHtml(entry.title)}</h3>
                    <p class="card-description">${this.escapeHtml(entry.description || '')}</p>
                    <div class="card-meta">
                        <span class="meta-item">
                            <i class="icon-book"></i> ${entry.subject || 'N/A'}
                        </span>
                        <span class="meta-item">
                            <i class="icon-level"></i> ${entry.level || 'N/A'}
                        </span>
                        <span class="meta-item">
                            <i class="icon-user"></i> ${entry.author_name || 'Anonyme'}
                        </span>
                    </div>
                    <div class="card-tags">
                        ${tags.slice(0, 3).map(tag => `<span class="tag">${tag}</span>`).join('')}
                        ${tags.length > 3 ? `<span class="tag-more">+${tags.length - 3}</span>` : ''}
                    </div>
                    <div class="card-version">
                        <i class="icon-version"></i> ${entry.version_label || 'v1.0'}
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-sm btn-primary btn-view" data-id="${entry.id}">
                        <i class="icon-eye"></i> Consulter
                    </button>
                    ${this.renderCardActions(entry)}
                </div>
            </div>
        `;
    }

    renderCardActions(entry) {
        const actions = [];

        // Action: Affecter à une classe (si publié)
        if (entry.workflow_status === 'published') {
            actions.push(`
                <button class="btn btn-sm btn-secondary btn-assign" data-id="${entry.id}">
                    <i class="icon-assign"></i> Affecter
                </button>
            `);
        }

        return actions.join('');
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

    getDifficultyBadge(difficulty) {
        const badges = {
            beginner: '<span class="badge badge-info">Débutant</span>',
            intermediate: '<span class="badge badge-warning">Intermédiaire</span>',
            advanced: '<span class="badge badge-danger">Avancé</span>'
        };

        return badges[difficulty] || '';
    }

    attachCardEvents() {
        // Boutons "Consulter"
        document.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = e.currentTarget.dataset.id;
                this.viewTheme(id);
            });
        });

        // Boutons "Affecter"
        document.querySelectorAll('.btn-assign').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = e.currentTarget.dataset.id;
                this.assignTheme(id);
            });
        });
    }

    async viewTheme(catalogId) {
        this.options.onThemeView(catalogId);
    }

    async assignTheme(catalogId) {
        this.options.onThemeAssign(catalogId);
    }

    showMyContributions() {
        // Rediriger vers la vue "mes contributions"
        window.location.href = '/catalog/my-contributions';
    }

    showValidationQueue() {
        // Rediriger vers la vue "validation"
        window.location.href = '/catalog/validation';
    }

    setView(view) {
        this.currentView = view;

        document.getElementById('btn-view-grid').classList.toggle('active', view === 'grid');
        document.getElementById('btn-view-list').classList.toggle('active', view === 'list');

        this.renderCatalogEntries();
    }

    showLoading(show) {
        const loading = document.getElementById('loading-indicator');
        loading.classList.toggle('hidden', !show);
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
    module.exports = InternalCatalog;
}
