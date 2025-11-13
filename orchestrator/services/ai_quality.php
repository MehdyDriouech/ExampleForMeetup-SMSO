<?php
/**
 * Sprint 13 - US13-2: AI Quality & Confidence Scoring
 * Evaluates AI-generated content reliability and provides confidence scores
 *
 * @package StudyMate\Services
 */

class AIQualityService {
    /**
     * Analyze theme quality and generate confidence report
     *
     * @param array $theme Theme data with questions
     * @return array Confidence report with scores and risk areas
     */
    public static function analyzeTheme($theme) {
        $questions = $theme['questions'] ?? [];
        $report = [
            'overall_confidence' => 0,
            'questions' => [],
            'risk_areas' => [],
            'recommendations' => []
        ];

        $totalScore = 0;
        $questionCount = count($questions);

        foreach ($questions as $index => $question) {
            $analysis = self::analyzeQuestion($question);
            $report['questions'][] = [
                'question_id' => $question['id'] ?? $index,
                'confidence_score' => $analysis['confidence'],
                'risk_level' => $analysis['risk_level'],
                'issues' => $analysis['issues'],
                'text_preview' => mb_substr($question['question_text'] ?? '', 0, 100)
            ];

            $totalScore += $analysis['confidence'];

            // Collect high-risk questions
            if ($analysis['risk_level'] === 'high') {
                $report['risk_areas'][] = [
                    'question_index' => $index + 1,
                    'reason' => $analysis['primary_issue'],
                    'severity' => 'high'
                ];
            }
        }

        // Calculate overall confidence
        $report['overall_confidence'] = $questionCount > 0 ? round($totalScore / $questionCount, 2) : 0;

        // Generate recommendations
        $report['recommendations'] = self::generateRecommendations($report);

        // Determine if human validation is needed
        $report['requires_validation'] = $report['overall_confidence'] < 0.75 || count($report['risk_areas']) > 0;

        return $report;
    }

    /**
     * Analyze individual question quality
     *
     * @param array $question Question data
     * @return array Analysis with confidence score and issues
     */
    private static function analyzeQuestion($question) {
        $confidence = 1.0;
        $issues = [];
        $penalties = [];

        $questionText = $question['question_text'] ?? '';
        $correctAnswer = $question['correct_answer'] ?? '';
        $distractors = $question['distractors'] ?? [];
        $explanation = $question['explanation'] ?? '';

        // 1. Check question text length
        $questionLength = mb_strlen($questionText);
        if ($questionLength < 10) {
            $issues[] = 'Question trop courte';
            $penalties[] = 0.3;
        } elseif ($questionLength > 500) {
            $issues[] = 'Question trop longue';
            $penalties[] = 0.15;
        }

        // 2. Check for empty fields
        if (empty($questionText)) {
            $issues[] = 'Texte de question manquant';
            $penalties[] = 0.5;
        }
        if (empty($correctAnswer)) {
            $issues[] = 'Réponse correcte manquante';
            $penalties[] = 0.5;
        }
        if (count($distractors) < 2) {
            $issues[] = 'Distracteurs insuffisants (minimum 2)';
            $penalties[] = 0.3;
        }

        // 3. Check answer similarity (too similar distractors)
        $allAnswers = array_merge([$correctAnswer], $distractors);
        $similarityIssues = self::detectSimilarAnswers($allAnswers);
        if ($similarityIssues > 0) {
            $issues[] = "$similarityIssues paires de réponses trop similaires";
            $penalties[] = 0.2 * min($similarityIssues, 2);
        }

        // 4. Check for obvious patterns (alphabetical, numerical sequences)
        if (self::detectObviousPattern($allAnswers)) {
            $issues[] = 'Pattern évident détecté dans les réponses';
            $penalties[] = 0.25;
        }

        // 5. Check explanation quality
        $explanationLength = mb_strlen($explanation);
        if ($explanationLength < 20) {
            $issues[] = 'Explication insuffisante';
            $penalties[] = 0.15;
        } elseif ($explanationLength > 1000) {
            $issues[] = 'Explication trop longue';
            $penalties[] = 0.1;
        }

        // 6. Check for suspicious keywords (might indicate AI uncertainty)
        $uncertaintyKeywords = ['peut-être', 'probablement', 'il semble', 'on pense que', 'généralement'];
        foreach ($uncertaintyKeywords as $keyword) {
            if (stripos($questionText, $keyword) !== false || stripos($explanation, $keyword) !== false) {
                $issues[] = "Incertitude détectée (mot-clé: '$keyword')";
                $penalties[] = 0.2;
                break;
            }
        }

        // 7. Check correct answer is not trivially obvious
        if (self::isAnswerTriviallyObvious($questionText, $correctAnswer, $distractors)) {
            $issues[] = 'Réponse correcte trop évidente';
            $penalties[] = 0.15;
        }

        // 8. Check for incomplete sentences
        if (!self::endsWithProperPunctuation($questionText)) {
            $issues[] = 'Ponctuation de question incorrecte';
            $penalties[] = 0.1;
        }

        // Apply penalties
        foreach ($penalties as $penalty) {
            $confidence -= $penalty;
        }
        $confidence = max(0, min(1, $confidence)); // Clamp between 0 and 1

        // Determine risk level
        $riskLevel = 'low';
        if ($confidence < 0.5) {
            $riskLevel = 'high';
        } elseif ($confidence < 0.75) {
            $riskLevel = 'medium';
        }

        return [
            'confidence' => round($confidence, 2),
            'risk_level' => $riskLevel,
            'issues' => $issues,
            'primary_issue' => !empty($issues) ? $issues[0] : null
        ];
    }

    /**
     * Detect similar answers using Levenshtein distance
     *
     * @param array $answers List of answer texts
     * @return int Number of similar pairs
     */
    private static function detectSimilarAnswers($answers) {
        $similarCount = 0;
        $n = count($answers);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $similarity = self::calculateSimilarity($answers[$i], $answers[$j]);
                if ($similarity > 0.8) { // 80% similar
                    $similarCount++;
                }
            }
        }

        return $similarCount;
    }

    /**
     * Calculate similarity between two strings (0 = different, 1 = identical)
     *
     * @param string $str1
     * @param string $str2
     * @return float Similarity score
     */
    private static function calculateSimilarity($str1, $str2) {
        $str1 = mb_strtolower(trim($str1));
        $str2 = mb_strtolower(trim($str2));

        if ($str1 === $str2) return 1.0;
        if (empty($str1) || empty($str2)) return 0.0;

        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        $distance = levenshtein($str1, $str2);

        return 1 - ($distance / $maxLen);
    }

    /**
     * Detect obvious patterns in answers (alphabetical, numerical)
     *
     * @param array $answers
     * @return bool
     */
    private static function detectObviousPattern($answers) {
        // Check if answers are alphabetically sorted
        $sorted = $answers;
        sort($sorted, SORT_STRING);
        if ($sorted === $answers) {
            return true;
        }

        // Check if answers are numerical sequence
        $numbers = array_filter($answers, 'is_numeric');
        if (count($numbers) >= 3) {
            $numbers = array_map('floatval', $numbers);
            sort($numbers);
            $isSequence = true;
            for ($i = 1; $i < count($numbers); $i++) {
                if ($numbers[$i] - $numbers[$i - 1] !== 1) {
                    $isSequence = false;
                    break;
                }
            }
            if ($isSequence) return true;
        }

        return false;
    }

    /**
     * Check if correct answer is trivially obvious
     *
     * @param string $question
     * @param string $correct
     * @param array $distractors
     * @return bool
     */
    private static function isAnswerTriviallyObvious($question, $correct, $distractors) {
        // Check if correct answer is much longer than distractors
        $correctLen = mb_strlen($correct);
        $avgDistractorLen = array_sum(array_map('mb_strlen', $distractors)) / max(count($distractors), 1);

        if ($correctLen > $avgDistractorLen * 2) {
            return true;
        }

        // Check if correct answer contains question keywords
        $questionWords = explode(' ', mb_strtolower($question));
        $correctWords = explode(' ', mb_strtolower($correct));
        $overlap = count(array_intersect($questionWords, $correctWords));

        if ($overlap > 5) {
            return true;
        }

        return false;
    }

    /**
     * Check if text ends with proper punctuation
     *
     * @param string $text
     * @return bool
     */
    private static function endsWithProperPunctuation($text) {
        $text = trim($text);
        $lastChar = mb_substr($text, -1);
        return in_array($lastChar, ['?', '.', '!', '»', '"']);
    }

    /**
     * Generate actionable recommendations based on analysis
     *
     * @param array $report Full report
     * @return array Recommendations
     */
    private static function generateRecommendations($report) {
        $recommendations = [];

        // Overall confidence
        if ($report['overall_confidence'] < 0.5) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => 'Révision complète nécessaire : confiance globale très faible',
                'action' => 'Vérifier chaque question manuellement'
            ];
        } elseif ($report['overall_confidence'] < 0.75) {
            $recommendations[] = [
                'priority' => 'medium',
                'message' => 'Révision partielle recommandée',
                'action' => 'Se concentrer sur les questions signalées'
            ];
        }

        // Risk areas
        $highRiskCount = count(array_filter($report['questions'], fn($q) => $q['risk_level'] === 'high'));
        if ($highRiskCount > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'message' => "$highRiskCount question(s) à haut risque détectée(s)",
                'action' => 'Vérifier en priorité les questions marquées'
            ];
        }

        // Common issues
        $commonIssues = [];
        foreach ($report['questions'] as $q) {
            foreach ($q['issues'] as $issue) {
                $commonIssues[$issue] = ($commonIssues[$issue] ?? 0) + 1;
            }
        }

        arsort($commonIssues);
        $topIssue = array_key_first($commonIssues);
        if ($topIssue && $commonIssues[$topIssue] >= 3) {
            $recommendations[] = [
                'priority' => 'medium',
                'message' => "Problème récurrent : $topIssue ({$commonIssues[$topIssue]} occurrences)",
                'action' => 'Corriger ce type d\'erreur dans toutes les questions affectées'
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'priority' => 'low',
                'message' => 'Qualité satisfaisante',
                'action' => 'Validation rapide conseillée avant publication'
            ];
        }

        return $recommendations;
    }

    /**
     * Get quality badge based on confidence score
     *
     * @param float $confidence Score between 0 and 1
     * @return array Badge info
     */
    public static function getQualityBadge($confidence) {
        if ($confidence >= 0.9) {
            return ['label' => 'Excellente', 'color' => '#10b981', 'icon' => '⭐'];
        } elseif ($confidence >= 0.75) {
            return ['label' => 'Bonne', 'color' => '#3b82f6', 'icon' => '✓'];
        } elseif ($confidence >= 0.5) {
            return ['label' => 'Acceptable', 'color' => '#f59e0b', 'icon' => '⚠'];
        } else {
            return ['label' => 'À revoir', 'color' => '#ef4444', 'icon' => '✗'];
        }
    }
}
