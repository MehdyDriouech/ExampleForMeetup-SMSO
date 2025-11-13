/**
 * Sprint 13 - US13-5: System Diagnostic Dashboard
 * Real-time system health monitoring and diagnostics
 *
 * @module diagnostic
 */

class DiagnosticDashboard {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.diagnosticData = null;
        this.refreshInterval = null;
        this.autoRefresh = false;
    }

    /**
     * Initialize dashboard
     */
    async init() {
        this.render();
        await this.runDiagnostic();
    }

    /**
     * Run system diagnostic
     */
    async runDiagnostic() {
        this.showLoading();

        try {
            const response = await apiCall('/api/system/diagnostic', 'GET');
            this.diagnosticData = response;
            this.render();
        } catch (error) {
            this.showError(error.message || 'Failed to run diagnostic');
        }
    }

    /**
     * Show loading state
     */
    showLoading() {
        const loadingArea = document.getElementById('diagnostic-content');
        if (loadingArea) {
            loadingArea.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #666;">
                    <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #2563eb; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="margin-top: 16px;">Analyse du système en cours...</p>
                </div>
            `;
        }
    }

    /**
     * Show error message
     */
    showError(message) {
        this.container.innerHTML = `
            <div class="diagnostic-error" style="background: #fee; padding: 20px; border-radius: 8px; border-left: 4px solid #ef4444;">
                <strong style="color: #dc2626;">Erreur</strong>
                <p style="margin: 8px 0 0 0; color: #666;">${message}</p>
                <button onclick="diagnosticDashboard.runDiagnostic()" style="margin-top: 12px; padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    Réessayer
                </button>
            </div>
        `;
    }

    /**
     * Render full dashboard
     */
    render() {
        this.container.innerHTML = `
            <div class="diagnostic-dashboard">
                ${this.renderHeader()}
                <div id="diagnostic-content">
                    ${this.diagnosticData ? this.renderContent() : '<p style="text-align: center; color: #666; padding: 40px;">Cliquez sur "Lancer le diagnostic" pour commencer</p>'}
                </div>
            </div>
        `;

        this.attachEventHandlers();
    }

    /**
     * Render header with controls
     */
    renderHeader() {
        return `
            <div class="diagnostic-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px; border-radius: 12px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h2 style="margin: 0; font-size: 24px;">Diagnostic Système</h2>
                        <p style="margin: 8px 0 0 0; opacity: 0.9;">Surveillance de la santé de StudyMate</p>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button id="btn-run-diagnostic" style="padding: 10px 20px; background: white; color: #667eea; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            🔍 Lancer le diagnostic
                        </button>
                        <button id="btn-toggle-refresh" style="padding: 10px 20px; background: rgba(255,255,255,0.2); color: white; border: 1px solid white; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            ${this.autoRefresh ? '⏸ Pause' : '▶ Auto-refresh'}
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render diagnostic content
     */
    renderContent() {
        if (!this.diagnosticData) return '';

        const data = this.diagnosticData;
        const statusInfo = this.getStatusInfo(data.status);

        return `
            <div class="diagnostic-content">
                ${this.renderStatusCard(data.status, statusInfo, data.timestamp)}
                ${this.renderChecksGrid(data.checks)}
                ${this.renderBackupSection()}
            </div>
        `;
    }

    /**
     * Render overall status card
     */
    renderStatusCard(status, statusInfo, timestamp) {
        return `
            <div class="status-card" style="background: ${statusInfo.bgColor}; padding: 24px; border-radius: 12px; border-left: 6px solid ${statusInfo.color}; margin-bottom: 24px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="font-size: 48px;">${statusInfo.icon}</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0; font-size: 20px; color: ${statusInfo.color};">
                            ${statusInfo.label}
                        </h3>
                        <p style="margin: 8px 0 0 0; color: #666;">
                            Dernière vérification : ${new Date(timestamp).toLocaleString('fr-FR')}
                        </p>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render checks grid
     */
    renderChecksGrid(checks) {
        const checkEntries = Object.entries(checks);

        return `
            <div class="checks-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 16px; margin-bottom: 24px;">
                ${checkEntries.map(([key, check]) => this.renderCheckCard(key, check)).join('')}
            </div>
        `;
    }

    /**
     * Render individual check card
     */
    renderCheckCard(key, check) {
        const statusInfo = this.getCheckStatusInfo(check.status);
        const title = this.formatCheckTitle(key);

        return `
            <div class="check-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-top: 4px solid ${statusInfo.color};">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                    <h4 style="margin: 0; font-size: 16px; color: #1a1a1a;">${title}</h4>
                    <span style="font-size: 24px;">${statusInfo.icon}</span>
                </div>
                <div style="color: #666; font-size: 14px; line-height: 1.6;">
                    ${check.message}
                </div>
                ${this.renderCheckDetails(check)}
            </div>
        `;
    }

    /**
     * Render check-specific details
     */
    renderCheckDetails(check) {
        const details = [];

        if (check.latency_ms !== undefined) {
            details.push(`<strong>Latence:</strong> ${check.latency_ms}ms`);
        }
        if (check.provider !== undefined) {
            details.push(`<strong>Fournisseur:</strong> ${check.provider}`);
        }
        if (check.table_count !== undefined) {
            details.push(`<strong>Tables:</strong> ${check.table_count}`);
        }
        if (check.used_percent !== undefined) {
            const color = check.used_percent > 80 ? '#ef4444' : '#10b981';
            details.push(`<strong>Utilisation:</strong> <span style="color: ${color};">${check.used_percent}%</span>`);
            details.push(`<strong>Espace libre:</strong> ${check.free_gb} GB / ${check.total_gb} GB`);
        }
        if (check.version !== undefined) {
            details.push(`<strong>Version:</strong> ${check.version}`);
        }
        if (check.memory_limit !== undefined) {
            details.push(`<strong>Mémoire:</strong> ${check.memory_limit}`);
        }
        if (check.backup_count !== undefined) {
            details.push(`<strong>Sauvegardes:</strong> ${check.backup_count}`);
        }

        if (details.length === 0) return '';

        return `
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; font-size: 13px; color: #666;">
                ${details.join(' • ')}
            </div>
        `;
    }

    /**
     * Render backup management section
     */
    renderBackupSection() {
        return `
            <div class="backup-section" style="background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="margin: 0; font-size: 18px;">
                        💾 Gestion des Sauvegardes
                    </h3>
                    <button id="btn-create-backup" style="padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        Créer une sauvegarde
                    </button>
                </div>
                <div id="backup-list">
                    <p style="text-align: center; color: #666; padding: 20px;">Chargement des sauvegardes...</p>
                </div>
            </div>
        `;
    }

    /**
     * Load and render backup list
     */
    async loadBackupList() {
        try {
            const response = await apiCall('/api/system/backups', 'GET');
            const backupListEl = document.getElementById('backup-list');

            if (!backupListEl) return;

            if (response.backups.length === 0) {
                backupListEl.innerHTML = `
                    <p style="text-align: center; color: #666; padding: 20px;">
                        Aucune sauvegarde disponible
                    </p>
                `;
                return;
            }

            backupListEl.innerHTML = `
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #eee;">
                            <th style="text-align: left; padding: 12px; font-weight: 600; color: #666;">Nom</th>
                            <th style="text-align: left; padding: 12px; font-weight: 600; color: #666;">Date</th>
                            <th style="text-align: left; padding: 12px; font-weight: 600; color: #666;">Taille</th>
                            <th style="text-align: left; padding: 12px; font-weight: 600; color: #666;">Âge</th>
                            <th style="text-align: right; padding: 12px; font-weight: 600; color: #666;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${response.backups.map(backup => `
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 12px; font-family: monospace; font-size: 13px;">${backup.name}</td>
                                <td style="padding: 12px;">${new Date(backup.created_at).toLocaleDateString('fr-FR')}</td>
                                <td style="padding: 12px;">${backup.size_human}</td>
                                <td style="padding: 12px;">${backup.age_days} jours</td>
                                <td style="padding: 12px; text-align: right;">
                                    <a href="/api/system/backup/${backup.name}/download"
                                       style="padding: 6px 12px; background: #2563eb; color: white; border-radius: 4px; text-decoration: none; font-size: 13px;">
                                        ⬇ Télécharger
                                    </a>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (error) {
            console.error('Failed to load backups:', error);
        }
    }

    /**
     * Create manual backup
     */
    async createBackup() {
        const btn = document.getElementById('btn-create-backup');
        if (!btn) return;

        btn.disabled = true;
        btn.textContent = 'Création en cours...';

        try {
            const response = await apiCall('/api/system/backup', 'POST', {
                force: true
            });

            if (response.success) {
                alert(`✓ Sauvegarde créée avec succès\n\nNom: ${response.backup_name}\nTaille: ${response.size_human}`);
                await this.loadBackupList();
            } else {
                alert('✗ Échec de la sauvegarde: ' + (response.error || 'Erreur inconnue'));
            }
        } catch (error) {
            alert('✗ Erreur: ' + error.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Créer une sauvegarde';
        }
    }

    /**
     * Toggle auto-refresh
     */
    toggleAutoRefresh() {
        this.autoRefresh = !this.autoRefresh;

        if (this.autoRefresh) {
            this.refreshInterval = setInterval(() => {
                this.runDiagnostic();
            }, 30000); // Refresh every 30 seconds
        } else {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        }

        this.render();
    }

    /**
     * Attach event handlers
     */
    attachEventHandlers() {
        const runBtn = document.getElementById('btn-run-diagnostic');
        const toggleBtn = document.getElementById('btn-toggle-refresh');
        const createBackupBtn = document.getElementById('btn-create-backup');

        if (runBtn) {
            runBtn.addEventListener('click', () => this.runDiagnostic());
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggleAutoRefresh());
        }

        if (createBackupBtn) {
            createBackupBtn.addEventListener('click', () => this.createBackup());
            // Load backup list
            this.loadBackupList();
        }
    }

    /**
     * Get status info for overall system status
     */
    getStatusInfo(status) {
        const statuses = {
            healthy: {
                label: 'Système en bonne santé',
                icon: '✅',
                color: '#10b981',
                bgColor: '#f0fdf4'
            },
            degraded: {
                label: 'Système dégradé',
                icon: '⚠️',
                color: '#f59e0b',
                bgColor: '#fffbeb'
            },
            unhealthy: {
                label: 'Système en erreur',
                icon: '❌',
                color: '#ef4444',
                bgColor: '#fef2f2'
            }
        };

        return statuses[status] || statuses.healthy;
    }

    /**
     * Get status info for individual check
     */
    getCheckStatusInfo(status) {
        const statuses = {
            ok: { icon: '✅', color: '#10b981' },
            warning: { icon: '⚠️', color: '#f59e0b' },
            error: { icon: '❌', color: '#ef4444' },
            critical: { icon: '🔴', color: '#dc2626' },
            info: { icon: 'ℹ️', color: '#3b82f6' }
        };

        return statuses[status] || statuses.info;
    }

    /**
     * Format check key to human-readable title
     */
    formatCheckTitle(key) {
        const titles = {
            database: 'Base de données',
            database_schema: 'Schéma de données',
            ai_service: 'Service IA',
            filesystem: 'Système de fichiers',
            disk_space: 'Espace disque',
            php_config: 'Configuration PHP',
            rate_limits: 'Limites API',
            backups: 'Sauvegardes'
        };

        return titles[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    /**
     * Clean up on destroy
     */
    destroy() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
    }
}

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('diagnostic-dashboard');
    if (container) {
        window.diagnosticDashboard = new DiagnosticDashboard('diagnostic-dashboard');
        diagnosticDashboard.init();
    }
});
