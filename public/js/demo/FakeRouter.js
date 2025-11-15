/**
 * FakeRouter.js - Sprint 20B - Mode Démo Global
 * Intercepte les appels API et retourne des données mock en mode démo
 * Support complet S18 (Curriculum Builder) + S19 (Workflow Multi-acteurs) + S20 (Tenant Onboarding)
 */

class FakeRouter {
    constructor() {
        this.mockData = {};
        this.requestLog = [];
        this.enabled = false;
        this.basePath = '/js/demo/mock';
        this.debugMode = true; // Active les logs détaillés
    }

    /**
     * Active le mode démo et intercepte fetch
     */
    enable() {
        if (this.enabled) return;

        this.enabled = true;
        console.log('[FakeRouter] Mode démo activé - Interception des appels API');

        // Sauvegarder le fetch original
        this.originalFetch = window.fetch;

        // Remplacer fetch par notre version mockée
        window.fetch = async (...args) => {
            return this.interceptFetch(...args);
        };

        // Sauvegarder XMLHttpRequest original
        this.originalXHR = window.XMLHttpRequest;

        // Remplacer XMLHttpRequest
        const self = this;
        window.XMLHttpRequest = function() {
            const xhr = new self.originalXHR();
            const originalOpen = xhr.open;
            const originalSend = xhr.send;

            xhr.open = function(method, url, ...rest) {
                this._fakeRouterUrl = url;
                this._fakeRouterMethod = method;
                return originalOpen.call(this, method, url, ...rest);
            };

            xhr.send = async function(body) {
                if (self.shouldIntercept(this._fakeRouterUrl)) {
                    const mockResponse = await self.getMockResponse(this._fakeRouterUrl, {
                        method: this._fakeRouterMethod,
                        body: body
                    });

                    // Simuler une réponse asynchrone
                    setTimeout(() => {
                        Object.defineProperty(this, 'response', { value: mockResponse.body });
                        Object.defineProperty(this, 'responseText', { value: mockResponse.body });
                        Object.defineProperty(this, 'status', { value: mockResponse.status });
                        Object.defineProperty(this, 'readyState', { value: 4 });

                        if (this.onreadystatechange) {
                            this.onreadystatechange();
                        }
                    }, 100);
                    return;
                }

                return originalSend.call(this, body);
            };

            return xhr;
        };
    }

    /**
     * Désactive le mode démo
     */
    disable() {
        if (!this.enabled) return;

        this.enabled = false;
        window.fetch = this.originalFetch;
        window.XMLHttpRequest = this.originalXHR;

        console.log('[FakeRouter] Mode démo désactivé');
    }

    /**
     * Vérifie si l'URL doit être interceptée
     */
    shouldIntercept(url) {
        if (!this.enabled) return false;

        // Intercepter uniquement les appels API locaux
        return url.includes('/api/') || url.startsWith('/orchestrator/api/');
    }

    /**
     * Intercepte les appels fetch
     */
    async interceptFetch(url, options = {}) {
        const urlString = typeof url === 'string' ? url : url.url;

        if (this.shouldIntercept(urlString)) {
            console.log('[FakeRouter] Intercepté:', options.method || 'GET', urlString);

            // Logger la requête
            this.requestLog.push({
                timestamp: new Date().toISOString(),
                method: options.method || 'GET',
                url: urlString,
                body: options.body
            });

            // Simuler un délai réseau
            await this.delay(100, 300);

            // Retourner une réponse mockée
            const mockResponse = await this.getMockResponse(urlString, options);

            return new Response(mockResponse.body, {
                status: mockResponse.status,
                statusText: mockResponse.statusText,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Demo-Mode': 'true'
                }
            });
        }

        // Appeler le fetch original pour les autres requêtes
        return this.originalFetch(url, options);
    }

    /**
     * Récupère la réponse mock correspondant à l'URL
     */
    async getMockResponse(url, options = {}) {
        const method = options.method || 'GET';
        const endpoint = this.normalizeEndpoint(url);

        try {
            // Réponses spécifiques pour certains endpoints
            if (endpoint === '/api/config') {
                return this.createResponse({
                    success: true,
                    data: {
                        demoMode: true,
                        apiVersion: 'v1',
                        features: {
                            iaGovernance: true,
                            telemetry: true,
                            multiTenant: true
                        }
                    }
                });
            }

            if (endpoint === '/api/auth/login') {
                return this.handleLogin(options);
            }

            if (endpoint.startsWith('/api/dashboard')) {
                return this.loadMockFile('dashboard.json');
            }

            if (endpoint.startsWith('/api/students')) {
                return this.loadMockFile('students.json');
            }

            if (endpoint.startsWith('/api/classes')) {
                return this.loadMockFile('classes.json');
            }

            if (endpoint.startsWith('/api/assignments')) {
                return this.loadMockFile('assignments.json');
            }

            if (endpoint.startsWith('/api/analytics/teacher_kpi')) {
                return this.loadMockFile('teacher_kpi.json');
            }

            if (endpoint.startsWith('/api/analytics/risk')) {
                return this.loadMockFile('student_risk.json');
            }

            if (endpoint.startsWith('/api/themes')) {
                return this.loadMockFile('themes.json');
            }

            if (endpoint.startsWith('/api/catalog')) {
                return this.loadMockFile('catalog.json');
            }

            if (endpoint.startsWith('/api/quality')) {
                return this.loadMockFile('quality.json');
            }

            if (endpoint.startsWith('/api/ai')) {
                return this.loadMockFile('ai_governance.json');
            }

            if (endpoint.startsWith('/api/telemetry/stats')) {
                return this.loadMockFile('telemetry.json');
            }

            // ========================================
            // SPRINT 18 - CURRICULUM BUILDER
            // ========================================
            if (endpoint === '/api/curriculum' && method === 'GET') {
                return this.loadMockFile('mock_curriculum.json');
            }

            if (endpoint.match(/^\/api\/curriculum\/[0-9a-f-]+$/) && method === 'GET') {
                return this.loadMockFile('mock_curriculum_sequences.json');
            }

            if (endpoint.match(/^\/api\/curriculum\/student\/[0-9a-f-]+$/) && method === 'GET') {
                return this.loadMockFile('mock_student_path.json');
            }

            if (endpoint === '/api/curriculum' && method === 'POST') {
                this.logDebug('Creating new curriculum (mock)', options.body);
                return this.createMutationResponse('Curriculum créé avec succès');
            }

            if (endpoint.match(/^\/api\/curriculum\/sequence\/[0-9a-f-]+\/link-assignment$/) && method === 'PATCH') {
                this.logDebug('Linking assignment to sequence (mock)', options.body);
                return this.createMutationResponse('Affectation liée à la séquence');
            }

            // ========================================
            // SPRINT 19 - WORKFLOW MULTI-ACTEURS
            // ========================================
            if (endpoint.match(/^\/api\/themes\/[0-9a-f-]+\/status$/) && method === 'PATCH') {
                this.logDebug('Updating theme status (mock)', options.body);
                return this.createMutationResponse('Statut du thème mis à jour');
            }

            if (endpoint.match(/^\/api\/annotations\/[0-9a-f-]+$/) && method === 'GET') {
                return this.loadMockFile('mock_annotations.json');
            }

            if (endpoint === '/api/annotations' && method === 'POST') {
                this.logDebug('Creating annotation (mock)', options.body);
                return this.createMutationResponse('Annotation créée', {
                    id: this.generateUUID(),
                    created_at: new Date().toISOString()
                });
            }

            if (endpoint.match(/^\/api\/themes\/[0-9a-f-]+\/versions$/) && method === 'GET') {
                return this.loadMockFile('mock_theme_versions.json');
            }

            if (endpoint.match(/^\/api\/themes\/[0-9a-f-]+\/version\/rollback$/) && method === 'POST') {
                this.logDebug('Rolling back theme version (mock)', options.body);
                return this.createMutationResponse('Version restaurée avec succès');
            }

            // ========================================
            // SPRINT 20 - TENANT ONBOARDING
            // ========================================
            if (endpoint === '/api/admin/tenant/create' && method === 'POST') {
                this.logDebug('Creating tenant (mock)', options.body);
                return this.createMutationResponse('Tenant créé avec succès', {
                    tenant_id: 'tenant_' + Date.now(),
                    created_at: new Date().toISOString()
                });
            }

            if (endpoint.match(/^\/api\/admin\/tenant\/[0-9a-zA-Z_-]+\/config$/) && method === 'PATCH') {
                this.logDebug('Updating tenant config (mock)', options.body);
                return this.createMutationResponse('Configuration tenant mise à jour');
            }

            if (endpoint === '/api/admin/tenant/import-preview' && method === 'POST') {
                this.logDebug('Previewing import (mock)', options.body);
                return this.loadMockFile('mock_import_preview.json');
            }

            if (endpoint === '/api/admin/tenant/import-apply' && method === 'POST') {
                this.logDebug('Applying import (mock)', options.body);
                return this.loadMockFile('mock_import_apply.json');
            }

            // ========================================
            // FALLBACK UNIVERSEL
            // ========================================
            this.logDebug(`⚠️ Endpoint non mocké: ${method} ${endpoint}`, 'Fallback utilisé');
            return this.createFallbackResponse(endpoint, method);

        } catch (error) {
            console.error('[FakeRouter] Erreur:', error);
            return this.createResponse({
                success: false,
                error: error.message
            }, 500);
        }
    }

    /**
     * Gère la connexion en mode démo
     */
    handleLogin(options) {
        // En mode démo, accepter n'importe quel login
        return this.createResponse({
            success: true,
            token: 'demo-token-' + Date.now(),
            user: {
                id: 1,
                email: 'demo@studymate.fr',
                firstname: 'Démo',
                lastname: 'Enseignant',
                role: 'teacher',
                tenantId: 'demo-tenant',
                permissions: ['read', 'write', 'manage']
            }
        });
    }

    /**
     * Charge un fichier mock depuis le dossier mock/
     */
    async loadMockFile(filename) {
        try {
            const response = await this.originalFetch(`${this.basePath}/${filename}`);

            if (!response.ok) {
                throw new Error(`Fichier mock non trouvé: ${filename}`);
            }

            const data = await response.json();
            return this.createResponse(data);

        } catch (error) {
            console.warn(`[FakeRouter] Impossible de charger ${filename}:`, error);

            // Retourner une réponse vide par défaut
            return this.createResponse({
                success: true,
                data: [],
                message: `Mock data for ${filename} not found`
            });
        }
    }

    /**
     * Normalise l'endpoint (retire le domaine et les paramètres de query)
     */
    normalizeEndpoint(url) {
        try {
            const urlObj = new URL(url, window.location.origin);
            return urlObj.pathname;
        } catch {
            // Si URL invalide, retourner tel quel
            return url.split('?')[0];
        }
    }

    /**
     * Crée une réponse mock
     */
    createResponse(data, status = 200, statusText = 'OK') {
        return {
            status,
            statusText,
            body: JSON.stringify(data)
        };
    }

    /**
     * Simule un délai réseau aléatoire
     */
    delay(min = 100, max = 500) {
        const duration = Math.floor(Math.random() * (max - min + 1)) + min;
        return new Promise(resolve => setTimeout(resolve, duration));
    }

    /**
     * Récupère les logs de requêtes
     */
    getRequestLog() {
        return this.requestLog;
    }

    /**
     * Réinitialise les logs
     */
    clearLog() {
        this.requestLog = [];
    }

    /**
     * Log en mode debug
     */
    logDebug(message, details = null) {
        if (this.debugMode) {
            console.log(`[FakeRouter DEBUG] ${message}`, details || '');
        }
    }

    /**
     * Crée une réponse pour une mutation (POST/PATCH/DELETE)
     */
    createMutationResponse(message, additionalData = {}) {
        return this.createResponse({
            success: true,
            message: message,
            data: {
                demo_mode: true,
                ...additionalData
            }
        });
    }

    /**
     * Crée une réponse fallback pour les endpoints non mockés
     */
    createFallbackResponse(endpoint, method) {
        // Pour les mutations, retourner un succès générique
        if (['POST', 'PATCH', 'PUT', 'DELETE'].includes(method)) {
            return this.createMutationResponse('Opération effectuée (mode démo)', {
                endpoint: endpoint,
                fallback: true
            });
        }

        // Pour les GET, retourner une structure générique
        return this.createResponse({
            success: true,
            data: [],
            message: `Mock data for ${endpoint}`,
            demo_mode: true,
            fallback: true
        });
    }

    /**
     * Génère un UUID v4
     */
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    /**
     * Active/désactive le mode debug
     */
    setDebugMode(enabled) {
        this.debugMode = enabled;
        console.log(`[FakeRouter] Mode debug ${enabled ? 'activé' : 'désactivé'}`);
    }
}

// Instance singleton
window.fakeRouter = new FakeRouter();

// Auto-activer si DEMO_SESSION est active
if (localStorage.getItem('DEMO_SESSION') === 'true') {
    window.fakeRouter.enable();
}

export default window.fakeRouter;
