<?php
/**
 * API Configuration Endpoint
 * Expose la configuration publique pour le frontend
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Retourner la configuration publique
echo json_encode([
    'success' => true,
    'data' => getPublicConfig()
], JSON_PRETTY_PRINT);
