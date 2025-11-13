<?php
/**
 * Sprint 13 - US13-6: QTI 2.2 Export Converter
 * Converts StudyMate themes to IMS QTI 2.2 format for LMS integration
 *
 * Supports:
 * - Moodle
 * - Canvas
 * - Blackboard
 * - Generic QTI 2.2 compliant systems
 *
 * @package StudyMate\Services\Converters
 */

class QTIConverter {
    private $version = '2.2';
    private $xmlns = 'http://www.imsglobal.org/xsd/imsqti_v2p2';
    private $errors = [];

    /**
     * Convert theme to QTI 2.2 format
     *
     * @param array $theme Theme data with questions
     * @param array $options Conversion options
     * @return string QTI XML content
     */
    public function convert($theme, $options = []) {
        $this->errors = [];

        $options = array_merge([
            'include_explanations' => true,
            'shuffle_answers' => true,
            'target_lms' => 'generic', // generic, moodle, canvas, blackboard
            'item_prefix' => 'item_'
        ], $options);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Create assessment root
        $assessment = $this->createAssessment($xml, $theme);
        $xml->appendChild($assessment);

        // Add questions
        $questions = $theme['questions'] ?? [];
        foreach ($questions as $index => $question) {
            $item = $this->createQuestionItem($xml, $question, $index, $options);
            if ($item) {
                $assessment->appendChild($item);
            }
        }

        return $xml->saveXML();
    }

    /**
     * Create assessment root element
     */
    private function createAssessment($xml, $theme) {
        $assessment = $xml->createElementNS($this->xmlns, 'assessmentTest');
        $assessment->setAttribute('identifier', $this->sanitizeIdentifier($theme['title'] ?? 'assessment'));
        $assessment->setAttribute('title', $theme['title'] ?? 'StudyMate Quiz');

        // Add metadata
        $metadata = $xml->createElement('metadata');
        $assessment->appendChild($metadata);

        // Add test part
        $testPart = $xml->createElement('testPart');
        $testPart->setAttribute('identifier', 'main');
        $testPart->setAttribute('navigationMode', 'linear');
        $testPart->setAttribute('submissionMode', 'individual');
        $assessment->appendChild($testPart);

        // Add assessment section
        $section = $xml->createElement('assessmentSection');
        $section->setAttribute('identifier', 'section1');
        $section->setAttribute('title', 'Questions');
        $section->setAttribute('visible', 'true');
        $testPart->appendChild($section);

        return $section;
    }

    /**
     * Create question item element
     */
    private function createQuestionItem($xml, $question, $index, $options) {
        $itemId = $options['item_prefix'] . ($index + 1);

        // Validate question
        if (!$this->validateQuestion($question, $index)) {
            return null;
        }

        $item = $xml->createElement('assessmentItem');
        $item->setAttribute('identifier', $itemId);
        $item->setAttribute('title', 'Question ' . ($index + 1));
        $item->setAttribute('adaptive', 'false');
        $item->setAttribute('timeDependent', 'false');

        // Response declaration (correct answer)
        $responseDecl = $this->createResponseDeclaration($xml, $question);
        $item->appendChild($responseDecl);

        // Outcome declaration (score)
        $outcomeDecl = $this->createOutcomeDeclaration($xml);
        $item->appendChild($outcomeDecl);

        // Item body (question text and choices)
        $itemBody = $this->createItemBody($xml, $question, $options);
        $item->appendChild($itemBody);

        // Response processing (scoring)
        $responseProcessing = $this->createResponseProcessing($xml);
        $item->appendChild($responseProcessing);

        // Modal feedback (explanation)
        if ($options['include_explanations'] && !empty($question['explanation'])) {
            $feedback = $this->createModalFeedback($xml, $question['explanation']);
            $item->appendChild($feedback);
        }

        return $item;
    }

    /**
     * Create response declaration
     */
    private function createResponseDeclaration($xml, $question) {
        $responseDecl = $xml->createElement('responseDeclaration');
        $responseDecl->setAttribute('identifier', 'RESPONSE');
        $responseDecl->setAttribute('cardinality', 'single');
        $responseDecl->setAttribute('baseType', 'identifier');

        // Correct response
        $correctResponse = $xml->createElement('correctResponse');
        $value = $xml->createElement('value', 'choice_0'); // Correct answer is always first (before shuffle)
        $correctResponse->appendChild($value);
        $responseDecl->appendChild($correctResponse);

        return $responseDecl;
    }

    /**
     * Create outcome declaration
     */
    private function createOutcomeDeclaration($xml) {
        $outcomeDecl = $xml->createElement('outcomeDeclaration');
        $outcomeDecl->setAttribute('identifier', 'SCORE');
        $outcomeDecl->setAttribute('cardinality', 'single');
        $outcomeDecl->setAttribute('baseType', 'float');

        $defaultValue = $xml->createElement('defaultValue');
        $value = $xml->createElement('value', '0');
        $defaultValue->appendChild($value);
        $outcomeDecl->appendChild($defaultValue);

        return $outcomeDecl;
    }

    /**
     * Create item body with question and choices
     */
    private function createItemBody($xml, $question, $options) {
        $itemBody = $xml->createElement('itemBody');

        // Question text
        $prompt = $xml->createElement('p');
        $prompt->appendChild($xml->createTextNode($question['question_text'] ?? ''));
        $itemBody->appendChild($prompt);

        // Choice interaction
        $choiceInteraction = $xml->createElement('choiceInteraction');
        $choiceInteraction->setAttribute('responseIdentifier', 'RESPONSE');
        $choiceInteraction->setAttribute('shuffle', $options['shuffle_answers'] ? 'true' : 'false');
        $choiceInteraction->setAttribute('maxChoices', '1');

        // Build choices array (correct answer + distractors)
        $allChoices = array_merge(
            [$question['correct_answer'] ?? ''],
            $question['distractors'] ?? []
        );

        // Remove empty choices
        $allChoices = array_filter($allChoices, fn($c) => !empty(trim($c)));

        // Create simple choice elements
        foreach ($allChoices as $index => $choiceText) {
            $choice = $xml->createElement('simpleChoice');
            $choice->setAttribute('identifier', 'choice_' . $index);
            $choice->appendChild($xml->createTextNode($choiceText));
            $choiceInteraction->appendChild($choice);
        }

        $itemBody->appendChild($choiceInteraction);

        return $itemBody;
    }

    /**
     * Create response processing (scoring logic)
     */
    private function createResponseProcessing($xml) {
        $responseProcessing = $xml->createElement('responseProcessing');
        $responseProcessing->setAttribute('template', 'http://www.imsglobal.org/question/qti_v2p2/rptemplates/match_correct');

        return $responseProcessing;
    }

    /**
     * Create modal feedback (explanation)
     */
    private function createModalFeedback($xml, $explanation) {
        $feedback = $xml->createElement('modalFeedback');
        $feedback->setAttribute('identifier', 'feedback');
        $feedback->setAttribute('outcomeIdentifier', 'SCORE');
        $feedback->setAttribute('showHide', 'show');

        $p = $xml->createElement('p');
        $p->appendChild($xml->createTextNode($explanation));
        $feedback->appendChild($p);

        return $feedback;
    }

    /**
     * Validate question structure
     */
    private function validateQuestion($question, $index) {
        $errors = [];

        if (empty($question['question_text'])) {
            $errors[] = "Question " . ($index + 1) . ": Missing question text";
        }

        if (empty($question['correct_answer'])) {
            $errors[] = "Question " . ($index + 1) . ": Missing correct answer";
        }

        $distractors = $question['distractors'] ?? [];
        if (count($distractors) < 1) {
            $errors[] = "Question " . ($index + 1) . ": At least 1 distractor required";
        }

        if (!empty($errors)) {
            $this->errors = array_merge($this->errors, $errors);
            return false;
        }

        return true;
    }

    /**
     * Sanitize identifier for QTI compliance
     */
    private function sanitizeIdentifier($str) {
        // QTI identifiers must start with letter, contain only alphanumeric and underscore
        $str = preg_replace('/[^a-zA-Z0-9_]/', '_', $str);
        $str = preg_replace('/^[^a-zA-Z]+/', '', $str);
        $str = substr($str, 0, 50); // Max length

        return $str ?: 'assessment_' . uniqid();
    }

    /**
     * Get conversion errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Validate QTI XML
     */
    public function validate($xml) {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $errors = libxml_get_errors();
        libxml_clear_errors();

        return [
            'valid' => empty($errors),
            'errors' => array_map(fn($e) => trim($e->message), $errors)
        ];
    }

    /**
     * Export as ZIP package (for Moodle import)
     */
    public function exportAsPackage($theme, $outputPath, $options = []) {
        // Convert to QTI
        $qtiXml = $this->convert($theme, $options);

        if (!empty($this->errors)) {
            return [
                'success' => false,
                'errors' => $this->errors
            ];
        }

        // Create ZIP
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'success' => false,
                'errors' => ['Failed to create ZIP archive']
            ];
        }

        // Add QTI XML
        $zip->addFromString('assessment.xml', $qtiXml);

        // Add manifest (imsmanifest.xml)
        $manifest = $this->createManifest($theme);
        $zip->addFromString('imsmanifest.xml', $manifest);

        $zip->close();

        return [
            'success' => true,
            'path' => $outputPath,
            'size' => filesize($outputPath)
        ];
    }

    /**
     * Create IMS manifest for package
     */
    private function createManifest($theme) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $manifest = $xml->createElementNS('http://www.imsglobal.org/xsd/imscp_v1p1', 'manifest');
        $manifest->setAttribute('identifier', 'MANIFEST-' . uniqid());
        $xml->appendChild($manifest);

        // Metadata
        $metadata = $xml->createElement('metadata');
        $schema = $xml->createElement('schema', 'IMS QTI');
        $schemaversion = $xml->createElement('schemaversion', $this->version);
        $metadata->appendChild($schema);
        $metadata->appendChild($schemaversion);
        $manifest->appendChild($metadata);

        // Resources
        $resources = $xml->createElement('resources');
        $resource = $xml->createElement('resource');
        $resource->setAttribute('identifier', 'assessment');
        $resource->setAttribute('type', 'imsqti_assessment_xmlv2p2');
        $resource->setAttribute('href', 'assessment.xml');

        $file = $xml->createElement('file');
        $file->setAttribute('href', 'assessment.xml');
        $resource->appendChild($file);

        $resources->appendChild($resource);
        $manifest->appendChild($resources);

        return $xml->saveXML();
    }

    /**
     * Get supported LMS platforms
     */
    public static function getSupportedPlatforms() {
        return [
            'generic' => 'QTI 2.2 Standard',
            'moodle' => 'Moodle',
            'canvas' => 'Canvas LMS',
            'blackboard' => 'Blackboard Learn'
        ];
    }
}
