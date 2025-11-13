<?php
/**
 * Service IA - Génération de contenu pédagogique
 *
 * Intégration avec Mistral AI pour générer :
 * - Thèmes complets
 * - Quiz
 * - Flashcards
 * - Fiches de révision
 */

class AIService {
    private $db;
    private $apiKey;

    public function __construct($apiKey = null) {
        $this->db = db();
        $this->apiKey = $apiKey;
    }

    /**
     * Générer un thème depuis du texte
     *
     * @param string $text Texte source
     * @param string $userId ID de l'utilisateur
     * @param string $tenantId ID du tenant
     * @param array $options Options de génération
     * @return array Résultat de la génération
     */
    public function generateThemeFromText($text, $userId, $tenantId, $options = []) {
        // Valider les paramètres
        if (empty($text)) {
            throw new Exception('Text cannot be empty');
        }

        // Calculer le hash du contenu source
        $sourceHash = hash('sha256', $text);

        // Vérifier si une génération identique existe déjà (cache)
        $existing = $this->db->queryOne(
            'SELECT id, result_json, validation_status, theme_id
             FROM ai_generations
             WHERE source_hash = :hash
               AND tenant_id = :tenant_id
               AND generation_type = :type
               AND validation_status = \'valid\'
               AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC
             LIMIT 1',
            [
                'hash' => $sourceHash,
                'tenant_id' => $tenantId,
                'type' => $options['type'] ?? 'theme'
            ]
        );

        if ($existing && $existing['result_json']) {
            logInfo('Using cached AI generation', [
                'generation_id' => $existing['id'],
                'theme_id' => $existing['theme_id']
            ]);

            return [
                'generation_id' => $existing['id'],
                'theme_id' => $existing['theme_id'],
                'result' => json_decode($existing['result_json'], true),
                'cached' => true
            ];
        }

        // Créer un enregistrement de génération
        $generationId = generateId('aigen');

        $this->db->execute(
            'INSERT INTO ai_generations
             (id, tenant_id, user_id, generation_type, source_type, source_hash, status, created_at)
             VALUES (:id, :tenant_id, :user_id, :generation_type, :source_type, :source_hash, :status, NOW())',
            [
                'id' => $generationId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'generation_type' => $options['type'] ?? 'theme',
                'source_type' => 'text',
                'source_hash' => $sourceHash,
                'status' => 'processing'
            ]
        );

        // Générer via Mistral AI
        try {
            $startTime = microtime(true);

            $result = $this->callMistralAPI($text, $options);

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Valider le résultat contre le schéma
            $validator = new SchemaValidator();
            $validation = $validator->validateTheme($result);

            if ($validation['valid']) {
                // Créer le thème
                $themeId = $this->createThemeFromGeneration($result, $userId, $tenantId);

                // Mettre à jour l'enregistrement de génération
                $this->db->execute(
                    'UPDATE ai_generations
                     SET result_json = :result_json,
                         validation_status = :validation_status,
                         theme_id = :theme_id,
                         status = :status,
                         processing_time_ms = :processing_time_ms,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'id' => $generationId,
                        'result_json' => json_encode($result),
                        'validation_status' => 'valid',
                        'theme_id' => $themeId,
                        'status' => 'completed',
                        'processing_time_ms' => (int)$processingTime
                    ]
                );

                logInfo('AI theme generated successfully', [
                    'generation_id' => $generationId,
                    'theme_id' => $themeId,
                    'processing_time_ms' => (int)$processingTime
                ]);

                return [
                    'generation_id' => $generationId,
                    'theme_id' => $themeId,
                    'result' => $result,
                    'validation' => $validation,
                    'processing_time_ms' => (int)$processingTime
                ];

            } else {
                // Validation échouée
                $this->db->execute(
                    'UPDATE ai_generations
                     SET result_json = :result_json,
                         validation_status = :validation_status,
                         validation_errors = :validation_errors,
                         status = :status,
                         processing_time_ms = :processing_time_ms,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'id' => $generationId,
                        'result_json' => json_encode($result),
                        'validation_status' => 'invalid',
                        'validation_errors' => json_encode($validation['errors']),
                        'status' => 'completed',
                        'processing_time_ms' => (int)$processingTime
                    ]
                );

                logWarn('AI generation validation failed', [
                    'generation_id' => $generationId,
                    'errors' => $validation['errors']
                ]);

                return [
                    'generation_id' => $generationId,
                    'result' => $result,
                    'validation' => $validation,
                    'processing_time_ms' => (int)$processingTime
                ];
            }

        } catch (Exception $e) {
            // Erreur lors de la génération
            $this->db->execute(
                'UPDATE ai_generations
                 SET status = :status,
                     error_message = :error_message,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $generationId,
                    'status' => 'error',
                    'error_message' => $e->getMessage()
                ]
            );

            logError('AI generation failed', [
                'generation_id' => $generationId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Appeler l'API Mistral
     */
    private function callMistralAPI($text, $options = []) {
        // En mode MOCK, retourner un thème de test
        if (defined('MOCK_MODE') && MOCK_MODE === true) {
            return $this->generateMockTheme($text, $options);
        }

        if (!$this->apiKey) {
            throw new Exception('Mistral API key not configured');
        }

        $type = $options['type'] ?? 'theme';
        $difficulty = $options['difficulty'] ?? 'intermediate';

        // Construire le prompt
        $prompt = $this->buildPrompt($text, $type, $difficulty);

        // Appeler Mistral API
        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'mistral-medium',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es un assistant pédagogique expert. Tu génères du contenu éducatif de haute qualité au format JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Mistral API error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new Exception('Invalid Mistral API response');
        }

        // Parser le JSON depuis la réponse
        $content = $data['choices'][0]['message']['content'];

        // Extraire le JSON (peut être entouré de ```json ... ```)
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $jsonStr = $matches[1];
        } else {
            $jsonStr = $content;
        }

        $result = json_decode($jsonStr, true);

        if (!$result) {
            throw new Exception('Failed to parse AI response as JSON');
        }

        return $result;
    }

    /**
     * Construire le prompt pour Mistral
     */
    private function buildPrompt($text, $type, $difficulty) {
        $prompts = [
            'theme' => "Génère un thème pédagogique complet au format JSON à partir du texte suivant.
Le thème doit contenir :
- Un titre accrocheur
- Une description claire
- Au moins 10 questions de type QCM avec 4 choix et une seule bonne réponse
- Au moins 10 flashcards avec une face (question) et un verso (réponse)
- Une fiche de révision structurée avec les points clés

Format JSON attendu :
{
  \"title\": \"Titre du thème\",
  \"description\": \"Description\",
  \"difficulty\": \"$difficulty\",
  \"questions\": [
    {\"id\": \"q1\", \"text\": \"Question?\", \"choices\": [\"A\", \"B\", \"C\", \"D\"], \"correctAnswer\": 0, \"explanation\": \"Explication\"}
  ],
  \"flashcards\": [
    {\"id\": \"f1\", \"front\": \"Question\", \"back\": \"Réponse\"}
  ],
  \"fiche\": {
    \"sections\": [
      {\"title\": \"Section 1\", \"content\": \"Contenu\", \"keyPoints\": [\"Point 1\", \"Point 2\"]}
    ]
  }
}

Texte source :
$text",

            'quiz' => "Génère un quiz de 15 questions QCM au format JSON à partir du texte suivant...",

            'flashcards' => "Génère 20 flashcards au format JSON à partir du texte suivant..."
        ];

        return $prompts[$type] ?? $prompts['theme'];
    }

    /**
     * Générer un thème mock pour les tests
     */
    private function generateMockTheme($text, $options = []) {
        return [
            'title' => 'Thème généré par IA (MOCK)',
            'description' => 'Ceci est un thème de test généré automatiquement.',
            'difficulty' => $options['difficulty'] ?? 'intermediate',
            'questions' => [
                [
                    'id' => 'q1',
                    'text' => 'Question de test 1 ?',
                    'choices' => ['Réponse A', 'Réponse B', 'Réponse C', 'Réponse D'],
                    'correctAnswer' => 0,
                    'explanation' => 'Explication de la réponse correcte'
                ],
                [
                    'id' => 'q2',
                    'text' => 'Question de test 2 ?',
                    'choices' => ['Réponse A', 'Réponse B', 'Réponse C', 'Réponse D'],
                    'correctAnswer' => 1,
                    'explanation' => 'Explication de la réponse correcte'
                ]
            ],
            'flashcards' => [
                [
                    'id' => 'f1',
                    'front' => 'Qu\'est-ce que X ?',
                    'back' => 'X est...'
                ],
                [
                    'id' => 'f2',
                    'front' => 'Définir Y',
                    'back' => 'Y se définit comme...'
                ]
            ],
            'fiche' => [
                'sections' => [
                    [
                        'title' => 'Introduction',
                        'content' => 'Contenu de l\'introduction',
                        'keyPoints' => ['Point clé 1', 'Point clé 2']
                    ],
                    [
                        'title' => 'Concepts principaux',
                        'content' => 'Explication des concepts',
                        'keyPoints' => ['Concept A', 'Concept B']
                    ]
                ]
            ]
        ];
    }

    /**
     * Créer un thème à partir d'une génération IA
     */
    private function createThemeFromGeneration($data, $userId, $tenantId) {
        $themeId = generateId('theme');

        $this->db->execute(
            'INSERT INTO themes
             (id, tenant_id, created_by, title, description, content, difficulty, source, status, created_at)
             VALUES (:id, :tenant_id, :created_by, :title, :description, :content, :difficulty, :source, :status, NOW())',
            [
                'id' => $themeId,
                'tenant_id' => $tenantId,
                'created_by' => $userId,
                'title' => $data['title'] ?? 'Thème sans titre',
                'description' => $data['description'] ?? '',
                'content' => json_encode($data),
                'difficulty' => $data['difficulty'] ?? 'intermediate',
                'source' => 'pdf_mistral',
                'status' => 'draft'
            ]
        );

        return $themeId;
    }
}

/**
 * Validateur de schéma JSON
 */
class SchemaValidator {
    /**
     * Valider un thème contre le schéma ErgoMate
     *
     * @param array $data Données du thème
     * @param bool $strictErgoMate Validation stricte contre schéma Ergo-Mate complet
     * @return array Résultat de validation
     */
    public function validateTheme($data, $strictErgoMate = false) {
        $errors = [];

        // Vérifier les champs obligatoires
        if (empty($data['title'])) {
            $errors[] = 'Missing required field: title';
        } elseif (strlen($data['title']) < 3 || strlen($data['title']) > 255) {
            $errors[] = 'Title must be between 3 and 255 characters';
        }

        if (empty($data['description'])) {
            $errors[] = 'Missing required field: description';
        } elseif (strlen($data['description']) < 10 || strlen($data['description']) > 2000) {
            $errors[] = 'Description must be between 10 and 2000 characters';
        }

        if (empty($data['difficulty'])) {
            $errors[] = 'Missing required field: difficulty';
        } elseif (!in_array($data['difficulty'], ['beginner', 'intermediate', 'advanced'])) {
            $errors[] = 'Invalid difficulty value';
        }

        // Validation Ergo-Mate stricte
        if ($strictErgoMate) {
            if (empty($data['content_type'])) {
                $errors[] = 'Missing required field: content_type';
            } elseif (!in_array($data['content_type'], ['complete', 'quiz', 'flashcards', 'fiche'])) {
                $errors[] = 'Invalid content_type value';
            }

            // Vérifier qu'il y a au moins du contenu
            $hasContent = !empty($data['questions']) || !empty($data['flashcards']) || !empty($data['fiche']);
            if (!$hasContent) {
                $errors[] = 'Theme must have at least questions, flashcards, or fiche';
            }
        }

        // Valider les questions
        if (isset($data['questions'])) {
            if (!is_array($data['questions']) || empty($data['questions'])) {
                $errors[] = 'Questions must be a non-empty array';
            } else {
                foreach ($data['questions'] as $i => $q) {
                    if (empty($q['id'])) {
                        $errors[] = "Question $i: missing id";
                    } elseif (!preg_match('/^q[0-9]+$/', $q['id'])) {
                        $errors[] = "Question $i: id must match pattern 'q[0-9]+'";
                    }

                    if (empty($q['text'])) {
                        $errors[] = "Question $i: missing text";
                    } elseif (strlen($q['text']) < 5 || strlen($q['text']) > 1000) {
                        $errors[] = "Question $i: text must be between 5 and 1000 characters";
                    }

                    if (!isset($q['choices']) || !is_array($q['choices'])) {
                        $errors[] = "Question $i: missing choices array";
                    } elseif (count($q['choices']) < 2 || count($q['choices']) > 6) {
                        $errors[] = "Question $i: must have between 2 and 6 choices";
                    }

                    if (!isset($q['correctAnswer']) || !is_int($q['correctAnswer'])) {
                        $errors[] = "Question $i: missing or invalid correctAnswer (must be integer)";
                    } elseif (isset($q['choices']) && ($q['correctAnswer'] < 0 || $q['correctAnswer'] >= count($q['choices']))) {
                        $errors[] = "Question $i: correctAnswer out of range";
                    }
                }
            }
        }

        // Valider les flashcards
        if (isset($data['flashcards'])) {
            if (!is_array($data['flashcards'])) {
                $errors[] = 'Flashcards must be an array';
            } else {
                foreach ($data['flashcards'] as $i => $f) {
                    if (empty($f['id'])) {
                        $errors[] = "Flashcard $i: missing id";
                    } elseif (!preg_match('/^f[0-9]+$/', $f['id'])) {
                        $errors[] = "Flashcard $i: id must match pattern 'f[0-9]+'";
                    }

                    if (empty($f['front'])) {
                        $errors[] = "Flashcard $i: missing front";
                    } elseif (strlen($f['front']) < 3 || strlen($f['front']) > 500) {
                        $errors[] = "Flashcard $i: front must be between 3 and 500 characters";
                    }

                    if (empty($f['back'])) {
                        $errors[] = "Flashcard $i: missing back";
                    } elseif (strlen($f['back']) < 3 || strlen($f['back']) > 2000) {
                        $errors[] = "Flashcard $i: back must be between 3 and 2000 characters";
                    }
                }
            }
        }

        // Valider la fiche
        if (isset($data['fiche'])) {
            if (!is_array($data['fiche'])) {
                $errors[] = 'Fiche must be an object';
            } else {
                if (empty($data['fiche']['sections'])) {
                    $errors[] = 'Fiche: missing sections';
                } elseif (!is_array($data['fiche']['sections'])) {
                    $errors[] = 'Fiche: sections must be an array';
                } elseif (count($data['fiche']['sections']) === 0) {
                    $errors[] = 'Fiche: sections must have at least one section';
                } elseif (count($data['fiche']['sections']) > 20) {
                    $errors[] = 'Fiche: sections must have at most 20 sections';
                } else {
                    foreach ($data['fiche']['sections'] as $i => $section) {
                        if (empty($section['title'])) {
                            $errors[] = "Fiche section $i: missing title";
                        } elseif (strlen($section['title']) < 3 || strlen($section['title']) > 255) {
                            $errors[] = "Fiche section $i: title must be between 3 and 255 characters";
                        }

                        if (empty($section['content'])) {
                            $errors[] = "Fiche section $i: missing content";
                        } elseif (strlen($section['content']) < 10 || strlen($section['content']) > 5000) {
                            $errors[] = "Fiche section $i: content must be between 10 and 5000 characters";
                        }

                        if (isset($section['keyPoints'])) {
                            if (!is_array($section['keyPoints'])) {
                                $errors[] = "Fiche section $i: keyPoints must be an array";
                            } elseif (count($section['keyPoints']) > 10) {
                                $errors[] = "Fiche section $i: keyPoints must have at most 10 items";
                            }
                        }
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ergomate_compliant' => empty($errors) && $strictErgoMate
        ];
    }

    /**
     * Enrichir un thème avec des suggestions d'images
     *
     * @param array $theme Données du thème
     * @return array Thème enrichi avec suggestions d'images
     */
    public function enrichWithImageSuggestions($theme) {
        $imageSuggestions = [];

        // Suggérer des images pour les questions
        if (isset($theme['questions'])) {
            foreach ($theme['questions'] as $i => $question) {
                // Extraire des mots-clés de la question
                $keywords = $this->extractKeywords($question['text']);
                if (!empty($keywords)) {
                    $imageSuggestions['questions'][$question['id']] = [
                        'keywords' => $keywords,
                        'search_query' => implode(' ', $keywords),
                        'suggested_sources' => [
                            'unsplash' => "https://source.unsplash.com/800x600/?" . urlencode(implode(',', $keywords)),
                            'pexels' => "https://www.pexels.com/search/" . urlencode(implode(' ', $keywords))
                        ]
                    ];
                }
            }
        }

        // Suggérer des images pour les sections de fiche
        if (isset($theme['fiche']['sections'])) {
            foreach ($theme['fiche']['sections'] as $i => $section) {
                $keywords = $this->extractKeywords($section['title'] . ' ' . substr($section['content'], 0, 200));
                if (!empty($keywords)) {
                    $imageSuggestions['fiche_sections'][$i] = [
                        'keywords' => $keywords,
                        'search_query' => implode(' ', $keywords),
                        'suggested_sources' => [
                            'unsplash' => "https://source.unsplash.com/1200x800/?" . urlencode(implode(',', $keywords)),
                            'pexels' => "https://www.pexels.com/search/" . urlencode(implode(' ', $keywords))
                        ]
                    ];
                }
            }
        }

        return $imageSuggestions;
    }

    /**
     * Extraire des mots-clés d'un texte pour suggestions d'images
     */
    private function extractKeywords($text) {
        // Mots vides courants en français
        $stopWords = ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'ou', 'est', 'sont', 'a', 'à', 'en', 'dans', 'sur', 'pour', 'par', 'avec', 'ce', 'qui', 'que', 'quoi', 'comment', 'pourquoi'];

        // Nettoyer et tokeniser
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', $text);

        // Filtrer les mots vides et courts
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        });

        // Retourner les 3 premiers mots-clés
        return array_slice(array_values($keywords), 0, 3);
    }
}

