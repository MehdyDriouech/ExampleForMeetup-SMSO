/**
 * Sprint 15 - Admin IA Governance View
 *
 * UI for managing IA policies, budgets, and audit logs
 */

class AdminIAView {
    constructor() {
        this.apiBaseUrl = '/api/admin';
        this.currentTab = 'policy';
        this.policy = null;
        this.budgets = [];
        this.auditLogs = [];
    }

    /**
     * Initialize the view
     */
    async init() {
        this.renderLayout();
        this.attachEventListeners();
        await this.loadPolicy();
        await this.loadBudgets();
        await this.loadAuditLogs();
    }

    /**
     * Render main layout
     */
    renderLayout() {
        const container = document.getElementById('app');
        container.innerHTML = `
            <div class="admin-ia-governance">
                <header class="page-header">
                    <h1>🤖 Gouvernance IA</h1>
                    <p class="subtitle">Gestion des politiques, budgets et audit des interactions IA</p>
                </header>

                <nav class="tabs">
                    <button class="tab-btn active" data-tab="policy">Politique IA</button>
                    <button class="tab-btn" data-tab="budgets">Budgets</button>
                    <button class="tab-btn" data-tab="audit">Journal d'audit</button>
                    <button class="tab-btn" data-tab="stats">Statistiques</button>
                </nav>

                <div class="tab-content">
                    <div id="policy-tab" class="tab-pane active">
                        <div id="policy-content">
                            <div class="loading">Chargement...</div>
                        </div>
                    </div>

                    <div id="budgets-tab" class="tab-pane">
                        <div id="budgets-content">
                            <div class="loading">Chargement...</div>
                        </div>
                    </div>

                    <div id="audit-tab" class="tab-pane">
                        <div id="audit-content">
                            <div class="loading">Chargement...</div>
                        </div>
                    </div>

                    <div id="stats-tab" class="tab-pane">
                        <div id="stats-content">
                            <div class="loading">Chargement...</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Tab navigation
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.switchTab(e.target.dataset.tab);
            });
        });
    }

    /**
     * Switch tab
     */
    switchTab(tabName) {
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabName);
        });

        // Update panes
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('active');
        });
        document.getElementById(`${tabName}-tab`).classList.add('active');

        this.currentTab = tabName;

        // Load data if needed
        if (tabName === 'stats') {
            this.loadStats();
        }
    }

    /**
     * Load IA policy
     */
    async loadPolicy() {
        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-policy`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                }
            });

            if (!response.ok) throw new Error('Failed to load policy');

            const data = await response.json();
            this.policy = data.data;
            this.renderPolicy();
        } catch (error) {
            console.error('Error loading policy:', error);
            this.showError('policy-content', 'Erreur lors du chargement de la politique IA');
        }
    }

    /**
     * Render policy tab
     */
    renderPolicy() {
        const content = document.getElementById('policy-content');
        const policy = this.policy;

        content.innerHTML = `
            <div class="policy-container">
                <!-- Kill Switch -->
                <div class="card">
                    <h2>🔴 Kill Switch - Activation/Désactivation IA</h2>
                    <div class="toggle-section">
                        <label class="toggle-switch">
                            <input type="checkbox" id="ia-enabled" ${policy.ia_enabled ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                        <div>
                            <strong>IA ${policy.ia_enabled ? 'ACTIVÉE' : 'DÉSACTIVÉE'}</strong>
                            <p class="help-text">
                                ${policy.ia_enabled
                                    ? 'Les fonctionnalités IA sont accessibles aux enseignants.'
                                    : 'Les fonctionnalités IA sont bloquées pour tous les utilisateurs.'
                                }
                            </p>
                        </div>
                    </div>

                    ${!policy.ia_enabled ? `
                        <div class="warning-box">
                            <strong>⚠️ IA désactivée</strong>
                            <p>Raison: ${policy.ia_disabled_reason || 'Non spécifiée'}</p>
                            <p>Désactivée le: ${policy.ia_disabled_at ? new Date(policy.ia_disabled_at).toLocaleString('fr-FR') : 'N/A'}</p>
                        </div>
                    ` : ''}

                    <button id="save-kill-switch" class="btn btn-primary">
                        Enregistrer
                    </button>
                </div>

                <!-- BYOK Configuration -->
                <div class="card">
                    <h2>🔑 BYOK - Bring Your Own Key</h2>
                    <div class="form-group">
                        <label class="toggle-switch">
                            <input type="checkbox" id="byok-enabled" ${policy.byok_enabled ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                        <label for="byok-enabled">Utiliser ma propre clé API</label>
                    </div>

                    <div id="byok-config" style="display: ${policy.byok_enabled ? 'block' : 'none'}">
                        <div class="form-group">
                            <label for="api-provider">Fournisseur API</label>
                            <select id="api-provider">
                                <option value="openai" ${policy.api_provider === 'openai' ? 'selected' : ''}>OpenAI</option>
                                <option value="anthropic" ${policy.api_provider === 'anthropic' ? 'selected' : ''}>Anthropic</option>
                                <option value="azure" ${policy.api_provider === 'azure' ? 'selected' : ''}>Azure OpenAI</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="api-key">Clé API</label>
                            <input type="password" id="api-key" placeholder="sk-..." class="form-control">
                            <p class="help-text">La clé sera chiffrée avant stockage</p>
                        </div>
                    </div>

                    <button id="save-byok" class="btn btn-primary">
                        Enregistrer BYOK
                    </button>
                </div>

                <!-- Model Configuration -->
                <div class="card">
                    <h2>🎛️ Configuration des modèles</h2>
                    <div class="form-group">
                        <label for="default-model">Modèle par défaut</label>
                        <select id="default-model" class="form-control">
                            <option value="gpt-4o-mini" ${policy.default_model === 'gpt-4o-mini' ? 'selected' : ''}>GPT-4O Mini (rapide, économique)</option>
                            <option value="gpt-4o" ${policy.default_model === 'gpt-4o' ? 'selected' : ''}>GPT-4O (performant)</option>
                            <option value="claude-3-5-sonnet" ${policy.default_model === 'claude-3-5-sonnet' ? 'selected' : ''}>Claude 3.5 Sonnet</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Modèles autorisés</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" value="gpt-4o-mini" ${this.isModelAllowed('gpt-4o-mini')}> GPT-4O Mini</label>
                            <label><input type="checkbox" value="gpt-4o" ${this.isModelAllowed('gpt-4o')}> GPT-4O</label>
                            <label><input type="checkbox" value="claude-3-5-sonnet" ${this.isModelAllowed('claude-3-5-sonnet')}> Claude 3.5 Sonnet</label>
                        </div>
                    </div>

                    <button id="save-models" class="btn btn-primary">
                        Enregistrer la configuration
                    </button>
                </div>

                <!-- Content Filter -->
                <div class="card">
                    <h2>🛡️ Filtrage de contenu</h2>
                    <div class="form-group">
                        <label for="content-filter">Niveau de filtrage</label>
                        <select id="content-filter" class="form-control">
                            <option value="none" ${policy.content_filter_level === 'none' ? 'selected' : ''}>Aucun</option>
                            <option value="low" ${policy.content_filter_level === 'low' ? 'selected' : ''}>Faible</option>
                            <option value="medium" ${policy.content_filter_level === 'medium' ? 'selected' : ''}>Moyen (recommandé)</option>
                            <option value="high" ${policy.content_filter_level === 'high' ? 'selected' : ''}>Élevé</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="retention-days">Durée de conservation des logs (jours)</label>
                        <input type="number" id="retention-days" value="${policy.data_retention_days}" min="1" max="365" class="form-control">
                    </div>

                    <button id="save-compliance" class="btn btn-primary">
                        Enregistrer
                    </button>
                </div>
            </div>
        `;

        this.attachPolicyEventListeners();
    }

    /**
     * Check if model is allowed
     */
    isModelAllowed(model) {
        if (!this.policy || !this.policy.allowed_models) return '';
        return this.policy.allowed_models.includes(model) ? 'checked' : '';
    }

    /**
     * Attach policy event listeners
     */
    attachPolicyEventListeners() {
        // Toggle BYOK config visibility
        document.getElementById('byok-enabled').addEventListener('change', (e) => {
            document.getElementById('byok-config').style.display = e.target.checked ? 'block' : 'none';
        });

        // Save kill switch
        document.getElementById('save-kill-switch').addEventListener('click', () => {
            this.saveKillSwitch();
        });

        // Save BYOK
        document.getElementById('save-byok').addEventListener('click', () => {
            this.saveBYOK();
        });

        // Save models
        document.getElementById('save-models').addEventListener('click', () => {
            this.saveModels();
        });

        // Save compliance
        document.getElementById('save-compliance').addEventListener('click', () => {
            this.saveCompliance();
        });
    }

    /**
     * Save kill switch
     */
    async saveKillSwitch() {
        const iaEnabled = document.getElementById('ia-enabled').checked;
        const reason = !iaEnabled ? prompt('Raison de la désactivation:') : null;

        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-policy`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                },
                body: JSON.stringify({
                    ia_enabled: iaEnabled,
                    ia_disabled_reason: reason
                })
            });

            if (!response.ok) throw new Error('Failed to save');

            alert('✅ Kill switch mis à jour');
            await this.loadPolicy();
        } catch (error) {
            console.error('Error saving kill switch:', error);
            alert('❌ Erreur lors de la sauvegarde');
        }
    }

    /**
     * Save BYOK
     */
    async saveBYOK() {
        const byokEnabled = document.getElementById('byok-enabled').checked;
        const apiProvider = document.getElementById('api-provider').value;
        const apiKey = document.getElementById('api-key').value;

        if (byokEnabled && !apiKey) {
            alert('Veuillez saisir une clé API');
            return;
        }

        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-policy`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                },
                body: JSON.stringify({
                    byok_enabled: byokEnabled,
                    api_provider: apiProvider,
                    api_key: apiKey
                })
            });

            if (!response.ok) throw new Error('Failed to save');

            alert('✅ Configuration BYOK enregistrée');
            await this.loadPolicy();
        } catch (error) {
            console.error('Error saving BYOK:', error);
            alert('❌ Erreur lors de la sauvegarde');
        }
    }

    /**
     * Save models configuration
     */
    async saveModels() {
        const defaultModel = document.getElementById('default-model').value;
        const allowedModels = Array.from(document.querySelectorAll('.checkbox-group input:checked'))
            .map(cb => cb.value);

        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-policy`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                },
                body: JSON.stringify({
                    default_model: defaultModel,
                    allowed_models: allowedModels
                })
            });

            if (!response.ok) throw new Error('Failed to save');

            alert('✅ Configuration des modèles enregistrée');
            await this.loadPolicy();
        } catch (error) {
            console.error('Error saving models:', error);
            alert('❌ Erreur lors de la sauvegarde');
        }
    }

    /**
     * Save compliance settings
     */
    async saveCompliance() {
        const contentFilter = document.getElementById('content-filter').value;
        const retentionDays = parseInt(document.getElementById('retention-days').value);

        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-policy`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                },
                body: JSON.stringify({
                    content_filter_level: contentFilter,
                    data_retention_days: retentionDays
                })
            });

            if (!response.ok) throw new Error('Failed to save');

            alert('✅ Paramètres de conformité enregistrés');
            await this.loadPolicy();
        } catch (error) {
            console.error('Error saving compliance:', error);
            alert('❌ Erreur lors de la sauvegarde');
        }
    }

    /**
     * Load budgets
     */
    async loadBudgets() {
        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-budgets`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                }
            });

            if (!response.ok) throw new Error('Failed to load budgets');

            const data = await response.json();
            this.budgets = data.data;
            this.renderBudgets();
        } catch (error) {
            console.error('Error loading budgets:', error);
            this.showError('budgets-content', 'Erreur lors du chargement des budgets');
        }
    }

    /**
     * Render budgets tab
     */
    renderBudgets() {
        const content = document.getElementById('budgets-content');

        const tenantBudgets = this.budgets.filter(b => b.budget_type === 'tenant');
        const teacherBudgets = this.budgets.filter(b => b.budget_type === 'teacher');

        content.innerHTML = `
            <div class="budgets-container">
                <div class="section-header">
                    <h2>💰 Budgets IA</h2>
                    <button id="create-budget" class="btn btn-primary">+ Créer un budget</button>
                </div>

                <h3>Budget Tenant</h3>
                <div class="budget-list">
                    ${tenantBudgets.map(b => this.renderBudgetCard(b)).join('')}
                </div>

                <h3>Budgets Enseignants</h3>
                <div class="budget-list">
                    ${teacherBudgets.map(b => this.renderBudgetCard(b)).join('')}
                </div>
            </div>
        `;

        document.getElementById('create-budget').addEventListener('click', () => {
            this.showCreateBudgetModal();
        });
    }

    /**
     * Render budget card
     */
    renderBudgetCard(budget) {
        const usagePercent = budget.usage_percent || 0;
        const statusClass = budget.status === 'exceeded' ? 'danger' : usagePercent >= 80 ? 'warning' : 'success';

        return `
            <div class="budget-card">
                <div class="budget-header">
                    <strong>${budget.budget_type === 'tenant' ? '🏢 Tenant' : '👤 ' + budget.user_email}</strong>
                    <span class="badge badge-${statusClass}">${budget.status}</span>
                </div>
                <div class="budget-progress">
                    <div class="progress-bar">
                        <div class="progress-fill ${statusClass}" style="width: ${Math.min(usagePercent, 100)}%"></div>
                    </div>
                    <p>${budget.used_tokens.toLocaleString()} / ${budget.max_tokens.toLocaleString()} tokens (${usagePercent.toFixed(1)}%)</p>
                </div>
                <div class="budget-meta">
                    <p>Période: ${new Date(budget.period_start).toLocaleDateString('fr-FR')} - ${new Date(budget.period_end).toLocaleDateString('fr-FR')}</p>
                </div>
            </div>
        `;
    }

    /**
     * Load audit logs
     */
    async loadAuditLogs() {
        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-audit?limit=50`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                }
            });

            if (!response.ok) throw new Error('Failed to load audit logs');

            const data = await response.json();
            this.auditLogs = data.data;
            this.renderAuditLogs(data);
        } catch (error) {
            console.error('Error loading audit logs:', error);
            this.showError('audit-content', 'Erreur lors du chargement des logs d\'audit');
        }
    }

    /**
     * Render audit logs tab
     */
    renderAuditLogs(data) {
        const content = document.getElementById('audit-content');

        content.innerHTML = `
            <div class="audit-container">
                <div class="audit-stats">
                    <div class="stat-card">
                        <h3>${data.stats.total_requests || 0}</h3>
                        <p>Requêtes totales</p>
                    </div>
                    <div class="stat-card">
                        <h3>${(data.stats.total_tokens || 0).toLocaleString()}</h3>
                        <p>Tokens utilisés</p>
                    </div>
                    <div class="stat-card">
                        <h3>${(data.stats.avg_latency_ms || 0).toFixed(0)}ms</h3>
                        <p>Latence moyenne</p>
                    </div>
                </div>

                <h3>📋 Logs récents</h3>
                <div class="audit-logs-list">
                    ${this.auditLogs.map(log => this.renderAuditLogRow(log)).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Render audit log row
     */
    renderAuditLogRow(log) {
        const statusEmoji = log.status === 'success' ? '✅' : '❌';
        return `
            <div class="audit-log-row">
                <div class="log-header">
                    <span>${statusEmoji} ${log.user_email || log.user_id}</span>
                    <span class="log-date">${new Date(log.created_at).toLocaleString('fr-FR')}</span>
                </div>
                <div class="log-body">
                    <p><strong>Modèle:</strong> ${log.model_used}</p>
                    <p><strong>Tokens:</strong> ${log.tokens_total}</p>
                    <p><strong>Contexte:</strong> ${log.context_type || 'N/A'}</p>
                    <details>
                        <summary>Voir le prompt</summary>
                        <pre>${log.prompt_text}</pre>
                    </details>
                </div>
            </div>
        `;
    }

    /**
     * Load stats
     */
    async loadStats() {
        const content = document.getElementById('stats-content');
        content.innerHTML = '<div class="loading">Chargement des statistiques...</div>';

        try {
            const response = await fetch(`${this.apiBaseUrl}/ia-budgets/usage`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('token')}`,
                    'X-Orchestrator-Id': localStorage.getItem('tenant_id')
                }
            });

            if (!response.ok) throw new Error('Failed to load stats');

            const data = await response.json();
            this.renderStats(data.data);
        } catch (error) {
            console.error('Error loading stats:', error);
            this.showError('stats-content', 'Erreur lors du chargement des statistiques');
        }
    }

    /**
     * Render stats tab
     */
    renderStats(data) {
        const content = document.getElementById('stats-content');

        content.innerHTML = `
            <div class="stats-container">
                <h2>📊 Statistiques d'utilisation</h2>

                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Budget Tenant</h3>
                        <p>Utilisé: ${(data.tenant_budget?.used_tokens || 0).toLocaleString()} / ${(data.tenant_budget?.max_tokens || 0).toLocaleString()}</p>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${((data.tenant_budget?.used_tokens || 0) / (data.tenant_budget?.max_tokens || 1) * 100)}%"></div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <h3>Enseignants</h3>
                        <p>Total: ${data.teacher_budgets_summary?.total_teachers || 0}</p>
                        <p>Dépassements: ${data.teacher_budgets_summary?.budgets_exceeded || 0}</p>
                    </div>
                </div>

                <h3>Utilisation sur 30 jours</h3>
                <div class="chart-container">
                    ${this.renderUsageChart(data.recent_usage)}
                </div>
            </div>
        `;
    }

    /**
     * Render usage chart (simple text-based for now)
     */
    renderUsageChart(usageData) {
        if (!usageData || usageData.length === 0) {
            return '<p>Aucune donnée disponible</p>';
        }

        return `
            <table class="usage-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Requêtes</th>
                        <th>Tokens</th>
                    </tr>
                </thead>
                <tbody>
                    ${usageData.map(row => `
                        <tr>
                            <td>${new Date(row.date).toLocaleDateString('fr-FR')}</td>
                            <td>${row.requests}</td>
                            <td>${row.tokens_used?.toLocaleString() || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    /**
     * Show error message
     */
    showError(containerId, message) {
        const container = document.getElementById(containerId);
        container.innerHTML = `
            <div class="error-box">
                <p>❌ ${message}</p>
            </div>
        `;
    }

    /**
     * Show create budget modal (placeholder)
     */
    showCreateBudgetModal() {
        alert('Fonctionnalité de création de budget à implémenter');
    }
}

// Initialize when DOM is ready
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        const adminIAView = new AdminIAView();
        adminIAView.init();
    });
}
