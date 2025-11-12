<?php
/**
 * Integration Tests - Tenant Isolation
 * Sprint 3: Multi-tenant & RBAC
 *
 * Tests to verify:
 * - Cross-tenant access is blocked
 * - Tenant header validation
 * - Tenant-auth mismatch detection
 * - Audit logging of violations
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../TestHelper.php';

class TenantIsolationTest {
    private $testResults = [];

    public function runAll() {
        echo "🧪 Running Tenant Isolation Tests\n";
        echo str_repeat("=", 60) . "\n\n";

        $this->testMissingTenantHeader();
        $this->testInvalidTenant();
        $this->testInactiveTenant();
        $this->testCrossTenantAccessBlocked();
        $this->testTenantAuthMismatch();
        $this->testTenantOwnershipVerification();
        $this->testAssignmentTargetIsolation();

        $this->printResults();
    }

    /**
     * Test 1: Missing tenant header returns 400
     */
    private function testMissingTenantHeader() {
        echo "Test 1: Missing tenant header...\n";

        try {
            $response = $this->makeRequest('/api/students', 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getValidToken('TENANT_INST_PARIS')
                    // Missing X-Orchestrator-Id header
                ]
            ]);

            $this->assert(
                $response['code'] === 400,
                "Missing tenant header should return 400",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['error']) && $response['body']['error'] === 'missing_tenant_id',
                "Error code should be 'missing_tenant_id'",
                "Got: " . ($response['body']['error'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    /**
     * Test 2: Invalid tenant ID returns 403
     */
    private function testInvalidTenant() {
        echo "Test 2: Invalid tenant ID...\n";

        try {
            $response = $this->makeRequest('/api/students', 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getValidToken('TENANT_INST_PARIS'),
                    'X-Orchestrator-Id' => 'TENANT_INVALID_XXXXX'
                ]
            ]);

            $this->assert(
                $response['code'] === 403,
                "Invalid tenant should return 403",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['error']) && $response['body']['error'] === 'invalid_tenant',
                "Error code should be 'invalid_tenant'",
                "Got: " . ($response['body']['error'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    /**
     * Test 3: Inactive tenant returns 403
     */
    private function testInactiveTenant() {
        echo "Test 3: Inactive tenant access...\n";

        // First, create a suspended tenant for testing
        $testTenantId = 'TENANT_TEST_SUSPENDED';
        $this->createSuspendedTenant($testTenantId);

        try {
            $response = $this->makeRequest('/api/students', 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getValidToken($testTenantId),
                    'X-Orchestrator-Id' => $testTenantId
                ]
            ]);

            $this->assert(
                $response['code'] === 403,
                "Suspended tenant should return 403",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['error']) && $response['body']['error'] === 'tenant_inactive',
                "Error code should be 'tenant_inactive'",
                "Got: " . ($response['body']['error'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestTenant($testTenantId);
        }
    }

    /**
     * Test 4: Cross-tenant data access is blocked
     */
    private function testCrossTenantAccessBlocked() {
        echo "Test 4: Cross-tenant data access blocked...\n";

        // User from PARIS tries to access LYON data
        try {
            $parisToken = $this->getValidToken('TENANT_INST_PARIS');

            // Try to access with LYON tenant header
            $response = $this->makeRequest('/api/students', 'GET', [
                'headers' => [
                    'Authorization' => "Bearer $parisToken",
                    'X-Orchestrator-Id' => 'TENANT_INST_LYON'  // Different tenant!
                ]
            ]);

            $this->assert(
                $response['code'] === 403,
                "Cross-tenant access should return 403",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['error']) && $response['body']['error'] === 'tenant_mismatch',
                "Error should indicate tenant mismatch",
                "Got: " . ($response['body']['error'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    /**
     * Test 5: Tenant-auth mismatch detection
     */
    private function testTenantAuthMismatch() {
        echo "Test 5: Tenant-auth mismatch detection...\n";

        try {
            $parisToken = $this->getValidToken('TENANT_INST_PARIS');

            // Make request with body tenant_id different from JWT tenant
            $response = $this->makeRequest('/api/assignments', 'POST', [
                'headers' => [
                    'Authorization' => "Bearer $parisToken",
                    'X-Orchestrator-Id' => 'TENANT_INST_PARIS',
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'tenant_id' => 'TENANT_INST_LYON',  // Mismatch!
                    'title' => 'Test Assignment',
                    'type' => 'quiz',
                    'theme_id' => 'THEME_TEST',
                    'targets' => []
                ])
            ]);

            $this->assert(
                $response['code'] === 403,
                "Tenant-auth mismatch should return 403",
                "Got: {$response['code']}"
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    /**
     * Test 6: Tenant ownership verification for related resources
     */
    private function testTenantOwnershipVerification() {
        echo "Test 6: Tenant ownership verification...\n";

        // Create test resources in two different tenants
        $parisThemeId = $this->createTestTheme('TENANT_INST_PARIS', 'Paris Theme');
        $lyonToken = $this->getValidToken('TENANT_INST_LYON');

        try {
            // LYON user tries to create assignment with PARIS theme
            $response = $this->makeRequest('/api/assignments', 'POST', [
                'headers' => [
                    'Authorization' => "Bearer $lyonToken",
                    'X-Orchestrator-Id' => 'TENANT_INST_LYON',
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Test Assignment',
                    'type' => 'quiz',
                    'theme_id' => $parisThemeId,  // Cross-tenant reference!
                    'targets' => []
                ])
            ]);

            $this->assert(
                $response['code'] === 403,
                "Cross-tenant resource reference should return 403",
                "Got: {$response['code']}"
            );

            $this->assert(
                strpos($response['body']['message'] ?? '', 'tenant') !== false,
                "Error message should mention tenant",
                "Got: " . ($response['body']['message'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestTheme($parisThemeId);
        }
    }

    /**
     * Test 7: Assignment targets include tenant_id
     */
    private function testAssignmentTargetIsolation() {
        echo "Test 7: Assignment target isolation...\n";

        $parisToken = $this->getValidToken('TENANT_INST_PARIS');
        $testThemeId = $this->createTestTheme('TENANT_INST_PARIS', 'Test Theme');
        $testClassId = $this->createTestClass('TENANT_INST_PARIS', 'Test Class');

        try {
            // Create assignment with targets
            $response = $this->makeRequest('/api/assignments', 'POST', [
                'headers' => [
                    'Authorization' => "Bearer $parisToken",
                    'X-Orchestrator-Id' => 'TENANT_INST_PARIS',
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Test Assignment',
                    'type' => 'quiz',
                    'theme_id' => $testThemeId,
                    'targets' => [
                        ['type' => 'class', 'id' => $testClassId]
                    ]
                ])
            ]);

            $this->assert(
                $response['code'] === 201 || $response['code'] === 200,
                "Assignment creation should succeed",
                "Got: {$response['code']}"
            );

            if ($response['code'] === 200 || $response['code'] === 201) {
                $assignmentId = $response['body']['assignment_id'] ?? null;

                if ($assignmentId) {
                    // Verify assignment_targets have tenant_id
                    $targets = db()->query(
                        'SELECT * FROM assignment_targets WHERE assignment_id = ?',
                        [$assignmentId]
                    );

                    $this->assert(
                        !empty($targets),
                        "Assignment targets should exist",
                        "Got: " . count($targets) . " targets"
                    );

                    foreach ($targets as $target) {
                        $this->assert(
                            isset($target['tenant_id']) && $target['tenant_id'] === 'TENANT_INST_PARIS',
                            "Target should have correct tenant_id",
                            "Got: " . ($target['tenant_id'] ?? 'none')
                        );
                    }

                    // Cleanup
                    $this->cleanupTestAssignment($assignmentId);
                }
            }

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestClass($testClassId);
            $this->cleanupTestTheme($testThemeId);
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function makeRequest($endpoint, $method = 'GET', $options = []) {
        // Implementation would use curl or similar
        // For now, return mock structure
        return [
            'code' => 200,
            'body' => []
        ];
    }

    private function getValidToken($tenantId) {
        // Generate a test JWT token
        require_once __DIR__ . '/../../lib/auth.php';
        return generateTestToken($tenantId, 'admin');
    }

    private function createSuspendedTenant($tenantId) {
        db()->execute(
            'INSERT INTO tenants (id, name, status) VALUES (?, ?, ?)',
            [$tenantId, 'Test Suspended Tenant', 'suspended']
        );
    }

    private function createTestTheme($tenantId, $title) {
        $themeId = 'THEME_TEST_' . uniqid();
        $userId = $this->getTestUserId($tenantId);

        db()->execute(
            'INSERT INTO themes (id, tenant_id, created_by, title, content, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$themeId, $tenantId, $userId, $title, '{}', 'active']
        );

        return $themeId;
    }

    private function createTestClass($tenantId, $name) {
        $classId = 'CLASS_TEST_' . uniqid();
        $promoId = $this->getTestPromoId($tenantId);

        db()->execute(
            'INSERT INTO classes (id, tenant_id, promo_id, name, status) VALUES (?, ?, ?, ?, ?)',
            [$classId, $tenantId, $promoId, $name, 'active']
        );

        return $classId;
    }

    private function getTestUserId($tenantId) {
        $user = db()->queryOne('SELECT id FROM users WHERE tenant_id = ? LIMIT 1', [$tenantId]);
        return $user ? $user['id'] : 'USER_TEST';
    }

    private function getTestPromoId($tenantId) {
        $promo = db()->queryOne('SELECT id FROM promotions WHERE tenant_id = ? LIMIT 1', [$tenantId]);
        return $promo ? $promo['id'] : 'PROMO_TEST';
    }

    private function cleanupTestTenant($tenantId) {
        db()->execute('DELETE FROM tenants WHERE id = ?', [$tenantId]);
    }

    private function cleanupTestTheme($themeId) {
        db()->execute('DELETE FROM themes WHERE id = ?', [$themeId]);
    }

    private function cleanupTestClass($classId) {
        db()->execute('DELETE FROM classes WHERE id = ?', [$classId]);
    }

    private function cleanupTestAssignment($assignmentId) {
        db()->execute('DELETE FROM assignment_targets WHERE assignment_id = ?', [$assignmentId]);
        db()->execute('DELETE FROM assignments WHERE id = ?', [$assignmentId]);
    }

    private function assert($condition, $message, $details = '') {
        if ($condition) {
            echo "  ✅ PASS: $message\n";
            $this->testResults[] = ['status' => 'pass', 'message' => $message];
        } else {
            echo "  ❌ FAIL: $message\n";
            if ($details) {
                echo "     Details: $details\n";
            }
            $this->testResults[] = ['status' => 'fail', 'message' => $message, 'details' => $details];
        }
    }

    private function fail($message) {
        echo "  ❌ ERROR: $message\n";
        $this->testResults[] = ['status' => 'error', 'message' => $message];
    }

    private function printResults() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📊 Test Results Summary\n";
        echo str_repeat("=", 60) . "\n";

        $passed = count(array_filter($this->testResults, fn($r) => $r['status'] === 'pass'));
        $failed = count(array_filter($this->testResults, fn($r) => $r['status'] === 'fail'));
        $errors = count(array_filter($this->testResults, fn($r) => $r['status'] === 'error'));
        $total = count($this->testResults);

        echo "Total: $total | Passed: $passed | Failed: $failed | Errors: $errors\n";

        if ($failed === 0 && $errors === 0) {
            echo "✅ All tests passed!\n";
        } else {
            echo "❌ Some tests failed!\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new TenantIsolationTest();
    $test->runAll();
}
