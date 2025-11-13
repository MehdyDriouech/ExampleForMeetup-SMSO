<?php
/**
 * Service d'extraction de contenu
 *
 * Supporte :
 * - PDF (via pdftotext ou Tesseract OCR)
 * - Audio (via Whisper API ou autre service de transcription)
 * - Vidéo (extraction audio puis transcription)
 */

class ContentExtractor {
    private $db;
    private $uploadsDir;

    public function __construct() {
        $this->db = db();
        $this->uploadsDir = dirname(__DIR__) . '/uploads';
    }

    /**
     * Extraire le texte depuis un fichier PDF
     *
     * @param string $filepath Chemin du fichier PDF
     * @param string $userId ID de l'utilisateur
     * @param string $tenantId ID du tenant
     * @return array Résultat de l'extraction
     */
    public function extractFromPDF($filepath, $userId, $tenantId) {
        if (!file_exists($filepath)) {
            throw new Exception('File not found: ' . $filepath);
        }

        // Créer un enregistrement d'extraction
        $extractionId = generateId('extract');
        $filename = basename($filepath);
        $filesize = filesize($filepath);

        $this->db->execute(
            'INSERT INTO ai_content_extractions
             (id, tenant_id, user_id, source_type, source_path, source_filename, source_size_bytes, extraction_status, created_at)
             VALUES (:id, :tenant_id, :user_id, :source_type, :source_path, :source_filename, :source_size_bytes, :extraction_status, NOW())',
            [
                'id' => $extractionId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'source_type' => 'pdf',
                'source_path' => $filepath,
                'source_filename' => $filename,
                'source_size_bytes' => $filesize,
                'extraction_status' => 'processing'
            ]
        );

        $startTime = microtime(true);

        try {
            // Essayer d'abord pdftotext (plus rapide et précis)
            $text = $this->extractPDFWithPdfToText($filepath);
            $method = 'pdftotext';

            // Si pdftotext échoue ou retourne peu de texte, essayer OCR
            if (empty($text) || strlen($text) < 100) {
                logInfo('PDFtotext failed or returned little text, trying OCR', [
                    'extraction_id' => $extractionId,
                    'text_length' => strlen($text)
                ]);

                $text = $this->extractPDFWithOCR($filepath);
                $method = 'tesseract_ocr';
            }

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Compter les pages
            $pageCount = $this->getPDFPageCount($filepath);

            // Mettre à jour l'enregistrement
            $this->db->execute(
                'UPDATE ai_content_extractions
                 SET extracted_text = :extracted_text,
                     extraction_status = :extraction_status,
                     extraction_method = :extraction_method,
                     processing_time_ms = :processing_time_ms,
                     metadata = :metadata,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $extractionId,
                    'extracted_text' => $text,
                    'extraction_status' => 'completed',
                    'extraction_method' => $method,
                    'processing_time_ms' => (int)$processingTime,
                    'metadata' => json_encode([
                        'page_count' => $pageCount,
                        'character_count' => strlen($text),
                        'word_count' => str_word_count($text)
                    ])
                ]
            );

            logInfo('PDF extraction completed', [
                'extraction_id' => $extractionId,
                'method' => $method,
                'page_count' => $pageCount,
                'text_length' => strlen($text),
                'processing_time_ms' => (int)$processingTime
            ]);

            return [
                'extraction_id' => $extractionId,
                'text' => $text,
                'method' => $method,
                'metadata' => [
                    'page_count' => $pageCount,
                    'character_count' => strlen($text),
                    'word_count' => str_word_count($text)
                ],
                'processing_time_ms' => (int)$processingTime
            ];

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Mettre à jour avec l'erreur
            $this->db->execute(
                'UPDATE ai_content_extractions
                 SET extraction_status = :extraction_status,
                     error_message = :error_message,
                     processing_time_ms = :processing_time_ms,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $extractionId,
                    'extraction_status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'processing_time_ms' => (int)$processingTime
                ]
            );

            logError('PDF extraction failed', [
                'extraction_id' => $extractionId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Extraire texte avec pdftotext (utilitaire système)
     */
    private function extractPDFWithPdfToText($filepath) {
        // Vérifier si pdftotext est disponible
        $command = 'which pdftotext';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('pdftotext not available on system');
        }

        // Extraire le texte
        $outputFile = $filepath . '.txt';
        $command = sprintf(
            'pdftotext -enc UTF-8 %s %s 2>&1',
            escapeshellarg($filepath),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('pdftotext failed: ' . implode("\n", $output));
        }

        if (!file_exists($outputFile)) {
            throw new Exception('pdftotext output file not created');
        }

        $text = file_get_contents($outputFile);
        unlink($outputFile); // Nettoyer

        return $text;
    }

    /**
     * Extraire texte avec OCR (Tesseract)
     */
    private function extractPDFWithOCR($filepath) {
        // Vérifier si tesseract et pdftoppm sont disponibles
        $command = 'which tesseract';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('Tesseract OCR not available on system');
        }

        $command = 'which pdftoppm';
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('pdftoppm not available on system');
        }

        // Créer un répertoire temporaire
        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            // Convertir PDF en images
            $imagePrefix = $tempDir . '/page';
            $command = sprintf(
                'pdftoppm -png %s %s 2>&1',
                escapeshellarg($filepath),
                escapeshellarg($imagePrefix)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception('pdftoppm failed: ' . implode("\n", $output));
            }

            // Lister les images créées
            $images = glob($tempDir . '/page-*.png');

            if (empty($images)) {
                throw new Exception('No images created from PDF');
            }

            // OCR sur chaque image
            $allText = [];
            foreach ($images as $imagePath) {
                $outputBase = $imagePath . '.txt';
                $command = sprintf(
                    'tesseract %s %s -l fra 2>&1',
                    escapeshellarg($imagePath),
                    escapeshellarg(substr($outputBase, 0, -4)) // Tesseract ajoute .txt automatiquement
                );

                exec($command, $output, $returnCode);

                if ($returnCode === 0 && file_exists($outputBase)) {
                    $pageText = file_get_contents($outputBase);
                    $allText[] = $pageText;
                }
            }

            return implode("\n\n", $allText);

        } finally {
            // Nettoyer le répertoire temporaire
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Obtenir le nombre de pages d'un PDF
     */
    private function getPDFPageCount($filepath) {
        $command = sprintf(
            'pdfinfo %s 2>&1 | grep Pages | awk \'{print $2}\'',
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            return (int)$output[0];
        }

        // Fallback: essayer avec pdftotext
        $command = sprintf(
            'pdftotext %s - 2>&1 | grep -c "\\f"',
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0 ? (int)($output[0] ?? 1) : 1;
    }

    /**
     * Extraire le texte depuis un fichier audio (via Whisper API ou autre)
     *
     * @param string $filepath Chemin du fichier audio
     * @param string $userId ID de l'utilisateur
     * @param string $tenantId ID du tenant
     * @param string|null $whisperApiKey Clé API Whisper (OpenAI)
     * @return array Résultat de l'extraction
     */
    public function extractFromAudio($filepath, $userId, $tenantId, $whisperApiKey = null) {
        if (!file_exists($filepath)) {
            throw new Exception('File not found: ' . $filepath);
        }

        // Créer un enregistrement d'extraction
        $extractionId = generateId('extract');
        $filename = basename($filepath);
        $filesize = filesize($filepath);

        $this->db->execute(
            'INSERT INTO ai_content_extractions
             (id, tenant_id, user_id, source_type, source_path, source_filename, source_size_bytes, extraction_status, created_at)
             VALUES (:id, :tenant_id, :user_id, :source_type, :source_path, :source_filename, :source_size_bytes, :extraction_status, NOW())',
            [
                'id' => $extractionId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'source_type' => 'audio',
                'source_path' => $filepath,
                'source_filename' => $filename,
                'source_size_bytes' => $filesize,
                'extraction_status' => 'processing'
            ]
        );

        $startTime = microtime(true);

        try {
            // Utiliser Whisper API (OpenAI)
            if ($whisperApiKey) {
                $text = $this->transcribeWithWhisper($filepath, $whisperApiKey);
                $method = 'whisper_api';
            } else {
                throw new Exception('No audio transcription service configured. Please provide Whisper API key.');
            }

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Obtenir la durée de l'audio
            $duration = $this->getAudioDuration($filepath);

            // Mettre à jour l'enregistrement
            $this->db->execute(
                'UPDATE ai_content_extractions
                 SET extracted_text = :extracted_text,
                     extraction_status = :extraction_status,
                     extraction_method = :extraction_method,
                     processing_time_ms = :processing_time_ms,
                     metadata = :metadata,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $extractionId,
                    'extracted_text' => $text,
                    'extraction_status' => 'completed',
                    'extraction_method' => $method,
                    'processing_time_ms' => (int)$processingTime,
                    'metadata' => json_encode([
                        'duration_seconds' => $duration,
                        'character_count' => strlen($text),
                        'word_count' => str_word_count($text)
                    ])
                ]
            );

            logInfo('Audio extraction completed', [
                'extraction_id' => $extractionId,
                'method' => $method,
                'duration_seconds' => $duration,
                'text_length' => strlen($text),
                'processing_time_ms' => (int)$processingTime
            ]);

            return [
                'extraction_id' => $extractionId,
                'text' => $text,
                'method' => $method,
                'metadata' => [
                    'duration_seconds' => $duration,
                    'character_count' => strlen($text),
                    'word_count' => str_word_count($text)
                ],
                'processing_time_ms' => (int)$processingTime
            ];

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            $this->db->execute(
                'UPDATE ai_content_extractions
                 SET extraction_status = :extraction_status,
                     error_message = :error_message,
                     processing_time_ms = :processing_time_ms,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $extractionId,
                    'extraction_status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'processing_time_ms' => (int)$processingTime
                ]
            );

            logError('Audio extraction failed', [
                'extraction_id' => $extractionId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Transcrire audio avec Whisper API (OpenAI)
     */
    private function transcribeWithWhisper($filepath, $apiKey) {
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey
        ]);

        $cFile = new CURLFile($filepath);

        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => $cFile,
            'model' => 'whisper-1',
            'language' => 'fr'
        ]);

        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes max

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Whisper API error: HTTP ' . $httpCode . ' - ' . $response);
        }

        $data = json_decode($response, true);

        if (!isset($data['text'])) {
            throw new Exception('Invalid Whisper API response: ' . $response);
        }

        return $data['text'];
    }

    /**
     * Obtenir la durée d'un fichier audio
     */
    private function getAudioDuration($filepath) {
        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            return (int)floatval($output[0]);
        }

        return 0;
    }

    /**
     * Supprimer récursivement un répertoire
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
