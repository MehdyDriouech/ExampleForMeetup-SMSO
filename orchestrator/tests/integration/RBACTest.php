<?php
/**
 * Integration Tests - RBAC (Role-Based Access Control)
 * Sprint 3: Multi-tenant & RBAC
 *
 * Tests to verify:
 * - Role permissions are enforced
 * - Teachers can only access own resources
 * - Inspectors have read-only access
 * - Direction has aggregated view access
 * - Admin has full access within tenant
 */

require_once __DIR__ . '/../../.env.php';
require_once __DIR__ . '/../TestHelper.php';

class RBACTest {
    private $testResults = [];
    private $tenantId = 'TENANT_INST_PARIS';

    public function runAll() {
        echo "🔐 Running RBAC Permission Tests\n";
        echo str_repeat("=", 60) . "\n\n";

        $this->testTeacherCreateAssignment();
        $this->testTeacherUpdateOwnAssignment();
        $this->testTeacherCannotUpdateOthersAssignment();
        $this->testInspectorReadOnlyAccess();
        $this->testInspectorCannotCreate();
        $this->testDirectionAggregatedAccess();
        $this->testAdminFullAccess();
        $this->testOwnershipFiltering();

        $this->printResults();
    }

    /**
     * Test 1: Teacher can create assignments
     */
    private function testTeacherCreateAssignment() {
        echo "Test 1: Teacher can create assignments...\n";

        $teacherToken = $this->getTokenForRole('teacher');
        $themeId = $this->createTestTheme($this->tenantId);

        try {
            $response = $this->makeRequest('/api/assignments', 'POST', [
                'headers' => [
                    'Authorization' => "Bearer $teacherToken",
                    'X-Orchestrator-Id' => $this->tenantId,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Test Assignment',
                    'type' => 'quiz',
                    'theme_id' => $themeId,
                    'targets' => []
                ])
            ]);

            $this->assert(
                $response['code'] === 200 || $response['code'] === 201,
                "Teacher should be able to create assignment",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['assignment_id']),
                "Response should contain assignment_id",
                "Got: " . json_encode($response['body'])
            );

            if (isset($response['body']['assignment_id'])) {
                $this->cleanupTestAssignment($response['body']['assignment_id']);
            }

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestTheme($themeId);
        }
    }

    /**
     * Test 2: Teacher can update own assignments
     */
    private function testTeacherUpdateOwnAssignment() {
        echo "Test 2: Teacher can update own assignments...\n";

        $teacherToken = $this->getTokenForRole('teacher');
        $teacherId = $this->getUserIdFromToken($teacherToken);
        $assignmentId = $this->createTestAssignment($this->tenantId, $teacherId);

        try {
            $response = $this->makeRequest("/api/assignments/$assignmentId", 'PATCH', [
                'headers' => [
                    'Authorization' => "Bearer $teacherToken",
                    'X-Orchestrator-Id' => $this->tenantId,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Updated Title'
                ])
            ]);

            $this->assert(
                $response['code'] === 200,
                "Teacher should be able to update own assignment",
                "Got: {$response['code']}"
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestAssignment($assignmentId);
        }
    }

    /**
     * Test 3: Teacher cannot update other teacher's assignments
     */
    private function testTeacherCannotUpdateOthersAssignment() {
        echo "Test 3: Teacher cannot update others' assignments...\n";

        $teacher1Token = $this->getTokenForRole('teacher', 'USER_TEACHER_1');
        $teacher2Id = 'USER_TEACHER_2';
        $assignmentId = $this->createTestAssignment($this->tenantId, $teacher2Id);

        try {
            $response = $this->makeRequest("/api/assignments/$assignmentId", 'PATCH', [
                'headers' => [
                    'Authorization' => "Bearer $teacher1Token",
                    'X-Orchestrator-Id' => $this->tenantId,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Unauthorized Update'
                ])
            ]);

            $this->assert(
                $response['code'] === 403,
                "Teacher should not update others' assignments",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['error']) && $response['body']['error'] === 'forbidden',
                "Error should be 'forbidden'",
                "Got: " . ($response['body']['error'] ?? 'none')
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestAssignment($assignmentId);
        }
    }

    /**
     * Test 4: Inspector has read-only access to assignments
     */
    private function testInspectorReadOnlyAccess() {
        echo "Test 4: Inspector has read-only access...\n";

        $inspectorToken = $this->getTokenForRole('inspector');
        $assignmentId = $this->createTestAssignment($this->tenantId, 'USER_TEACHER_X');

        try {
            // Inspector should be able to READ
            $response = $this->makeRequest("/api/assignments/$assignmentId", 'GET', [
                'headers' => [
                    'Authorization' => "Bearer $inspectorToken",
                    'X-Orchestrator-Id' => $this->tenantId
                ]
            ]);

            $this->assert(
                $response['code'] === 200,
                "Inspector should be able to read assignments",
                "Got: {$response['code']}"
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestAssignment($assignmentId);
        }
    }

    /**
     * Test 5: Inspector cannot create assignments
     */
    private function testInspectorCannotCreate() {
        echo "Test 5: Inspector cannot create assignments...\n";

        $inspectorToken = $this->getTokenForRole('inspector');
        $themeId = $this->createTestTheme($this->tenantId);

        try {
            $response = $this->makeRequest('/api/assignments', 'POST', [
                'headers' => [
                    'Authorization' => "Bearer $inspectorToken",
                    'X-Orchestrator-Id' => $this->tenantId,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Test Assignment',
                    'type' => 'quiz',
                    'theme_id' => $themeId,
                    'targets' => []
                ])
            ]);

            $this->assert(
                $response['code'] === 403,
                "Inspector should not be able to create assignments",
                "Got: {$response['code']}"
            );

            $this->assert(
                isset($response['body']['required_permission']),
                "Response should indicate required permission",
                "Got: " . json_encode($response['body'])
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestTheme($themeId);
        }
    }

    /**
     * Test 6: Direction has access to aggregated dashboards
     */
    private function testDirectionAggregatedAccess() {
        echo "Test 6: Direction has aggregated dashboard access...\n";

        $directionToken = $this->getTokenForRole('direction');

        try {
            $response = $this->makeRequest('/api/dashboard/aggregated', 'GET', [
                'headers' => [
                    'Authorization' => "Bearer $directionToken",
                    'X-Orchestrator-Id' => $this->tenantId
                ]
            ]);

            $this->assert(
                $response['code'] === 200,
                "Direction should access aggregated dashboard",
                "Got: {$response['code']}"
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    /**
     * Test 7: Admin has full access within tenant
     */
    private function testAdminFullAccess() {
        echo "Test 7: Admin has full access within tenant...\n";

        $adminToken = $this->getTokenForRole('admin');
        $teacherId = 'USER_TEACHER_OTHER';
        $assignmentId = $this->createTestAssignment($this->tenantId, $teacherId);

        try {
            // Admin should be able to UPDATE any assignment
            $response = $this->makeRequest("/api/assignments/$assignmentId", 'PATCH', [
                'headers' => [
                    'Authorization' => "Bearer $adminToken",
                    'X-Orchestrator-Id' => $this->tenantId,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'title' => 'Admin Updated'
                ])
            ]);

            $this->assert(
                $response['code'] === 200,
                "Admin should update any assignment",
                "Got: {$response['code']}"
            );

            // Admin should be able to DELETE any assignment
            $response = $this->makeRequest("/api/assignments/$assignmentId", 'DELETE', [
                'headers' => [
                    'Authorization' => "Bearer $adminToken",
                    'X-Orchestrator-Id' => $this->tenantId
                ]
            ]);

            $this->assert(
                $response['code'] === 200 || $response['code'] === 204,
                "Admin should delete any assignment",
                "Got: {$response['code']}"
            );

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            // Already deleted by admin
        }
    }

    /**
     * Test 8: Ownership filtering in list queries
     */
    private function testOwnershipFiltering() {
        echo "Test 8: Ownership filtering for teachers...\n";

        $teacher1Token = $this->getTokenForRole('teacher', 'USER_TEACHER_A');
        $teacher1Id = 'USER_TEACHER_A';
        $teacher2Id = 'USER_TEACHER_B';

        // Create assignments for both teachers
        $assignment1 = $this->createTestAssignment($this->tenantId, $teacher1Id);
        $assignment2 = $this->createTestAssignment($this->tenantId, $teacher2Id);

        try {
            // Teacher 1 lists assignments - should only see own
            $response = $this->makeRequest('/api/assignments', 'GET', [
                'headers' => [
                    'Authorization' => "Bearer $teacher1Token",
                    'X-Orchestrator-Id' => $this->tenantId
                ]
            ]);

            $this->assert(
                $response['code'] === 200,
                "Teacher should be able to list assignments",
                "Got: {$response['code']}"
            );

            if ($response['code'] === 200) {
                $assignments = $response['body']['assignments'] ?? [];

                // Should only contain teacher1's assignments
                $foundOwnAssignment = false;
                $foundOthersAssignment = false;

                foreach ($assignments as $assignment) {
                    if ($assignment['id'] === $assignment1) {
                        $foundOwnAssignment = true;
                    }
                    if ($assignment['id'] === $assignment2) {
                        $foundOthersAssignment = true;
                    }
                }

                $this->assert(
                    $foundOwnAssignment,
                    "Teacher should see own assignments",
                    "Own assignment found: " . ($foundOwnAssignment ? 'yes' : 'no')
                );

                $this->assert(
                    !$foundOthersAssignment,
                    "Teacher should not see others' assignments",
                    "Others' assignment found: " . ($foundOthersAssignment ? 'yes' : 'no')
                );
            }

        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        } finally {
            $this->cleanupTestAssignment($assignment1);
            $this->cleanupTestAssignment($assignment2);
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function makeRequest($endpoint, $method = 'GET', $options = []) {
        // Mock implementation - in real tests, use curl
        return [
            'code' => 200,
            'body' => []
        ];
    }

    private function getTokenForRole($role, $userId = null) {
        require_once __DIR__ . '/../../lib/auth.php';
        $userId = $userId ?? "USER_TEST_$role";
        return generateTestToken($this->tenantId, $role, $userId);
    }

    private function getUserIdFromToken($token) {
        // Decode JWT and extract user ID
        return 'USER_TEST';
    }

    private function createTestTheme($tenantId) {
        $themeId = 'THEME_TEST_' . uniqid();
        $userId = 'USER_TEST_ADMIN';

        db()->execute(
            'INSERT INTO themes (id, tenant_id, created_by, title, content, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$themeId, $tenantId, $userId, 'Test Theme', '{}', 'active']
        );

        return $themeId;
    }

    private function createTestAssignment($tenantId, $teacherId) {
        $assignmentId = 'ASSIGN_TEST_' . uniqid();
        $themeId = $this->createTestTheme($tenantId);

        db()->execute(
            'INSERT INTO assignments (id, tenant_id, teacher_id, theme_id, title, type, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$assignmentId, $tenantId, $teacherId, $themeId, 'Test Assignment', 'quiz', 'draft']
        );

        return $assignmentId;
    }

    private function cleanupTestTheme($themeId) {
        db()->execute('DELETE FROM themes WHERE id = ?', [$themeId]);
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
    $test = new RBACTest();
    $test->runAll();
}
