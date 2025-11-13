/**
 * Sprint 13 - US13-2 & US13-3: Theme Quality Viewer
 * Displays AI confidence scores and linting results
 *
 * @module theme_quality_viewer
 */

class ThemeQualityViewer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.themeId = null;
        this.confidenceReport = null;
        this.lintReport = null;
    }

    /**
     * Analyze theme and display results
     * @param {number} themeId
     */
    async analyze(themeId) {
        this.themeId = themeId;
        this.showLoading();

        try {
            // Run both analyses in parallel
            const [confidenceResult, lintResult] = await Promise.all([
                this.runConfidenceAnalysis(themeId),
                this.runLintAnalysis(themeId)
            ]);

            this.confidenceReport = confidenceResult;
            this.lintReport = lintResult;

            this.render();
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Run AI confidence analysis
     */
    async runConfidenceAnalysis(themeId) {
        const response = await apiCall('/api/quality/analyze', 'POST', {
            theme_id: themeId
        });

        if (!response.success) {
            throw new Error('Confidence analysis failed');
        }

        return response;
    }

    /**
     * Run content linting
     */
    async runLintAnalysis(themeId) {
        const response = await apiCall('/api/quality/lint', 'POST', {
            theme_id: themeId
        });

        if (!response.success) {
            throw new Error('Linting failed');
        }

        return response;
    }

    /**
     * Show loading state
     */
    showLoading() {
        this.container.innerHTML = `
            <div class="quality-loading" style="text-align: center; padding: 40px;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #2563eb; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 16px; color: #666;">Analyse de la qualité en cours...</p>
            </div>
        `;
    }

    /**
     * Show error message
     */
    showError(message) {
        this.container.innerHTML = `
            <div class="quality-error" style="background: #fee; padding: 20px; border-radius: 8px; border-left: 4px solid #ef4444;">
                <strong style="color: #dc2626;">Erreur</strong>
                <p style="margin: 8px 0 0 0; color: #666;">${message}</p>
            </div>
        `;
    }

    /**
     * Render full quality report
     */
    render() {
        const conf = this.confidenceReport.report;
        const lint = this.lintReport.report;
        const badge = this.confidenceReport.badge;
        const summary = this.lintReport.summary;

        this.container.innerHTML = `
            <div class="quality-viewer">
                ${this.renderHeader(conf, badge, summary)}
                ${this.renderOverview(conf, lint)}
                ${this.renderConfidenceDetails(conf)}
                ${this.renderLintDetails(lint)}
                ${this.renderRecommendations(conf)}
            </div>
        `;

        this.attachEventHandlers();
    }

    /**
     * Render header with overall status
     */
    renderHeader(conf, badge, summary) {
        return `
            <div class="quality-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px; border-radius: 12px; margin-bottom: 24px;">
                <h2 style="margin: 0 0 8px 0; font-size: 24px;">Rapport de Qualité</h2>
                <div style="display: flex; gap: 16px; margin-top: 16px;">
                    <div style="flex: 1; background: rgba(255,255,255,0.2); padding: 16px; border-radius: 8px;">
                        <div style="font-size: 14px; opacity: 0.9;">Score de Confiance IA</div>
                        <div style="font-size: 32px; font-weight: bold; margin: 8px 0;">
                            ${(conf.overall_confidence * 100).toFixed(0)}%
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">${badge.icon}</span>
                            <span>${badge.label}</span>
                        </div>
                    </div>
                    <div style="flex: 1; background: rgba(255,255,255,0.2); padding: 16px; border-radius: 8px;">
                        <div style="font-size: 14px; opacity: 0.9;">Analyse Contenu</div>
                        <div style="font-size: 20px; font-weight: bold; margin: 8px 0;">
                            ${lint.errors.length} erreurs
                        </div>
                        <div style="font-size: 16px;">
                            ${lint.warnings.length} avertissements
                        </div>
                    </div>
                </div>
                ${conf.requires_validation ? `
                    <div style="margin-top: 16px; padding: 12px; background: rgba(239, 68, 68, 0.3); border-radius: 6px; border-left: 3px solid #ef4444;">
                        ⚠ <strong>Validation humaine requise</strong>
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * Render overview stats
     */
    renderOverview(conf, lint) {
        return `
            <div class="quality-overview" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <div style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase;">Questions Analysées</div>
                    <div style="font-size: 28px; font-weight: bold; color: #2563eb; margin: 8px 0;">${lint.stats.total_questions}</div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase;">Questions à Risque</div>
                    <div style="font-size: 28px; font-weight: bold; color: #ef4444; margin: 8px 0;">${conf.risk_areas.length}</div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase;">Longueur Moyenne</div>
                    <div style="font-size: 28px; font-weight: bold; color: #10b981; margin: 8px 0;">${lint.stats.avg_question_length}</div>
                    <div style="font-size: 12px; color: #999;">caractères par question</div>
                </div>
            </div>
        `;
    }

    /**
     * Render confidence details per question
     */
    renderConfidenceDetails(conf) {
        const questions = conf.questions.slice(0, 20); // Limit to first 20

        return `
            <div class="quality-section" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                    <span>🤖</span>
                    <span>Analyse IA par Question</span>
                </h3>
                <div class="confidence-list">
                    ${questions.map((q, index) => this.renderQuestionConfidence(q, index)).join('')}
                </div>
                ${conf.questions.length > 20 ? `
                    <div style="text-align: center; margin-top: 16px; color: #666; font-size: 14px;">
                        ... et ${conf.questions.length - 20} autres questions
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * Render individual question confidence
     */
    renderQuestionConfidence(q, index) {
        const riskColor = {
            low: '#10b981',
            medium: '#f59e0b',
            high: '#ef4444'
        }[q.risk_level];

        const riskLabel = {
            low: 'Faible risque',
            medium: 'Risque moyen',
            high: 'Risque élevé'
        }[q.risk_level];

        return `
            <div class="question-confidence" style="padding: 12px; border-left: 4px solid ${riskColor}; background: ${riskColor}15; margin-bottom: 12px; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div style="font-weight: 600;">Question ${index + 1}</div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 14px; color: ${riskColor};">${riskLabel}</span>
                        <span style="font-size: 16px; font-weight: bold;">${(q.confidence_score * 100).toFixed(0)}%</span>
                    </div>
                </div>
                <div style="font-size: 14px; color: #666; margin-bottom: 8px;">
                    ${q.text_preview}${q.text_preview.length >= 100 ? '...' : ''}
                </div>
                ${q.issues.length > 0 ? `
                    <div style="margin-top: 8px;">
                        ${q.issues.map(issue => `
                            <div style="font-size: 12px; color: #666; padding: 4px 8px; background: white; border-radius: 4px; display: inline-block; margin-right: 8px; margin-bottom: 4px;">
                                ⚠ ${issue}
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * Render lint details
     */
    renderLintDetails(lint) {
        const hasIssues = lint.errors.length > 0 || lint.warnings.length > 0;

        return `
            <div class="quality-section" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                    <span>🔍</span>
                    <span>Analyse de Contenu</span>
                </h3>
                ${!hasIssues ? `
                    <div style="text-align: center; padding: 20px; color: #10b981;">
                        <div style="font-size: 48px;">✓</div>
                        <div style="margin-top: 8px; font-weight: 600;">Aucun problème détecté</div>
                    </div>
                ` : `
                    ${this.renderLintCategory('Erreurs Critiques', lint.errors, '#ef4444')}
                    ${this.renderLintCategory('Avertissements', lint.warnings, '#f59e0b')}
                    ${lint.info.length > 0 ? this.renderLintCategory('Informations', lint.info, '#3b82f6') : ''}
                `}
            </div>
        `;
    }

    /**
     * Render lint category (errors, warnings, info)
     */
    renderLintCategory(title, items, color) {
        if (items.length === 0) return '';

        return `
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 12px 0; color: ${color}; font-size: 16px;">${title} (${items.length})</h4>
                <div class="lint-items">
                    ${items.map(item => `
                        <div style="padding: 10px; background: ${color}15; border-left: 3px solid ${color}; margin-bottom: 8px; border-radius: 4px;">
                            <div style="font-weight: 600; margin-bottom: 4px; font-size: 14px;">${item.code}</div>
                            <div style="color: #666; font-size: 14px;">${item.message}</div>
                            ${item.location ? `<div style="font-size: 12px; color: #999; margin-top: 4px;">📍 ${item.location}</div>` : ''}
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Render actionable recommendations
     */
    renderRecommendations(conf) {
        if (conf.recommendations.length === 0) return '';

        const priorityColors = {
            high: '#ef4444',
            medium: '#f59e0b',
            low: '#3b82f6'
        };

        const priorityLabels = {
            high: 'Priorité haute',
            medium: 'Priorité moyenne',
            low: 'Priorité basse'
        };

        return `
            <div class="quality-section" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 16px 0; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                    <span>💡</span>
                    <span>Recommandations</span>
                </h3>
                <div class="recommendations-list">
                    ${conf.recommendations.map(rec => `
                        <div style="padding: 16px; background: ${priorityColors[rec.priority]}15; border-left: 4px solid ${priorityColors[rec.priority]}; margin-bottom: 12px; border-radius: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                <div style="font-weight: 600; font-size: 15px;">${rec.message}</div>
                                <span style="font-size: 12px; padding: 4px 8px; background: ${priorityColors[rec.priority]}; color: white; border-radius: 4px;">
                                    ${priorityLabels[rec.priority]}
                                </span>
                            </div>
                            <div style="color: #666; font-size: 14px;">
                                <strong>Action :</strong> ${rec.action}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Attach event handlers
     */
    attachEventHandlers() {
        // Could add export, print, or filter functionality here
    }

    /**
     * Export report as JSON
     */
    exportReport() {
        const report = {
            theme_id: this.themeId,
            timestamp: new Date().toISOString(),
            confidence: this.confidenceReport.report,
            linting: this.lintReport.report
        };

        const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `quality-report-theme-${this.themeId}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
}

// Export for use in other modules
window.ThemeQualityViewer = ThemeQualityViewer;
