/**
 * AI Creator UI - Interface de génération de contenu pédagogique avec IA
 * Sprint 10 - Teacher-AI Copilot
 */

class AICreatorUI {
    constructor() {
        this.currentStep = 1;
        this.extractionId = null;
        this.generationId = null;
        this.themeId = null;
    }

    /**
     * Rendre l'interface du créateur AI
     */
    render() {
        return `
            <div class="ai-creator-container">
                <div class="ai-creator-header">
                    <h2>🤖 Créateur de Contenu IA</h2>
                    <p>Générez automatiquement des quiz, fiches et flashcards à partir de vos documents</p>
                </div>

                <!-- Stepper -->
                <div class="ai-creator-stepper">
                    <div class="step ${this.currentStep >= 1 ? 'active' : ''} ${this.currentStep > 1 ? 'completed' : ''}">
                        <div class="step-number">1</div>
                        <div class="step-label">Upload</div>
                    </div>
                    <div class="step ${this.currentStep >= 2 ? 'active' : ''} ${this.currentStep > 2 ? 'completed' : ''}">
                        <div class="step-number">2</div>
                        <div class="step-label">Extraction</div>
                    </div>
                    <div class="step ${this.currentStep >= 3 ? 'active' : ''} ${this.currentStep > 3 ? 'completed' : ''}">
                        <div class="step-number">3</div>
                        <div class="step-label">Génération</div>
                    </div>
                    <div class="step ${this.currentStep >= 4 ? 'active' : ''} ${this.currentStep > 4 ? 'completed' : ''}">
                        <div class="step-number">4</div>
                        <div class="step-label">Publication</div>
                    </div>
                </div>

                <!-- Contenu -->
                <div class="ai-creator-content">
                    ${this.renderStep()}
                </div>
            </div>
        `;
    }

    /**
     * Rendre l'étape courante
     */
    renderStep() {
        switch (this.currentStep) {
            case 1:
                return this.renderUploadStep();
            case 2:
                return this.renderExtractionStep();
            case 3:
                return this.renderGenerationStep();
            case 4:
                return this.renderPublicationStep();
            default:
                return '';
        }
    }

    /**
     * Étape 1 : Upload de fichier
     */
    renderUploadStep() {
        return `
            <div class="upload-step">
                <h3>📁 Uploadez votre document</h3>
                <p>Formats acceptés : PDF, MP3, WAV, M4A (audio)</p>

                <div class="upload-zone" id="uploadZone" ondrop="aiCreator.handleDrop(event)" ondragover="event.preventDefault()">
                    <input type="file" id="fileInput" accept=".pdf,.mp3,.wav,.m4a,.ogg" onchange="aiCreator.handleFileSelect(event)" style="display:none">
                    <div class="upload-icon">📄</div>
                    <p>Glissez-déposez votre fichier ici</p>
                    <p>ou</p>
                    <button class="btn btn-primary" onclick="document.getElementById('fileInput').click()">
                        Choisir un fichier
                    </button>
                </div>

                <div id="uploadProgress" style="display:none">
                    <div class="progress-bar">
                        <div class="progress-fill" id="uploadProgressBar"></div>
                    </div>
                    <p id="uploadStatus">Téléchargement en cours...</p>
                </div>

                <div id="uploadError" class="error-message" style="display:none"></div>
            </div>
        `;
    }

    /**
     * Étape 2 : Extraction en cours
     */
    renderExtractionStep() {
        return `
            <div class="extraction-step">
                <h3>⚙️ Extraction du texte en cours...</h3>
                <div class="loader"></div>
                <p id="extractionStatus">Analyse du document...</p>
                <div id="extractedText" style="display:none">
                    <h4>Texte extrait :</h4>
                    <div class="text-preview" id="textPreview"></div>
                    <button class="btn btn-primary" onclick="aiCreator.proceedToGeneration()">
                        Continuer →
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Étape 3 : Configuration de la génération
     */
    renderGenerationStep() {
        return `
            <div class="generation-step">
                <h3>✨ Configuration de la génération</h3>

                <div class="form-group">
                    <label for="generationType">Type de contenu</label>
                    <select id="generationType" class="form-control">
                        <option value="theme">Thème complet (Quiz + Flashcards + Fiche)</option>
                        <option value="quiz">Quiz uniquement</option>
                        <option value="flashcards">Flashcards uniquement</option>
                        <option value="fiche">Fiche de révision uniquement</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="difficulty">Niveau de difficulté</label>
                    <select id="difficulty" class="form-control">
                        <option value="beginner">Débutant</option>
                        <option value="intermediate" selected>Intermédiaire</option>
                        <option value="advanced">Avancé</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="aiCreator.goBack()">
                        ← Retour
                    </button>
                    <button class="btn btn-primary" onclick="aiCreator.startGeneration()">
                        Générer avec l'IA 🚀
                    </button>
                </div>

                <div id="generationProgress" style="display:none">
                    <div class="loader"></div>
                    <p id="generationStatus">Génération en cours...</p>
                </div>

                <div id="generationResult" style="display:none">
                    <h4>✅ Contenu généré avec succès !</h4>
                    <div id="generatedContent"></div>
                    <button class="btn btn-primary" onclick="aiCreator.proceedToPublication()">
                        Publier →
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Étape 4 : Publication vers Ergo-Mate
     */
    renderPublicationStep() {
        return `
            <div class="publication-step">
                <h3>📤 Publication vers Ergo-Mate</h3>

                <div class="form-group">
                    <label>Type de publication</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="publicationType" value="catalog" checked>
                            Catalogue (disponible pour tous)
                        </label>
                        <label>
                            <input type="radio" name="publicationType" value="assignment">
                            Affectation directe (ciblée)
                        </label>
                    </div>
                </div>

                <div id="assignmentTargets" style="display:none">
                    <div class="form-group">
                        <label for="targetClasses">Classes cibles</label>
                        <select id="targetClasses" class="form-control" multiple></select>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-secondary" onclick="aiCreator.goBack()">
                        ← Retour
                    </button>
                    <button class="btn btn-success" onclick="aiCreator.publish()">
                        Publier vers Ergo-Mate
                    </button>
                </div>

                <div id="publicationProgress" style="display:none">
                    <div class="loader"></div>
                    <p id="publicationStatus">Publication en cours...</p>
                </div>

                <div id="publicationResult" style="display:none"></div>
            </div>
        `;
    }

    /**
     * Gérer la sélection de fichier
     */
    async handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        await this.uploadFile(file);
    }

    /**
     * Gérer le drag & drop
     */
    handleDrop(event) {
        event.preventDefault();
        const file = event.dataTransfer.files[0];
        if (file) {
            this.uploadFile(file);
        }
    }

    /**
     * Upload et extraction du fichier
     */
    async uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        // Afficher la progression
        document.getElementById('uploadProgress').style.display = 'block';
        document.getElementById('uploadError').style.display = 'none';

        try {
            const response = await apiCall('/api/ingest/upload', {
                method: 'POST',
                body: formData,
                headers: {} // Let browser set Content-Type for FormData
            });

            this.extractionId = response.extraction_id;

            // Passer à l'étape suivante
            this.currentStep = 2;
            this.updateUI();

            // Afficher le texte extrait
            setTimeout(() => {
                document.getElementById('extractionStatus').textContent = 'Extraction terminée !';
                document.getElementById('textPreview').textContent =
                    response.text.substring(0, 500) + '...';
                document.getElementById('extractedText').style.display = 'block';
            }, 1000);

        } catch (error) {
            document.getElementById('uploadError').textContent =
                'Erreur lors de l\'upload : ' + (error.message || 'Erreur inconnue');
            document.getElementById('uploadError').style.display = 'block';
            document.getElementById('uploadProgress').style.display = 'none';
        }
    }

    /**
     * Passer à la génération
     */
    proceedToGeneration() {
        this.currentStep = 3;
        this.updateUI();
    }

    /**
     * Lancer la génération IA
     */
    async startGeneration() {
        const generationType = document.getElementById('generationType').value;
        const difficulty = document.getElementById('difficulty').value;

        document.getElementById('generationProgress').style.display = 'block';

        try {
            const response = await apiCall('/api/ingest/generate', {
                method: 'POST',
                body: JSON.stringify({
                    extraction_id: this.extractionId,
                    type: generationType,
                    difficulty: difficulty
                })
            });

            this.generationId = response.generation_id;
            this.themeId = response.theme_id;

            // Afficher le résultat
            document.getElementById('generationProgress').style.display = 'none';
            document.getElementById('generatedContent').innerHTML = this.renderGeneratedContent(response.result);
            document.getElementById('generationResult').style.display = 'block';

        } catch (error) {
            alert('Erreur lors de la génération : ' + (error.message || 'Erreur inconnue'));
            document.getElementById('generationProgress').style.display = 'none';
        }
    }

    /**
     * Rendre l'aperçu du contenu généré
     */
    renderGeneratedContent(content) {
        let html = '<div class="generated-content-preview">';

        html += `<h5>${content.title}</h5>`;
        html += `<p>${content.description}</p>`;
        html += `<p><strong>Difficulté:</strong> ${content.difficulty}</p>`;

        if (content.questions) {
            html += `<p><strong>Questions:</strong> ${content.questions.length}</p>`;
        }

        if (content.flashcards) {
            html += `<p><strong>Flashcards:</strong> ${content.flashcards.length}</p>`;
        }

        if (content.fiche) {
            html += `<p><strong>Fiche:</strong> ${content.fiche.sections?.length || 0} sections</p>`;
        }

        html += '</div>';
        return html;
    }

    /**
     * Passer à la publication
     */
    proceedToPublication() {
        this.currentStep = 4;
        this.updateUI();
        this.loadClasses();
    }

    /**
     * Charger les classes pour les affectations
     */
    async loadClasses() {
        try {
            const response = await apiCall('/api/classes', { method: 'GET' });
            const select = document.getElementById('targetClasses');
            if (select) {
                select.innerHTML = response.classes.map(c =>
                    `<option value="${c.id}">${c.name}</option>`
                ).join('');
            }
        } catch (error) {
            console.error('Error loading classes:', error);
        }

        // Gérer l'affichage conditionnel des cibles
        const radioButtons = document.querySelectorAll('input[name="publicationType"]');
        radioButtons.forEach(radio => {
            radio.addEventListener('change', (e) => {
                const targets = document.getElementById('assignmentTargets');
                if (targets) {
                    targets.style.display = e.target.value === 'assignment' ? 'block' : 'none';
                }
            });
        });
    }

    /**
     * Publier vers Ergo-Mate
     */
    async publish() {
        const publicationType = document.querySelector('input[name="publicationType"]:checked').value;
        let targetClasses = null;

        if (publicationType === 'assignment') {
            const select = document.getElementById('targetClasses');
            targetClasses = Array.from(select.selectedOptions).map(opt => opt.value);

            if (targetClasses.length === 0) {
                alert('Veuillez sélectionner au moins une classe cible');
                return;
            }
        }

        document.getElementById('publicationProgress').style.display = 'block';

        try {
            const response = await apiCall('/api/publish/theme', {
                method: 'POST',
                body: JSON.stringify({
                    theme_id: this.themeId,
                    generation_id: this.generationId,
                    publication_type: publicationType,
                    target_classes: targetClasses
                })
            });

            document.getElementById('publicationProgress').style.display = 'none';
            document.getElementById('publicationResult').innerHTML = `
                <div class="success-message">
                    <h4>✅ Publication réussie !</h4>
                    <p>Votre contenu a été publié avec succès vers Ergo-Mate.</p>
                    <p><strong>ID Publication:</strong> ${response.publication_id}</p>
                    ${response.ergomate_theme_id ? `<p><strong>ID Thème Ergo-Mate:</strong> ${response.ergomate_theme_id}</p>` : ''}
                    ${response.ergomate_assignment_id ? `<p><strong>ID Affectation:</strong> ${response.ergomate_assignment_id}</p>` : ''}
                    <button class="btn btn-primary" onclick="aiCreator.reset()">
                        Créer un nouveau contenu
                    </button>
                </div>
            `;
            document.getElementById('publicationResult').style.display = 'block';

        } catch (error) {
            alert('Erreur lors de la publication : ' + (error.message || 'Erreur inconnue'));
            document.getElementById('publicationProgress').style.display = 'none';
        }
    }

    /**
     * Retour à l'étape précédente
     */
    goBack() {
        if (this.currentStep > 1) {
            this.currentStep--;
            this.updateUI();
        }
    }

    /**
     * Réinitialiser le créateur
     */
    reset() {
        this.currentStep = 1;
        this.extractionId = null;
        this.generationId = null;
        this.themeId = null;
        this.updateUI();
    }

    /**
     * Mettre à jour l'interface
     */
    updateUI() {
        const container = document.getElementById('aiCreatorContainer');
        if (container) {
            container.innerHTML = this.render();
        }
    }
}

// Instance globale
const aiCreator = new AICreatorUI();
