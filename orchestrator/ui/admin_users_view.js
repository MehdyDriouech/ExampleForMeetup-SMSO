/**
 * Admin Users Management UI - Sprint 14
 *
 * Vue.js-style component for managing users (teachers, admins, direction).
 * Provides CRUD operations with role-based access control.
 *
 * @version 1.0
 * @date 2025-11-14
 */

const AdminUsersView = {
    data() {
        return {
            users: [],
            classes: [],
            loading: false,
            error: null,

            // Filters
            filters: {
                role: '',
                status: '',
                search: ''
            },

            // Modal state
            showModal: false,
            modalMode: 'create', // 'create' | 'edit' | 'view'
            selectedUser: null,

            // Form data
            form: {
                email: '',
                firstname: '',
                lastname: '',
                role: 'teacher',
                class_ids: [],
                send_invitation: true
            },

            // Validation
            formErrors: {},

            // Pagination
            currentPage: 1,
            itemsPerPage: 20,

            // Available roles
            roles: [
                { value: 'admin', label: 'Administrateur', color: '#ef4444' },
                { value: 'direction', label: 'Direction', color: '#f59e0b' },
                { value: 'teacher', label: 'Enseignant', color: '#10b981' },
                { value: 'inspector', label: 'Inspecteur', color: '#3b82f6' },
                { value: 'referent', label: 'Référent', color: '#8b5cf6' },
                { value: 'intervenant', label: 'Intervenant', color: '#6b7280' }
            ],

            // Status options
            statuses: [
                { value: 'active', label: 'Actif', color: '#10b981' },
                { value: 'inactive', label: 'Inactif', color: '#ef4444' },
                { value: 'pending', label: 'En attente', color: '#f59e0b' }
            ]
        };
    },

    computed: {
        filteredUsers() {
            let result = this.users;

            if (this.filters.role) {
                result = result.filter(u => u.role === this.filters.role);
            }

            if (this.filters.status) {
                result = result.filter(u => u.status === this.filters.status);
            }

            if (this.filters.search) {
                const search = this.filters.search.toLowerCase();
                result = result.filter(u =>
                    u.email.toLowerCase().includes(search) ||
                    u.firstname.toLowerCase().includes(search) ||
                    u.lastname.toLowerCase().includes(search)
                );
            }

            return result;
        },

        paginatedUsers() {
            const start = (this.currentPage - 1) * this.itemsPerPage;
            const end = start + this.itemsPerPage;
            return this.filteredUsers.slice(start, end);
        },

        totalPages() {
            return Math.ceil(this.filteredUsers.length / this.itemsPerPage);
        },

        roleLabel() {
            return (role) => {
                const r = this.roles.find(r => r.value === role);
                return r ? r.label : role;
            };
        },

        roleColor() {
            return (role) => {
                const r = this.roles.find(r => r.value === role);
                return r ? r.color : '#6b7280';
            };
        },

        statusLabel() {
            return (status) => {
                const s = this.statuses.find(s => s.value === status);
                return s ? s.label : status;
            };
        },

        statusColor() {
            return (status) => {
                const s = this.statuses.find(s => s.value === status);
                return s ? s.color : '#6b7280';
            };
        }
    },

    mounted() {
        this.loadUsers();
        this.loadClasses();
    },

    methods: {
        async loadUsers() {
            this.loading = true;
            this.error = null;

            try {
                const response = await fetch('/api/admin/users', {
                    headers: {
                        'Authorization': `Bearer ${this.getToken()}`,
                        'X-Orchestrator-Id': this.getTenantId()
                    }
                });

                if (!response.ok) {
                    throw new Error('Failed to load users');
                }

                const data = await response.json();
                this.users = data.users || [];
            } catch (error) {
                this.error = error.message;
                console.error('Error loading users:', error);
            } finally {
                this.loading = false;
            }
        },

        async loadClasses() {
            try {
                const response = await fetch('/api/admin/classes', {
                    headers: {
                        'Authorization': `Bearer ${this.getToken()}`,
                        'X-Orchestrator-Id': this.getTenantId()
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.classes = data.classes || [];
                }
            } catch (error) {
                console.error('Error loading classes:', error);
            }
        },

        openCreateModal() {
            this.modalMode = 'create';
            this.selectedUser = null;
            this.form = {
                email: '',
                firstname: '',
                lastname: '',
                role: 'teacher',
                class_ids: [],
                send_invitation: true
            };
            this.formErrors = {};
            this.showModal = true;
        },

        openEditModal(user) {
            this.modalMode = 'edit';
            this.selectedUser = user;
            this.form = {
                email: user.email,
                firstname: user.firstname,
                lastname: user.lastname,
                role: user.role,
                class_ids: user.classes ? user.classes.ids : [],
                send_invitation: false
            };
            this.formErrors = {};
            this.showModal = true;
        },

        openViewModal(user) {
            this.modalMode = 'view';
            this.selectedUser = user;
            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.selectedUser = null;
            this.form = {
                email: '',
                firstname: '',
                lastname: '',
                role: 'teacher',
                class_ids: [],
                send_invitation: true
            };
            this.formErrors = {};
        },

        validateForm() {
            this.formErrors = {};

            if (!this.form.email || !this.form.email.includes('@')) {
                this.formErrors.email = 'Email invalide';
            }

            if (!this.form.firstname || this.form.firstname.trim().length < 2) {
                this.formErrors.firstname = 'Prénom requis (min. 2 caractères)';
            }

            if (!this.form.lastname || this.form.lastname.trim().length < 2) {
                this.formErrors.lastname = 'Nom requis (min. 2 caractères)';
            }

            if (!this.form.role) {
                this.formErrors.role = 'Rôle requis';
            }

            return Object.keys(this.formErrors).length === 0;
        },

        async saveUser() {
            if (!this.validateForm()) {
                return;
            }

            this.loading = true;

            try {
                const url = this.modalMode === 'create'
                    ? '/api/admin/users'
                    : `/api/admin/users/${this.selectedUser.id}`;

                const method = this.modalMode === 'create' ? 'POST' : 'PATCH';

                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.getToken()}`,
                        'X-Orchestrator-Id': this.getTenantId()
                    },
                    body: JSON.stringify(this.form)
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to save user');
                }

                // Reload users
                await this.loadUsers();

                // Close modal
                this.closeModal();

                // Show success message
                this.showSuccess(this.modalMode === 'create' ? 'Utilisateur créé avec succès' : 'Utilisateur mis à jour');
            } catch (error) {
                this.error = error.message;
                console.error('Error saving user:', error);
            } finally {
                this.loading = false;
            }
        },

        async changeUserStatus(user, newStatus) {
            if (!confirm(`Voulez-vous vraiment ${newStatus === 'inactive' ? 'désactiver' : 'activer'} cet utilisateur ?`)) {
                return;
            }

            this.loading = true;

            try {
                const response = await fetch(`/api/admin/users/${user.id}/status`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.getToken()}`,
                        'X-Orchestrator-Id': this.getTenantId()
                    },
                    body: JSON.stringify({ status: newStatus })
                });

                if (!response.ok) {
                    throw new Error('Failed to change user status');
                }

                // Reload users
                await this.loadUsers();

                this.showSuccess('Statut mis à jour');
            } catch (error) {
                this.error = error.message;
                console.error('Error changing user status:', error);
            } finally {
                this.loading = false;
            }
        },

        formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        },

        getToken() {
            return localStorage.getItem('auth_token') || '';
        },

        getTenantId() {
            return localStorage.getItem('tenant_id') || '';
        },

        showSuccess(message) {
            // TODO: Implement toast/notification
            alert(message);
        }
    },

    template: `
        <div class="admin-users-view">
            <div class="header">
                <h1>Gestion des utilisateurs</h1>
                <button @click="openCreateModal" class="btn-primary">
                    + Créer un utilisateur
                </button>
            </div>

            <!-- Filters -->
            <div class="filters">
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Rechercher par nom ou email..."
                    class="search-input"
                />

                <select v-model="filters.role" class="filter-select">
                    <option value="">Tous les rôles</option>
                    <option v-for="role in roles" :key="role.value" :value="role.value">
                        {{ role.label }}
                    </option>
                </select>

                <select v-model="filters.status" class="filter-select">
                    <option value="">Tous les statuts</option>
                    <option v-for="status in statuses" :key="status.value" :value="status.value">
                        {{ status.label }}
                    </option>
                </select>
            </div>

            <!-- Error message -->
            <div v-if="error" class="error-message">
                {{ error }}
            </div>

            <!-- Loading -->
            <div v-if="loading && users.length === 0" class="loading">
                Chargement...
            </div>

            <!-- Users table -->
            <div v-else class="users-table">
                <table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Email</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th>Classes</th>
                            <th>Dernière connexion</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in paginatedUsers" :key="user.id">
                            <td>{{ user.firstname }} {{ user.lastname }}</td>
                            <td>{{ user.email }}</td>
                            <td>
                                <span class="badge" :style="{ backgroundColor: roleColor(user.role) }">
                                    {{ roleLabel(user.role) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge" :style="{ backgroundColor: statusColor(user.status) }">
                                    {{ statusLabel(user.status) }}
                                </span>
                            </td>
                            <td>
                                <span v-if="user.classes">
                                    {{ user.classes.names.join(', ') }}
                                </span>
                                <span v-else>-</span>
                            </td>
                            <td>{{ formatDate(user.lastLoginAt) }}</td>
                            <td class="actions">
                                <button @click="openViewModal(user)" class="btn-icon" title="Voir">👁️</button>
                                <button @click="openEditModal(user)" class="btn-icon" title="Modifier">✏️</button>
                                <button
                                    v-if="user.status === 'active'"
                                    @click="changeUserStatus(user, 'inactive')"
                                    class="btn-icon"
                                    title="Désactiver"
                                >
                                    🚫
                                </button>
                                <button
                                    v-else
                                    @click="changeUserStatus(user, 'active')"
                                    class="btn-icon"
                                    title="Activer"
                                >
                                    ✅
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="pagination">
                    <button
                        @click="currentPage--"
                        :disabled="currentPage === 1"
                        class="btn-secondary"
                    >
                        ← Précédent
                    </button>
                    <span>Page {{ currentPage }} / {{ totalPages }}</span>
                    <button
                        @click="currentPage++"
                        :disabled="currentPage === totalPages"
                        class="btn-secondary"
                    >
                        Suivant →
                    </button>
                </div>
            </div>

            <!-- Modal -->
            <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
                <div class="modal">
                    <div class="modal-header">
                        <h2>
                            <span v-if="modalMode === 'create'">Créer un utilisateur</span>
                            <span v-else-if="modalMode === 'edit'">Modifier l'utilisateur</span>
                            <span v-else>Détails de l'utilisateur</span>
                        </h2>
                        <button @click="closeModal" class="btn-close">×</button>
                    </div>

                    <div class="modal-body">
                        <div v-if="modalMode !== 'view'">
                            <!-- Email -->
                            <div class="form-group">
                                <label>Email *</label>
                                <input
                                    v-model="form.email"
                                    type="email"
                                    :disabled="modalMode === 'edit'"
                                    class="form-input"
                                    :class="{ error: formErrors.email }"
                                />
                                <span v-if="formErrors.email" class="error-text">{{ formErrors.email }}</span>
                            </div>

                            <!-- Firstname -->
                            <div class="form-group">
                                <label>Prénom *</label>
                                <input
                                    v-model="form.firstname"
                                    type="text"
                                    class="form-input"
                                    :class="{ error: formErrors.firstname }"
                                />
                                <span v-if="formErrors.firstname" class="error-text">{{ formErrors.firstname }}</span>
                            </div>

                            <!-- Lastname -->
                            <div class="form-group">
                                <label>Nom *</label>
                                <input
                                    v-model="form.lastname"
                                    type="text"
                                    class="form-input"
                                    :class="{ error: formErrors.lastname }"
                                />
                                <span v-if="formErrors.lastname" class="error-text">{{ formErrors.lastname }}</span>
                            </div>

                            <!-- Role -->
                            <div class="form-group">
                                <label>Rôle *</label>
                                <select v-model="form.role" class="form-input">
                                    <option v-for="role in roles" :key="role.value" :value="role.value">
                                        {{ role.label }}
                                    </option>
                                </select>
                            </div>

                            <!-- Classes -->
                            <div class="form-group">
                                <label>Classes</label>
                                <select v-model="form.class_ids" multiple class="form-input" size="5">
                                    <option v-for="cls in classes" :key="cls.id" :value="cls.id">
                                        {{ cls.name }} ({{ cls.level }})
                                    </option>
                                </select>
                                <small>Maintenez Ctrl/Cmd pour sélectionner plusieurs classes</small>
                            </div>

                            <!-- Send invitation (create only) -->
                            <div v-if="modalMode === 'create'" class="form-group">
                                <label>
                                    <input v-model="form.send_invitation" type="checkbox" />
                                    Envoyer une invitation par email
                                </label>
                            </div>
                        </div>

                        <!-- View mode -->
                        <div v-else class="user-details">
                            <p><strong>Email:</strong> {{ selectedUser.email }}</p>
                            <p><strong>Nom:</strong> {{ selectedUser.firstname }} {{ selectedUser.lastname }}</p>
                            <p><strong>Rôle:</strong> {{ roleLabel(selectedUser.role) }}</p>
                            <p><strong>Statut:</strong> {{ statusLabel(selectedUser.status) }}</p>
                            <p><strong>Dernière connexion:</strong> {{ formatDate(selectedUser.lastLoginAt) }}</p>
                            <p><strong>Créé le:</strong> {{ formatDate(selectedUser.createdAt) }}</p>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button @click="closeModal" class="btn-secondary">Annuler</button>
                        <button
                            v-if="modalMode !== 'view'"
                            @click="saveUser"
                            class="btn-primary"
                            :disabled="loading"
                        >
                            {{ modalMode === 'create' ? 'Créer' : 'Enregistrer' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `
};

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminUsersView;
}
