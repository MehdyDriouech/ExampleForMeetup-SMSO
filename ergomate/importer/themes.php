<?php
/**
 * Sprint 12 - Pedagogical Library
 * Ergo-Mate Integration: Theme Importer (E12-INTEGRATION)
 *
 * Webhook endpoint pour recevoir les thèmes du catalogue Orchestrator
 * et les importer dans Ergo-Mate pour utilisation par les élèves.
 *
 * Endpoint: POST /ergo/api/v1/themes/push
 *
 * @version 1.0.0
 * @date 2025-11-13
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Tenant-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Vérifier la méthode HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'method_not_allowed',
        'message' => 'Only POST requests are accepted'
    ]);
    exit;
}

// Charger les dépendances (à adapter selon l'architecture Ergo-Mate)
// require_once __DIR__ . '/../config/db.php';
// require_once __DIR__ . '/../lib/logger.php';

/**
 * Simuler une connexion DB pour l'exemple
 * En production, utiliser la vraie connexion Ergo-Mate
 */
function getErgoMateDB() {
    // TODO: Implémenter la vraie connexion DB Ergo-Mate
    try {
        $dsn = getenv('ERGOMATE_DB_DSN') ?: 'mysql:host=localhost;dbname=ergomate';
        $username = getenv('ERGOMATE_DB_USER') ?: 'root';
        $password = getenv('ERGOMATE_DB_PASS') ?: '';

        $db = new PDO($dsn, $username, $password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
        logError("Database connection failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Logger simplifié
 */
function logInfo($message, $context = []) {
    error_log("[INFO] $message " . json_encode($context));
}

function logError($message, $context = []) {
    error_log("[ERROR] $message " . json_encode($context));
}

// ============================================================================
// Traitement de la requête
// ============================================================================

try {
    // Récupérer le payload
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_payload',
            'message' => 'Empty request body'
        ]);
        exit;
    }

    // Validation des champs requis
    $requiredFields = ['tenant_id', 'theme_id', 'class_ids', 'theme', 'metadata'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode([
                'error' => 'missing_field',
                'message' => "Missing required field: {$field}"
            ]);
            exit;
        }
    }

    $tenantId = $input['tenant_id'];
    $themeId = $input['theme_id'];
    $classIds = $input['class_ids'];
    $themeData = $input['theme'];
    $metadata = $input['metadata'];

    // Vérifier le tenant_id dans le header
    $headerTenantId = $_SERVER['HTTP_X_TENANT_ID'] ?? null;
    if ($headerTenantId && $headerTenantId !== $tenantId) {
        http_response_code(403);
        echo json_encode([
            'error' => 'tenant_mismatch',
            'message' => 'Tenant ID in header does not match payload'
        ]);
        exit;
    }

    logInfo("Importing theme from catalog", [
        'tenant_id' => $tenantId,
        'theme_id' => $themeId,
        'class_count' => count($classIds)
    ]);

    // Connexion DB
    $db = getErgoMateDB();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $db->beginTransaction();

    // ========================================================================
    // 1. Vérifier si le thème existe déjà (update vs insert)
    // ========================================================================

    $stmt = $db->prepare(
        "SELECT id FROM themes WHERE catalog_theme_id = ? AND tenant_id = ?"
    );
    $stmt->execute([$themeId, $tenantId]);
    $existingTheme = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingTheme) {
        // Update existing theme
        $ergoThemeId = $existingTheme['id'];

        $stmt = $db->prepare(
            "UPDATE themes
             SET title = ?,
                 description = ?,
                 content = ?,
                 difficulty = ?,
                 metadata = ?,
                 version = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        $stmt->execute([
            $metadata['title'],
            $metadata['description'],
            json_encode($themeData),
            $themeData['difficulty'] ?? 'intermediate',
            json_encode($metadata),
            $metadata['version'] ?? 'v1.0',
            $ergoThemeId
        ]);

        logInfo("Theme updated in Ergo-Mate", ['ergo_theme_id' => $ergoThemeId]);

    } else {
        // Insert new theme
        $ergoThemeId = 'ergo_theme_' . bin2hex(random_bytes(16));

        $stmt = $db->prepare(
            "INSERT INTO themes
             (id, catalog_theme_id, tenant_id, title, description, content, difficulty, metadata, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $ergoThemeId,
            $themeId,
            $tenantId,
            $metadata['title'],
            $metadata['description'],
            json_encode($themeData),
            $themeData['difficulty'] ?? 'intermediate',
            json_encode($metadata),
            $metadata['version'] ?? 'v1.0'
        ]);

        logInfo("Theme created in Ergo-Mate", ['ergo_theme_id' => $ergoThemeId]);
    }

    // ========================================================================
    // 2. Assigner le thème aux classes
    // ========================================================================

    // Supprimer les anciennes affectations pour ce thème
    $stmt = $db->prepare(
        "DELETE FROM theme_assignments WHERE theme_id = ? AND tenant_id = ?"
    );
    $stmt->execute([$ergoThemeId, $tenantId]);

    // Créer les nouvelles affectations
    $stmt = $db->prepare(
        "INSERT INTO theme_assignments
         (id, theme_id, class_id, tenant_id, assigned_at, status)
         VALUES (?, ?, ?, ?, NOW(), 'active')"
    );

    foreach ($classIds as $classId) {
        $assignmentId = 'assign_' . bin2hex(random_bytes(16));
        $stmt->execute([$assignmentId, $ergoThemeId, $classId, $tenantId]);
    }

    logInfo("Theme assigned to classes", [
        'ergo_theme_id' => $ergoThemeId,
        'class_count' => count($classIds)
    ]);

    // ========================================================================
    // 3. Créer les questions/flashcards/fiches dans la DB Ergo-Mate
    // ========================================================================

    // Questions
    if (isset($themeData['questions']) && is_array($themeData['questions'])) {
        $stmt = $db->prepare(
            "INSERT INTO theme_questions
             (id, theme_id, question_data, created_at)
             VALUES (?, ?, ?, NOW())"
        );

        foreach ($themeData['questions'] as $question) {
            $questionId = 'q_' . bin2hex(random_bytes(16));
            $stmt->execute([
                $questionId,
                $ergoThemeId,
                json_encode($question)
            ]);
        }

        logInfo("Questions imported", ['count' => count($themeData['questions'])]);
    }

    // Flashcards
    if (isset($themeData['flashcards']) && is_array($themeData['flashcards'])) {
        $stmt = $db->prepare(
            "INSERT INTO theme_flashcards
             (id, theme_id, flashcard_data, created_at)
             VALUES (?, ?, ?, NOW())"
        );

        foreach ($themeData['flashcards'] as $flashcard) {
            $flashcardId = 'fc_' . bin2hex(random_bytes(16));
            $stmt->execute([
                $flashcardId,
                $ergoThemeId,
                json_encode($flashcard)
            ]);
        }

        logInfo("Flashcards imported", ['count' => count($themeData['flashcards'])]);
    }

    // Fiche
    if (isset($themeData['fiche'])) {
        $stmt = $db->prepare(
            "INSERT INTO theme_fiches
             (id, theme_id, fiche_data, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
             fiche_data = VALUES(fiche_data)"
        );

        $ficheId = 'fiche_' . bin2hex(random_bytes(16));
        $stmt->execute([
            $ficheId,
            $ergoThemeId,
            json_encode($themeData['fiche'])
        ]);

        logInfo("Fiche imported");
    }

    // Commit transaction
    $db->commit();

    // ========================================================================
    // Réponse succès
    // ========================================================================

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Theme successfully imported to Ergo-Mate',
        'data' => [
            'ergo_theme_id' => $ergoThemeId,
            'catalog_theme_id' => $themeId,
            'classes_assigned' => count($classIds),
            'questions_imported' => isset($themeData['questions']) ? count($themeData['questions']) : 0,
            'flashcards_imported' => isset($themeData['flashcards']) ? count($themeData['flashcards']) : 0,
            'fiche_imported' => isset($themeData['fiche']) ? true : false
        ]
    ]);

} catch (Exception $e) {
    // Rollback en cas d'erreur
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logError("Theme import failed", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'import_failed',
        'message' => 'Failed to import theme to Ergo-Mate',
        'details' => (getenv('DEBUG_MODE') === 'true') ? $e->getMessage() : null
    ]);
}
