/**
 * Sprint 20 - Tenant Onboarding Wizard
 *
 * Interface wizard pour l'onboarding complet d'un établissement
 *
 * Étapes :
 * 1. Welcome & Template selection
 * 2. Tenant information
 * 3. Admin user creation
 * 4. Import structure (CSV or manual)
 * 5. SMTP configuration (optional)
 * 6. Branding & logo (optional)
 * 7. IA quotas & policy
 * 8. Complete & launch
 *
 * @version 1.0.0
 * @date 2025-11-15
 */

class OnboardingWizard {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.currentStep = 0;
        this.tenantId = null;
        this.adminUserId = null;
        this.data = {
            template: null,
            tenant: {},
            admin: {},
            import: {},
            smtp: {},
            branding: {},
            iaConfig: {}
        };

        this.steps = [
            { id: 'welcome', title: 'Bienvenue', component: this.renderWelcomeStep.bind(this) },
            { id: 'tenant_info', title: 'Informations établissement', component: this.renderTenantInfoStep.bind(this) },
            { id: 'admin_user', title: 'Compte administrateur', component: this.renderAdminUserStep.bind(this) },
            { id: 'import_structure', title: 'Structure académique', component: this.renderImportStep.bind(this) },
            { id: 'smtp', title: 'Configuration email', component: this.renderSMTPStep.bind(this), optional: true },
            { id: 'branding', title: 'Personnalisation', component: this.renderBrandingStep.bind(this), optional: true },
            { id: 'ia_config', title: 'Politique IA', component: this.renderIAConfigStep.bind(this) },
            { id: 'complete', title: 'Finalisation', component: this.renderCompleteStep.bind(this) }
        ];

        this.init();
    }

    init() {
        this.render();
    }

    render() {
        const step = this.steps[this.currentStep];

        this.container.innerHTML = `
            <div class="onboarding-wizard">
                <!-- Progress bar -->
                <div class="wizard-progress">
                    ${this.renderProgressBar()}
                </div>

                <!-- Step content -->
                <div class="wizard-content">
                    <h2>${step.title}</h2>
                    <div id="step-content"></div>
                </div>

                <!-- Navigation -->
                <div class="wizard-navigation">
                    ${this.currentStep > 0 ? '<button id="btn-prev" class="btn-secondary">← Précédent</button>' : ''}
                    ${step.optional ? '<button id="btn-skip" class="btn-secondary">Passer cette étape</button>' : ''}
                    <button id="btn-next" class="btn-primary">
                        ${this.currentStep === this.steps.length - 1 ? 'Terminer' : 'Suivant →'}
                    </button>
                </div>
            </div>
        `;

        // Render step content
        step.component();

        // Attach event listeners
        this.attachNavigation();
    }

    renderProgressBar() {
        return this.steps.map((step, index) => {
            const status = index < this.currentStep ? 'completed' :
                          index === this.currentStep ? 'active' : 'pending';

            return `
                <div class="progress-step ${status}" data-step="${index}">
                    <div class="step-number">${index + 1}</div>
                    <div class="step-label">${step.title}</div>
                </div>
            `;
        }).join('');
    }

    renderWelcomeStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="welcome-step">
                <p class="lead">
                    Bienvenue dans l'assistant de configuration de votre établissement !
                </p>
                <p>
                    Cet assistant va vous guider à travers toutes les étapes nécessaires pour
                    configurer votre établissement sur StudyMate Orchestrator.
                </p>

                <h3>Choisissez un template de configuration</h3>
                <div id="template-list" class="template-grid">
                    <div class="loading">Chargement des templates...</div>
                </div>
            </div>
        `;

        this.loadTemplates();
    }

    async loadTemplates() {
        try {
            const response = await fetch('/api/admin/onboarding/templates');
            const data = await response.json();

            const templateList = document.getElementById('template-list');
            templateList.innerHTML = data.templates.map(template => `
                <div class="template-card" data-template-id="${template.id}">
                    <h4>${template.name}</h4>
                    <p class="template-type">${template.type}</p>
                    <p>${template.description}</p>
                    <button class="btn-select-template" data-id="${template.id}">Sélectionner</button>
                </div>
            `).join('');

            // Attach click handlers
            document.querySelectorAll('.btn-select-template').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    this.data.template = e.target.dataset.id;
                    document.querySelectorAll('.template-card').forEach(card => {
                        card.classList.remove('selected');
                    });
                    e.target.closest('.template-card').classList.add('selected');
                });
            });

        } catch (error) {
            console.error('Failed to load templates:', error);
            document.getElementById('template-list').innerHTML = `
                <div class="error">Erreur lors du chargement des templates</div>
            `;
        }
    }

    renderTenantInfoStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="tenant-info-step">
                <form id="form-tenant-info">
                    <div class="form-group">
                        <label for="tenant-name">Nom de l'établissement *</label>
                        <input type="text" id="tenant-name" required
                               value="${this.data.tenant.name || ''}"
                               placeholder="Ex: Collège Jean Moulin">
                    </div>

                    <div class="form-group">
                        <label for="tenant-type">Type d'établissement *</label>
                        <select id="tenant-type" required>
                            <option value="public" ${this.data.tenant.type === 'public' ? 'selected' : ''}>Public</option>
                            <option value="private" ${this.data.tenant.type === 'private' ? 'selected' : ''}>Privé</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tenant-email">Email de contact</label>
                        <input type="email" id="tenant-email"
                               value="${this.data.tenant.email || ''}"
                               placeholder="contact@etablissement.fr">
                    </div>

                    <div class="form-group">
                        <label for="tenant-phone">Téléphone</label>
                        <input type="tel" id="tenant-phone"
                               value="${this.data.tenant.phone || ''}"
                               placeholder="01 23 45 67 89">
                    </div>

                    <div class="form-group">
                        <label for="tenant-address">Adresse</label>
                        <textarea id="tenant-address" rows="3"
                                  placeholder="1 rue de l'École, 75001 Paris">${this.data.tenant.address || ''}</textarea>
                    </div>
                </form>
            </div>
        `;
    }

    renderAdminUserStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="admin-user-step">
                <p>Créez le compte administrateur principal de l'établissement.</p>

                <form id="form-admin-user">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin-firstname">Prénom *</label>
                            <input type="text" id="admin-firstname" required
                                   value="${this.data.admin.firstname || ''}">
                        </div>

                        <div class="form-group">
                            <label for="admin-lastname">Nom *</label>
                            <input type="text" id="admin-lastname" required
                                   value="${this.data.admin.lastname || ''}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="admin-email">Email professionnel *</label>
                        <input type="email" id="admin-email" required
                               value="${this.data.admin.email || ''}"
                               placeholder="admin@etablissement.fr">
                    </div>

                    <div class="form-group">
                        <label for="admin-password">Mot de passe *</label>
                        <input type="password" id="admin-password" required minlength="8"
                               placeholder="Minimum 8 caractères">
                        <small>Le mot de passe doit contenir au moins 8 caractères</small>
                    </div>

                    <div class="form-group">
                        <label for="admin-password-confirm">Confirmer le mot de passe *</label>
                        <input type="password" id="admin-password-confirm" required>
                    </div>
                </form>
            </div>
        `;
    }

    renderImportStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="import-step">
                <p>Importez votre structure académique (promotions, classes, enseignants, élèves)</p>

                <div class="import-method-selector">
                    <button class="method-btn active" data-method="csv">Import CSV</button>
                    <button class="method-btn" data-method="manual">Saisie manuelle</button>
                </div>

                <div id="import-content">
                    ${this.renderCSVImport()}
                </div>
            </div>
        `;

        // Method selector
        document.querySelectorAll('.method-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                const method = e.target.dataset.method;
                document.getElementById('import-content').innerHTML =
                    method === 'csv' ? this.renderCSVImport() : this.renderManualImport();
            });
        });
    }

    renderCSVImport() {
        return `
            <div class="csv-import">
                <h4>1. Téléchargez les templates CSV</h4>
                <div class="template-downloads">
                    <a href="/api/admin/import/template/promotions" class="btn-download">📥 Promotions</a>
                    <a href="/api/admin/import/template/classes" class="btn-download">📥 Classes</a>
                    <a href="/api/admin/import/template/teachers" class="btn-download">📥 Enseignants</a>
                    <a href="/api/admin/import/template/students" class="btn-download">📥 Élèves</a>
                </div>

                <h4>2. Remplissez les templates avec vos données</h4>
                <p><small>Respectez l'ordre : Promotions → Classes → Enseignants → Élèves</small></p>

                <h4>3. Uploadez vos fichiers CSV</h4>
                <div id="csv-uploader">
                    ${['promotions', 'classes', 'teachers', 'students'].map(type => `
                        <div class="upload-section" data-type="${type}">
                            <label>${type.charAt(0).toUpperCase() + type.slice(1)}</label>
                            <input type="file" accept=".csv" data-import-type="${type}" class="csv-file-input">
                            <button class="btn-upload" data-type="${type}" disabled>Upload</button>
                            <span class="upload-status"></span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    renderManualImport() {
        return `
            <div class="manual-import">
                <p>Vous pourrez créer vos promotions, classes et utilisateurs après l'onboarding.</p>
                <div class="alert-info">
                    💡 Vous pouvez passer cette étape et configurer votre structure académique plus tard
                    depuis le panneau d'administration.
                </div>
            </div>
        `;
    }

    renderSMTPStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="smtp-step">
                <p>Configurez le serveur SMTP pour l'envoi d'emails (invitations, notifications, etc.)</p>

                <form id="form-smtp">
                    <div class="form-group">
                        <label for="smtp-host">Serveur SMTP *</label>
                        <input type="text" id="smtp-host"
                               value="${this.data.smtp.host || ''}"
                               placeholder="smtp.gmail.com">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="smtp-port">Port *</label>
                            <input type="number" id="smtp-port"
                                   value="${this.data.smtp.port || 587}">
                        </div>

                        <div class="form-group">
                            <label for="smtp-encryption">Chiffrement</label>
                            <select id="smtp-encryption">
                                <option value="tls" ${this.data.smtp.encryption === 'tls' ? 'selected' : ''}>TLS</option>
                                <option value="ssl" ${this.data.smtp.encryption === 'ssl' ? 'selected' : ''}>SSL</option>
                                <option value="none" ${this.data.smtp.encryption === 'none' ? 'selected' : ''}>Aucun</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtp-username">Utilisateur</label>
                        <input type="text" id="smtp-username"
                               value="${this.data.smtp.username || ''}">
                    </div>

                    <div class="form-group">
                        <label for="smtp-password">Mot de passe</label>
                        <input type="password" id="smtp-password">
                    </div>

                    <div class="form-group">
                        <label for="smtp-from-email">Email d'envoi *</label>
                        <input type="email" id="smtp-from-email"
                               value="${this.data.smtp.from_email || ''}"
                               placeholder="noreply@etablissement.fr">
                    </div>

                    <div class="form-group">
                        <label for="smtp-from-name">Nom d'envoi</label>
                        <input type="text" id="smtp-from-name"
                               value="${this.data.smtp.from_name || ''}"
                               placeholder="Mon Établissement">
                    </div>

                    <button type="button" id="btn-test-smtp" class="btn-secondary">Tester la connexion</button>
                    <span id="smtp-test-result"></span>
                </form>
            </div>
        `;

        // Test SMTP
        document.getElementById('btn-test-smtp')?.addEventListener('click', async () => {
            const result = document.getElementById('smtp-test-result');
            result.textContent = 'Test en cours...';

            try {
                const config = this.collectSMTPData();
                const response = await fetch('/api/admin/tenant/smtp/test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(config)
                });
                const data = await response.json();

                result.textContent = data.test_passed ? '✓ Connexion réussie' : '✗ Échec: ' + data.message;
                result.className = data.test_passed ? 'success' : 'error';
            } catch (error) {
                result.textContent = '✗ Erreur: ' + error.message;
                result.className = 'error';
            }
        });
    }

    renderBrandingStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="branding-step">
                <h3>Logo de l'établissement</h3>
                <div class="form-group">
                    <input type="file" id="logo-upload" accept="image/png,image/jpeg,image/svg+xml">
                    <small>PNG, JPEG ou SVG - Max 2MB</small>
                    <div id="logo-preview"></div>
                </div>

                <h3>Couleurs personnalisées</h3>
                <form id="form-branding">
                    <div class="form-group">
                        <label for="color-primary">Couleur principale</label>
                        <input type="color" id="color-primary"
                               value="${this.data.branding.primary_color || '#3B82F6'}">
                    </div>

                    <div class="form-group">
                        <label for="color-secondary">Couleur secondaire</label>
                        <input type="color" id="color-secondary"
                               value="${this.data.branding.secondary_color || '#10B981'}">
                    </div>

                    <div class="form-group">
                        <label for="color-accent">Couleur d'accentuation</label>
                        <input type="color" id="color-accent"
                               value="${this.data.branding.accent_color || '#F59E0B'}">
                    </div>
                </form>

                <div class="branding-preview" id="branding-preview">
                    <h4>Aperçu</h4>
                    <div class="preview-box" style="background: var(--primary-color)">Couleur principale</div>
                    <div class="preview-box" style="background: var(--secondary-color)">Couleur secondaire</div>
                    <div class="preview-box" style="background: var(--accent-color)">Couleur d'accentuation</div>
                </div>
            </div>
        `;

        // Color picker live preview
        ['primary', 'secondary', 'accent'].forEach(type => {
            document.getElementById(`color-${type}`)?.addEventListener('input', (e) => {
                document.documentElement.style.setProperty(`--${type}-color`, e.target.value);
            });
        });
    }

    renderIAConfigStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="ia-config-step">
                <h3>Politique d'usage de l'IA</h3>
                <form id="form-ia-policy">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="ia-allow-generation"
                                   ${this.data.iaConfig.allow_ai_generation !== false ? 'checked' : ''}>
                            Autoriser la génération de contenu par IA
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Fournisseurs IA autorisés</label>
                        <label><input type="checkbox" name="ia-providers" value="mistral" checked> Mistral</label>
                        <label><input type="checkbox" name="ia-providers" value="openai"> OpenAI</label>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="ia-require-review"
                                   ${this.data.iaConfig.require_review !== false ? 'checked' : ''}>
                            Exiger une validation humaine avant publication
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="ia-max-per-day">Générations max par utilisateur/jour</label>
                        <input type="number" id="ia-max-per-day" min="1" max="100"
                               value="${this.data.iaConfig.max_generations_per_user_per_day || 10}">
                    </div>
                </form>

                <h3>Quotas mensuels IA</h3>
                <form id="form-ia-quota">
                    <div class="form-group">
                        <label for="ia-monthly-quota">Quota mensuel de générations</label>
                        <input type="number" id="ia-monthly-quota" min="100" step="100"
                               value="${this.data.iaConfig.monthly_quota || 1000}">
                    </div>

                    <div class="form-group">
                        <label for="ia-warning-threshold">Seuil d'alerte (%)</label>
                        <input type="number" id="ia-warning-threshold" min="50" max="100"
                               value="${this.data.iaConfig.warning_threshold || 80}">
                        <small>Vous serez alerté lorsque ce pourcentage sera atteint</small>
                    </div>
                </form>
            </div>
        `;
    }

    renderCompleteStep() {
        const contentDiv = document.getElementById('step-content');
        contentDiv.innerHTML = `
            <div class="complete-step">
                <div class="success-icon">✓</div>
                <h3>Configuration terminée !</h3>
                <p>Votre établissement est prêt à être utilisé.</p>

                <div class="summary">
                    <h4>Récapitulatif</h4>
                    <ul>
                        <li><strong>Établissement :</strong> ${this.data.tenant.name}</li>
                        <li><strong>Administrateur :</strong> ${this.data.admin.email}</li>
                        <li><strong>Template :</strong> ${this.data.template}</li>
                    </ul>
                </div>

                <div class="next-steps">
                    <h4>Prochaines étapes</h4>
                    <ol>
                        <li>Inviter votre équipe enseignante</li>
                        <li>Créer vos premiers thèmes pédagogiques</li>
                        <li>Diffuser des activités aux élèves</li>
                        <li>Suivre les statistiques et progrès</li>
                    </ol>
                </div>

                <label>
                    <input type="checkbox" id="launch-tour">
                    Lancer le tutoriel guidé après finalisation
                </label>
            </div>
        `;
    }

    attachNavigation() {
        document.getElementById('btn-next')?.addEventListener('click', () => this.goNext());
        document.getElementById('btn-prev')?.addEventListener('click', () => this.goPrev());
        document.getElementById('btn-skip')?.addEventListener('click', () => this.skip());
    }

    async goNext() {
        // Validate and save current step
        const stepId = this.steps[this.currentStep].id;

        try {
            switch(stepId) {
                case 'welcome':
                    if (!this.data.template) {
                        alert('Veuillez sélectionner un template');
                        return;
                    }
                    break;

                case 'tenant_info':
                    await this.saveTenantInfo();
                    break;

                case 'admin_user':
                    await this.saveAdminUser();
                    break;

                case 'smtp':
                    await this.saveSMTP();
                    break;

                case 'branding':
                    await this.saveBranding();
                    break;

                case 'ia_config':
                    await this.saveIAConfig();
                    break;

                case 'complete':
                    await this.completeOnboarding();
                    return;
            }

            // Move to next step
            if (this.currentStep < this.steps.length - 1) {
                this.currentStep++;
                this.render();
            }

        } catch (error) {
            console.error('Error saving step:', error);
            alert('Erreur: ' + error.message);
        }
    }

    goPrev() {
        if (this.currentStep > 0) {
            this.currentStep--;
            this.render();
        }
    }

    skip() {
        // Skip optional step
        if (this.steps[this.currentStep].optional) {
            this.currentStep++;
            this.render();
        }
    }

    // Data collection methods
    collectTenantData() {
        return {
            name: document.getElementById('tenant-name').value,
            type: document.getElementById('tenant-type').value,
            email: document.getElementById('tenant-email').value,
            phone: document.getElementById('tenant-phone').value,
            address: document.getElementById('tenant-address').value
        };
    }

    collectAdminData() {
        const password = document.getElementById('admin-password').value;
        const confirm = document.getElementById('admin-password-confirm').value;

        if (password !== confirm) {
            throw new Error('Les mots de passe ne correspondent pas');
        }

        return {
            firstname: document.getElementById('admin-firstname').value,
            lastname: document.getElementById('admin-lastname').value,
            email: document.getElementById('admin-email').value,
            password: password
        };
    }

    collectSMTPData() {
        return {
            host: document.getElementById('smtp-host').value,
            port: parseInt(document.getElementById('smtp-port').value),
            encryption: document.getElementById('smtp-encryption').value,
            username: document.getElementById('smtp-username').value,
            password: document.getElementById('smtp-password').value,
            from_email: document.getElementById('smtp-from-email').value,
            from_name: document.getElementById('smtp-from-name').value
        };
    }

    collectBrandingData() {
        return {
            primary_color: document.getElementById('color-primary').value,
            secondary_color: document.getElementById('color-secondary').value,
            accent_color: document.getElementById('color-accent').value
        };
    }

    collectIAConfigData() {
        const providers = Array.from(document.querySelectorAll('input[name="ia-providers"]:checked'))
            .map(cb => cb.value);

        return {
            allow_ai_generation: document.getElementById('ia-allow-generation').checked,
            providers: providers,
            require_review: document.getElementById('ia-require-review').checked,
            auto_publish: false,
            max_generations_per_user_per_day: parseInt(document.getElementById('ia-max-per-day').value),
            monthly_quota: parseInt(document.getElementById('ia-monthly-quota').value),
            warning_threshold: parseInt(document.getElementById('ia-warning-threshold').value)
        };
    }

    // Save methods
    async saveTenantInfo() {
        this.data.tenant = this.collectTenantData();

        const response = await fetch('/api/admin/onboarding/tenant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ...this.data.tenant,
                template_id: this.data.template
            })
        });

        if (!response.ok) throw new Error('Failed to create tenant');

        const data = await response.json();
        this.tenantId = data.tenant_id;
    }

    async saveAdminUser() {
        this.data.admin = this.collectAdminData();

        const response = await fetch('/api/admin/onboarding/admin-user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tenant_id: this.tenantId,
                ...this.data.admin
            })
        });

        if (!response.ok) throw new Error('Failed to create admin user');

        const data = await response.json();
        this.adminUserId = data.user_id;
    }

    async saveSMTP() {
        this.data.smtp = this.collectSMTPData();

        const response = await fetch('/api/admin/tenant/smtp', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Tenant-Id': this.tenantId
            },
            body: JSON.stringify(this.data.smtp)
        });

        if (!response.ok) throw new Error('Failed to configure SMTP');
    }

    async saveBranding() {
        this.data.branding = this.collectBrandingData();

        // Upload logo if present
        const logoFile = document.getElementById('logo-upload')?.files[0];
        if (logoFile) {
            const formData = new FormData();
            formData.append('logo', logoFile);

            await fetch('/api/admin/tenant/logo', {
                method: 'POST',
                headers: { 'X-Tenant-Id': this.tenantId },
                body: formData
            });
        }

        // Save colors
        const response = await fetch('/api/admin/tenant/branding', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Tenant-Id': this.tenantId
            },
            body: JSON.stringify(this.data.branding)
        });

        if (!response.ok) throw new Error('Failed to configure branding');
    }

    async saveIAConfig() {
        this.data.iaConfig = this.collectIAConfigData();

        // Save IA policy
        const policyResponse = await fetch('/api/admin/tenant/ia-policy', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Tenant-Id': this.tenantId
            },
            body: JSON.stringify({
                allow_ai_generation: this.data.iaConfig.allow_ai_generation,
                providers: this.data.iaConfig.providers,
                require_review: this.data.iaConfig.require_review,
                auto_publish: this.data.iaConfig.auto_publish,
                max_generations_per_user_per_day: this.data.iaConfig.max_generations_per_user_per_day
            })
        });

        // Save IA quota
        const quotaResponse = await fetch('/api/admin/tenant/ia-quota', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Tenant-Id': this.tenantId
            },
            body: JSON.stringify({
                monthly_quota: this.data.iaConfig.monthly_quota,
                warning_threshold: this.data.iaConfig.warning_threshold
            })
        });

        if (!policyResponse.ok || !quotaResponse.ok) {
            throw new Error('Failed to configure IA settings');
        }
    }

    async completeOnboarding() {
        const response = await fetch('/api/admin/onboarding/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Tenant-Id': this.tenantId
            },
            body: JSON.stringify({
                tenant_id: this.tenantId
            })
        });

        if (!response.ok) throw new Error('Failed to complete onboarding');

        // Launch tour if requested
        const launchTour = document.getElementById('launch-tour')?.checked;
        if (launchTour) {
            // Redirect to dashboard with tour parameter
            window.location.href = '/dashboard?tour=true';
        } else {
            window.location.href = '/dashboard';
        }
    }
}

// Initialize wizard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('onboarding-wizard-container')) {
        new OnboardingWizard('onboarding-wizard-container');
    }
});
