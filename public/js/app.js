/**
 * Study-mate School Orchestrator - Frontend App
 * Sprint 17: Mode Démo intégré
 */

// Configuration
const API_BASE_URL = window.location.origin;
let currentView = 'home';
let authToken = localStorage.getItem('authToken');
let currentUser = null;
let demoModeEnabled = false;
let appConfig = null;

// Navigation
function navigateTo(view) {
    // Cacher toutes les vues
    document.querySelectorAll('.view').forEach(v => {
        v.classList.remove('active');
    });

    // Afficher la vue demandée
    const viewElement = document.getElementById(`${view}-view`);
    if (viewElement) {
        viewElement.classList.add('active');
        currentView = view;

        // Charger les données si nécessaire
        loadViewData(view);
    }
}

// Charger les données d'une vue
async function loadViewData(view) {
    switch(view) {
        case 'dashboard':
            await loadDashboard();
            break;
        case 'students':
            await loadStudents();
            break;
        case 'assignments':
            await loadAssignments();
            break;
        case 'sync':
            await loadSyncLogs();
            break;
    }
}

// API Helper
async function apiCall(endpoint, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...options.headers
    };

    if (authToken) {
        headers['Authorization'] = `Bearer ${authToken}`;
    }

    if (currentUser && currentUser.tenantId) {
        headers['X-Orchestrator-Id'] = currentUser.tenantId;
    }

    try {
        const response = await fetch(`${API_BASE_URL}${endpoint}`, {
            ...options,
            headers
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'API Error');
        }

        return await response.json();
    } catch (error) {
        console.error('API Call failed:', error);
        throw error;
    }
}

// Login
async function login(email, password) {
    try {
        const result = await apiCall('/api/auth/login', {
            method: 'POST',
            body: JSON.stringify({ email, password })
        });

        authToken = result.token;
        currentUser = result.user;
        localStorage.setItem('authToken', authToken);
        localStorage.setItem('currentUser', JSON.stringify(currentUser));

        renderNav();
        navigateTo('dashboard');

        return true;
    } catch (error) {
        alert('Erreur de connexion : ' + error.message);
        return false;
    }
}

// Logout
function logout() {
    // Si on est en mode démo, désactiver complètement
    if (isDemoMode()) {
        exitDemoMode();
        return;
    }

    authToken = null;
    currentUser = null;
    localStorage.removeItem('authToken');
    localStorage.removeItem('currentUser');
    renderNav();
    navigateTo('login');
}

// Render Navigation
function renderNav() {
    const nav = document.getElementById('nav');

    if (authToken) {
        nav.innerHTML = `
            <a href="#" onclick="navigateTo('dashboard'); return false;">Dashboard</a>
            <a href="#" onclick="navigateTo('students'); return false;">Élèves</a>
            <a href="#" onclick="navigateTo('assignments'); return false;">Affectations</a>
            <a href="#" onclick="navigateTo('sync'); return false;">Sync</a>
            <a href="#" onclick="logout(); return false;">Déconnexion</a>
        `;
    } else {
        nav.innerHTML = `
            <a href="#" onclick="navigateTo('home'); return false;">Accueil</a>
            <a href="#" onclick="navigateTo('login'); return false;">Connexion</a>
        `;
    }
}

// Load Dashboard
async function loadDashboard() {
    const content = document.getElementById('dashboard-content');

    if (!authToken) {
        content.innerHTML = '<p>Veuillez vous connecter</p>';
        return;
    }

    content.innerHTML = '<p>Chargement des statistiques...</p>';

    try {
        const data = await apiCall('/api/dashboard/summary');

        content.innerHTML = `
            <div class="card-grid">
                <div class="card">
                    <h3>Total Élèves</h3>
                    <p class="stat-value">${data.kpis?.totalStudents || 0}</p>
                </div>
                <div class="card">
                    <h3>Élèves Actifs</h3>
                    <p class="stat-value">${data.kpis?.activeStudents || 0}</p>
                </div>
                <div class="card">
                    <h3>Score Moyen</h3>
                    <p class="stat-value">${(data.kpis?.avgScore || 0).toFixed(1)}%</p>
                </div>
                <div class="card">
                    <h3>Maîtrise Moyenne</h3>
                    <p class="stat-value">${((data.kpis?.avgMastery || 0) * 100).toFixed(1)}%</p>
                </div>
            </div>
        `;
    } catch (error) {
        content.innerHTML = `<p class="error">Erreur : ${error.message}</p>`;
    }
}

// Load Students
async function loadStudents() {
    const content = document.getElementById('students-content');

    if (!authToken) {
        content.innerHTML = '<p>Veuillez vous connecter</p>';
        return;
    }

    content.innerHTML = `
        <div class="form-group">
            <label for="class-select">Sélectionnez une classe</label>
            <select id="class-select" onchange="loadStudentsForClass(this.value)">
                <option value="">-- Choisir --</option>
            </select>
        </div>
        <div id="students-list"></div>
    `;

    // Charger les classes
    try {
        const { data: classes } = await apiCall('/api/classes');
        const select = document.getElementById('class-select');

        classes.forEach(c => {
            const option = document.createElement('option');
            option.value = c.id;
            option.textContent = c.name;
            select.appendChild(option);
        });
    } catch (error) {
        content.innerHTML = `<p class="error">Erreur : ${error.message}</p>`;
    }
}

async function loadStudentsForClass(classId) {
    if (!classId) return;

    const listDiv = document.getElementById('students-list');
    listDiv.innerHTML = '<p>Chargement...</p>';

    try {
        const { data: students } = await apiCall(`/api/students?classId=${classId}`);

        if (students.length === 0) {
            listDiv.innerHTML = '<p>Aucun élève dans cette classe</p>';
            return;
        }

        let html = '<table><thead><tr><th>Nom</th><th>Email</th><th>UUID</th></tr></thead><tbody>';

        students.forEach(s => {
            html += `
                <tr>
                    <td>${s.firstname} ${s.lastname}</td>
                    <td>${s.email_scolaire}</td>
                    <td><code>${s.uuid_scolaire}</code></td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        listDiv.innerHTML = html;

    } catch (error) {
        listDiv.innerHTML = `<p class="error">Erreur : ${error.message}</p>`;
    }
}

// Load Assignments
async function loadAssignments() {
    const content = document.getElementById('assignments-content');

    if (!authToken) {
        content.innerHTML = '<p>Veuillez vous connecter</p>';
        return;
    }

    content.innerHTML = '<p>Chargement des affectations...</p>';

    try {
        const { data: assignments } = await apiCall('/api/assignments');

        if (assignments.length === 0) {
            content.innerHTML = '<p>Aucune affectation</p>';
            return;
        }

        let html = '<div class="card-grid">';

        assignments.forEach(a => {
            html += `
                <div class="card">
                    <h3>${a.title}</h3>
                    <p><strong>Type:</strong> ${a.type}</p>
                    <p><strong>Statut:</strong> ${a.status}</p>
                    <p><strong>Échéance:</strong> ${a.dueAt || 'Aucune'}</p>
                </div>
            `;
        });

        html += '</div>';
        content.innerHTML = html;

    } catch (error) {
        content.innerHTML = `<p class="error">Erreur : ${error.message}</p>`;
    }
}

// Load Sync Logs
async function loadSyncLogs() {
    // À implémenter
    document.getElementById('sync-logs').innerHTML = '<p>Logs de synchronisation à venir...</p>';
}

// Trigger Sync
async function triggerSync() {
    if (!authToken) {
        alert('Veuillez vous connecter');
        return;
    }

    try {
        await apiCall('/api/sync/pull-stats', {
            method: 'POST',
            body: JSON.stringify({
                since: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString()
            })
        });

        alert('Synchronisation déclenchée avec succès !');
        loadSyncLogs();
    } catch (error) {
        alert('Erreur : ' + error.message);
    }
}

// ========================================
// MODE DÉMO (Sprint 17)
// ========================================

/**
 * Charge la configuration de l'application
 */
async function loadAppConfig() {
    try {
        const response = await fetch(`${API_BASE_URL}/orchestrator/api/config.php`);
        if (response.ok) {
            const result = await response.json();
            appConfig = result.data;
            demoModeEnabled = appConfig.demoMode || false;

            // Afficher le bouton démo si DEMO_MODE est activé
            if (demoModeEnabled) {
                const demoContainer = document.getElementById('demo-mode-container');
                if (demoContainer) {
                    demoContainer.style.display = 'block';
                }
            }
        }
    } catch (error) {
        console.warn('Impossible de charger la configuration:', error);
    }
}

/**
 * Démarre le mode démo
 */
function startDemoMode() {
    console.log('[Demo] Activation du mode démo');

    // Activer la session démo
    localStorage.setItem('DEMO_SESSION', 'true');

    // Activer le FakeRouter
    if (window.fakeRouter) {
        window.fakeRouter.enable();
    }

    // Créer un faux utilisateur demo
    currentUser = {
        id: 'demo-user',
        email: 'demo@studymate.fr',
        firstname: 'Utilisateur',
        lastname: 'Démo',
        role: 'teacher',
        tenantId: 'demo-tenant',
        permissions: ['read', 'write', 'manage']
    };

    authToken = 'demo-token-' + Date.now();
    localStorage.setItem('authToken', authToken);
    localStorage.setItem('currentUser', JSON.stringify(currentUser));

    // Afficher le bandeau démo
    const demoBanner = document.getElementById('demo-banner');
    if (demoBanner) {
        demoBanner.style.display = 'block';
    }

    // Naviguer vers le dashboard
    renderNav();
    navigateTo('dashboard');

    // Optionnel : lancer le tour guidé
    setTimeout(() => {
        if (window.demoTour && confirm('Souhaitez-vous démarrer le parcours guidé ?')) {
            window.demoTour.start();
        }
    }, 500);
}

/**
 * Quitte le mode démo
 */
function exitDemoMode() {
    if (!confirm('Voulez-vous vraiment quitter le mode démo ?')) {
        return;
    }

    console.log('[Demo] Désactivation du mode démo');

    // Désactiver le FakeRouter
    if (window.fakeRouter) {
        window.fakeRouter.disable();
    }

    // Nettoyer la session
    localStorage.removeItem('DEMO_SESSION');
    localStorage.removeItem('authToken');
    localStorage.removeItem('currentUser');

    authToken = null;
    currentUser = null;

    // Masquer le bandeau
    const demoBanner = document.getElementById('demo-banner');
    if (demoBanner) {
        demoBanner.style.display = 'none';
    }

    // Recharger la page
    window.location.reload();
}

/**
 * Vérifie si on est en mode démo
 */
function isDemoMode() {
    return localStorage.getItem('DEMO_SESSION') === 'true';
}

// ========================================
// INITIALISATION
// ========================================

// Login Form Handler
document.addEventListener('DOMContentLoaded', async () => {
    // Charger la configuration
    await loadAppConfig();

    // Vérifier si on est en mode démo
    if (isDemoMode()) {
        // Afficher le bandeau
        const demoBanner = document.getElementById('demo-banner');
        if (demoBanner) {
            demoBanner.style.display = 'block';
        }

        // Activer le FakeRouter
        if (window.fakeRouter) {
            window.fakeRouter.enable();
        }
    }

    const loginForm = document.getElementById('login-form');

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            await login(email, password);
        });
    }

    // Restaurer la session
    if (authToken) {
        currentUser = JSON.parse(localStorage.getItem('currentUser'));
        renderNav();
        navigateTo('dashboard');
    } else {
        renderNav();
        navigateTo('home');
    }
});

// Export pour usage global
window.navigateTo = navigateTo;
window.logout = logout;
window.triggerSync = triggerSync;
window.loadStudentsForClass = loadStudentsForClass;

// Export fonctions mode démo (Sprint 17)
window.startDemoMode = startDemoMode;
window.exitDemoMode = exitDemoMode;
window.isDemoMode = isDemoMode;
