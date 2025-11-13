<?php
/**
 * Sprint 13 - US13-3: Theme Content Linter
 * Detects inconsistencies, factual errors, and structural issues in themes
 *
 * @package StudyMate\Services
 */

class ThemeLinter {
    /**
     * Lint theme content and detect anomalies
     *
     * @param array $theme Theme data
     * @return array Lint report with warnings and errors
     */
    public static function lint($theme) {
        $report = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
            'stats' => [
                'total_questions' => 0,
                'total_words' => 0,
                'avg_question_length' => 0,
                'avg_answer_length' => 0
            ]
        ];

        $questions = $theme['questions'] ?? [];
        $report['stats']['total_questions'] = count($questions);

        if (empty($questions)) {
            $report['errors'][] = [
                'code' => 'EMPTY_THEME',
                'message' => 'Le thème ne contient aucune question',
                'severity' => 'critical'
            ];
            return $report;
        }

        // Run various checks
        self::checkDuplicateQuestions($questions, $report);
        self::checkQuestionLengths($questions, $report);
        self::checkAnswerQuality($questions, $report);
        self::checkExplanations($questions, $report);
        self::checkDifficulty($questions, $report);
        self::checkFactualConsistency($questions, $report);
        self::checkFormattingIssues($questions, $report);

        // Calculate stats
        self::calculateStats($questions, $report);

        return $report;
    }

    /**
     * Check for duplicate or near-duplicate questions
     */
    private static function checkDuplicateQuestions($questions, &$report) {
        $seen = [];

        foreach ($questions as $index => $question) {
            $text = mb_strtolower(trim($question['question_text'] ?? ''));
            $normalized = preg_replace('/\s+/', ' ', $text);

            foreach ($seen as $seenIndex => $seenText) {
                $similarity = self::textSimilarity($normalized, $seenText);

                if ($similarity > 0.9) {
                    $report['errors'][] = [
                        'code' => 'DUPLICATE_QUESTION',
                        'message' => "Question " . ($index + 1) . " est identique à la question " . ($seenIndex + 1),
                        'severity' => 'high',
                        'location' => "Question " . ($index + 1)
                    ];
                } elseif ($similarity > 0.75) {
                    $report['warnings'][] = [
                        'code' => 'SIMILAR_QUESTION',
                        'message' => "Question " . ($index + 1) . " est très similaire à la question " . ($seenIndex + 1),
                        'severity' => 'medium',
                        'location' => "Question " . ($index + 1)
                    ];
                }
            }

            $seen[$index] = $normalized;
        }
    }

    /**
     * Check question length anomalies
     */
    private static function checkQuestionLengths($questions, &$report) {
        $lengths = array_map(fn($q) => mb_strlen($q['question_text'] ?? ''), $questions);
        $avgLength = array_sum($lengths) / count($lengths);
        $stdDev = self::standardDeviation($lengths);

        foreach ($questions as $index => $question) {
            $length = mb_strlen($question['question_text'] ?? '');

            // Too short
            if ($length < 10) {
                $report['errors'][] = [
                    'code' => 'QUESTION_TOO_SHORT',
                    'message' => "Question " . ($index + 1) . " est trop courte ($length caractères)",
                    'severity' => 'high',
                    'location' => "Question " . ($index + 1)
                ];
            }
            // Too long
            elseif ($length > 600) {
                $report['warnings'][] = [
                    'code' => 'QUESTION_TOO_LONG',
                    'message' => "Question " . ($index + 1) . " est très longue ($length caractères)",
                    'severity' => 'medium',
                    'location' => "Question " . ($index + 1)
                ];
            }
            // Statistical outlier
            elseif ($stdDev > 0 && abs($length - $avgLength) > 2 * $stdDev) {
                $report['info'][] = [
                    'code' => 'LENGTH_OUTLIER',
                    'message' => "Question " . ($index + 1) . " a une longueur inhabituelle ($length caractères vs moyenne $avgLength)",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }
        }
    }

    /**
     * Check answer quality issues
     */
    private static function checkAnswerQuality($questions, &$report) {
        foreach ($questions as $index => $question) {
            $correct = $question['correct_answer'] ?? '';
            $distractors = $question['distractors'] ?? [];
            $allAnswers = array_merge([$correct], $distractors);

            // Check minimum distractors
            if (count($distractors) < 2) {
                $report['errors'][] = [
                    'code' => 'INSUFFICIENT_DISTRACTORS',
                    'message' => "Question " . ($index + 1) . " a moins de 2 distracteurs",
                    'severity' => 'high',
                    'location' => "Question " . ($index + 1)
                ];
            }

            // Check for empty answers
            foreach ($allAnswers as $ansIndex => $answer) {
                if (empty(trim($answer))) {
                    $report['errors'][] = [
                        'code' => 'EMPTY_ANSWER',
                        'message' => "Question " . ($index + 1) . " contient une réponse vide",
                        'severity' => 'critical',
                        'location' => "Question " . ($index + 1) . ", réponse " . ($ansIndex + 1)
                    ];
                }
            }

            // Check for too-similar distractors
            for ($i = 0; $i < count($allAnswers); $i++) {
                for ($j = $i + 1; $j < count($allAnswers); $j++) {
                    $similarity = self::textSimilarity(
                        mb_strtolower($allAnswers[$i]),
                        mb_strtolower($allAnswers[$j])
                    );

                    if ($similarity > 0.85) {
                        $report['warnings'][] = [
                            'code' => 'SIMILAR_ANSWERS',
                            'message' => "Question " . ($index + 1) . " : deux réponses sont trop similaires",
                            'severity' => 'medium',
                            'location' => "Question " . ($index + 1)
                        ];
                        break 2;
                    }
                }
            }

            // Check length disparity (correct answer suspiciously longer)
            $correctLen = mb_strlen($correct);
            $distractorLengths = array_map('mb_strlen', $distractors);
            $avgDistractorLen = count($distractorLengths) > 0 ? array_sum($distractorLengths) / count($distractorLengths) : 0;

            if ($avgDistractorLen > 0 && $correctLen > $avgDistractorLen * 2.5) {
                $report['warnings'][] = [
                    'code' => 'ANSWER_LENGTH_DISPARITY',
                    'message' => "Question " . ($index + 1) . " : la réponse correcte est beaucoup plus longue que les distracteurs",
                    'severity' => 'medium',
                    'location' => "Question " . ($index + 1)
                ];
            }
        }
    }

    /**
     * Check explanation quality
     */
    private static function checkExplanations($questions, &$report) {
        foreach ($questions as $index => $question) {
            $explanation = $question['explanation'] ?? '';

            if (empty($explanation)) {
                $report['warnings'][] = [
                    'code' => 'MISSING_EXPLANATION',
                    'message' => "Question " . ($index + 1) . " n'a pas d'explication",
                    'severity' => 'medium',
                    'location' => "Question " . ($index + 1)
                ];
            } elseif (mb_strlen($explanation) < 20) {
                $report['warnings'][] = [
                    'code' => 'WEAK_EXPLANATION',
                    'message' => "Question " . ($index + 1) . " : explication trop brève",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }

            // Check if explanation accidentally reveals answer
            $correct = mb_strtolower($question['correct_answer'] ?? '');
            $explLower = mb_strtolower($explanation);

            if (!empty($correct) && mb_strlen($correct) > 5 && strpos($explLower, $correct) !== false) {
                $report['info'][] = [
                    'code' => 'EXPLANATION_REVEALS_ANSWER',
                    'message' => "Question " . ($index + 1) . " : l'explication contient textuellement la réponse",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }
        }
    }

    /**
     * Check difficulty distribution
     */
    private static function checkDifficulty($questions, &$report) {
        $difficulties = array_map(fn($q) => $q['difficulty'] ?? 'medium', $questions);
        $counts = array_count_values($difficulties);

        $easy = $counts['easy'] ?? 0;
        $medium = $counts['medium'] ?? 0;
        $hard = $counts['hard'] ?? 0;
        $total = count($questions);

        // Check for imbalanced difficulty
        if ($hard > $total * 0.7) {
            $report['warnings'][] = [
                'code' => 'TOO_MANY_HARD',
                'message' => "Plus de 70% des questions sont difficiles",
                'severity' => 'medium',
                'location' => 'Global'
            ];
        } elseif ($easy > $total * 0.7) {
            $report['info'][] = [
                'code' => 'TOO_MANY_EASY',
                'message' => "Plus de 70% des questions sont faciles",
                'severity' => 'low',
                'location' => 'Global'
            ];
        }
    }

    /**
     * Check factual consistency (basic heuristics)
     */
    private static function checkFactualConsistency($questions, &$report) {
        foreach ($questions as $index => $question) {
            $questionText = $question['question_text'] ?? '';
            $correct = $question['correct_answer'] ?? '';
            $explanation = $question['explanation'] ?? '';

            // Check for conflicting information
            $combined = mb_strtolower($questionText . ' ' . $explanation);

            // Date inconsistencies
            if (preg_match_all('/\b(19|20)\d{2}\b/', $combined, $dates)) {
                $uniqueDates = array_unique($dates[0]);
                if (count($uniqueDates) > 3) {
                    $report['warnings'][] = [
                        'code' => 'MULTIPLE_DATES',
                        'message' => "Question " . ($index + 1) . " contient plusieurs dates différentes",
                        'severity' => 'low',
                        'location' => "Question " . ($index + 1)
                    ];
                }
            }

            // Number inconsistencies
            if (preg_match_all('/\b\d+([.,]\d+)?\s*(km|m|kg|g|°C|%)\b/i', $combined, $numbers)) {
                if (count($numbers[0]) > 5) {
                    $report['info'][] = [
                        'code' => 'MANY_NUMBERS',
                        'message' => "Question " . ($index + 1) . " contient beaucoup de valeurs numériques",
                        'severity' => 'low',
                        'location' => "Question " . ($index + 1)
                    ];
                }
            }

            // Negative language in correct answer (possible inversion)
            if (preg_match('/\b(jamais|aucun|pas|ne .* pas|impossible)\b/i', $correct)) {
                $report['info'][] = [
                    'code' => 'NEGATIVE_ANSWER',
                    'message' => "Question " . ($index + 1) . " : réponse correcte formulée négativement",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }
        }
    }

    /**
     * Check formatting issues
     */
    private static function checkFormattingIssues($questions, &$report) {
        foreach ($questions as $index => $question) {
            $questionText = $question['question_text'] ?? '';

            // Check question mark
            if (!empty($questionText) && !preg_match('/[?!.»"]\s*$/u', trim($questionText))) {
                $report['warnings'][] = [
                    'code' => 'MISSING_PUNCTUATION',
                    'message' => "Question " . ($index + 1) . " : ponctuation finale manquante",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }

            // Check for code snippets without formatting
            if (preg_match('/(function|class|def|public|private|const)\s+\w+/i', $questionText)) {
                $report['info'][] = [
                    'code' => 'CODE_DETECTED',
                    'message' => "Question " . ($index + 1) . " semble contenir du code",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }

            // Check excessive whitespace
            if (preg_match('/\s{3,}/', $questionText)) {
                $report['warnings'][] = [
                    'code' => 'EXCESSIVE_WHITESPACE',
                    'message' => "Question " . ($index + 1) . " contient des espaces excessifs",
                    'severity' => 'low',
                    'location' => "Question " . ($index + 1)
                ];
            }
        }
    }

    /**
     * Calculate statistics
     */
    private static function calculateStats($questions, &$report) {
        $totalWords = 0;
        $questionLengths = [];
        $answerLengths = [];

        foreach ($questions as $question) {
            $questionText = $question['question_text'] ?? '';
            $words = str_word_count($questionText);
            $totalWords += $words;
            $questionLengths[] = mb_strlen($questionText);

            $allAnswers = array_merge(
                [$question['correct_answer'] ?? ''],
                $question['distractors'] ?? []
            );
            foreach ($allAnswers as $answer) {
                $answerLengths[] = mb_strlen($answer);
            }
        }

        $report['stats']['total_words'] = $totalWords;
        $report['stats']['avg_question_length'] = count($questionLengths) > 0
            ? round(array_sum($questionLengths) / count($questionLengths))
            : 0;
        $report['stats']['avg_answer_length'] = count($answerLengths) > 0
            ? round(array_sum($answerLengths) / count($answerLengths))
            : 0;
    }

    /**
     * Calculate text similarity (simple implementation)
     */
    private static function textSimilarity($str1, $str2) {
        if (empty($str1) || empty($str2)) return 0;
        if ($str1 === $str2) return 1;

        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        $distance = levenshtein(
            substr($str1, 0, 255),
            substr($str2, 0, 255)
        );

        return 1 - ($distance / $maxLen);
    }

    /**
     * Calculate standard deviation
     */
    private static function standardDeviation($values) {
        $n = count($values);
        if ($n === 0) return 0;

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $n;

        return sqrt($variance);
    }

    /**
     * Get summary message
     */
    public static function getSummary($report) {
        $errorCount = count($report['errors']);
        $warningCount = count($report['warnings']);

        if ($errorCount > 0) {
            return [
                'status' => 'error',
                'message' => "$errorCount erreur(s) critique(s) détectée(s)",
                'color' => '#ef4444'
            ];
        } elseif ($warningCount > 5) {
            return [
                'status' => 'warning',
                'message' => "$warningCount avertissement(s) détecté(s)",
                'color' => '#f59e0b'
            ];
        } elseif ($warningCount > 0) {
            return [
                'status' => 'info',
                'message' => "$warningCount avertissement(s) mineur(s)",
                'color' => '#3b82f6'
            ];
        } else {
            return [
                'status' => 'success',
                'message' => 'Aucun problème détecté',
                'color' => '#10b981'
            ];
        }
    }
}
