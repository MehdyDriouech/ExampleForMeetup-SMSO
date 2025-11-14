<?php
/**
 * Sprint 15 - IA Audit Service
 *
 * Service pour enregistrer et gérer les logs d'audit IA
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

class IAAuditService {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log an IA interaction
     *
     * @param array $data {
     *   tenant_id: string,
     *   user_id: string,
     *   user_role: string,
     *   prompt_text: string,
     *   model_used: string,
     *   model_version: string (optional),
     *   api_provider: string (optional, default: 'openai'),
     *   response_text: string (optional),
     *   tokens_prompt: int (optional),
     *   tokens_completion: int (optional),
     *   tokens_total: int,
     *   latency_ms: int (optional),
     *   context_type: string (optional),
     *   context_id: string (optional),
     *   status: string (default: 'success'),
     *   error_message: string (optional),
     *   content_filtered: bool (default: false),
     *   flagged_reason: string (optional)
     * }
     * @return string Log ID
     */
    public function logInteraction($data) {
        // Validate required fields
        $required = ['tenant_id', 'user_id', 'prompt_text', 'model_used', 'tokens_total'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Check budget before logging
        $budgetExceeded = $this->checkBudget($data['tenant_id'], $data['user_id'], $data['tokens_total']);

        $logData = [
            'id' => generateUUID(),
            'tenant_id' => $data['tenant_id'],
            'user_id' => $data['user_id'],
            'user_role' => $data['user_role'] ?? null,
            'prompt_text' => $data['prompt_text'],
            'prompt_hash' => hash('sha256', $data['prompt_text']),
            'model_used' => $data['model_used'],
            'model_version' => $data['model_version'] ?? null,
            'api_provider' => $data['api_provider'] ?? 'openai',
            'response_text' => $data['response_text'] ?? null,
            'response_truncated' => isset($data['response_text']) && strlen($data['response_text']) > 10000,
            'tokens_prompt' => $data['tokens_prompt'] ?? null,
            'tokens_completion' => $data['tokens_completion'] ?? null,
            'tokens_total' => $data['tokens_total'],
            'latency_ms' => $data['latency_ms'] ?? null,
            'context_type' => $data['context_type'] ?? null,
            'context_id' => $data['context_id'] ?? null,
            'status' => $budgetExceeded ? 'budget_exceeded' : ($data['status'] ?? 'success'),
            'error_message' => $data['error_message'] ?? null,
            'content_filtered' => $data['content_filtered'] ?? false,
            'flagged_reason' => $data['flagged_reason'] ?? null,
            'ip_address' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Truncate response if too long
        if (isset($logData['response_text']) && strlen($logData['response_text']) > 10000) {
            $logData['response_text'] = substr($logData['response_text'], 0, 10000) . '... [truncated]';
        }

        try {
            db()->beginTransaction();

            // Insert log
            db()->insert('audit_ia_log', $logData);

            // Update budgets if successful
            if (!$budgetExceeded && $data['status'] === 'success') {
                $this->updateBudgets($data['tenant_id'], $data['user_id'], $data['tokens_total']);
            }

            db()->commit();

            return $logData['id'];

        } catch (Exception $e) {
            db()->rollback();
            error_log("Failed to log IA interaction: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if budget is exceeded
     *
     * @param string $tenantId
     * @param string $userId
     * @param int $tokensNeeded
     * @return bool True if budget exceeded
     */
    public function checkBudget($tenantId, $userId, $tokensNeeded) {
        // Check tenant budget
        $tenantBudget = db()->queryOne(
            'SELECT * FROM ia_budgets
             WHERE tenant_id = :tenant_id
             AND budget_type = "tenant"
             AND period_end >= NOW()
             ORDER BY period_end DESC
             LIMIT 1',
            ['tenant_id' => $tenantId]
        );

        if ($tenantBudget) {
            $remainingTenant = $tenantBudget['max_tokens'] - $tenantBudget['used_tokens'];
            if ($remainingTenant < $tokensNeeded) {
                return true;
            }
        }

        // Check teacher budget
        $teacherBudget = db()->queryOne(
            'SELECT * FROM ia_budgets
             WHERE tenant_id = :tenant_id
             AND budget_type = "teacher"
             AND user_id = :user_id
             AND period_end >= NOW()
             ORDER BY period_end DESC
             LIMIT 1',
            ['tenant_id' => $tenantId, 'user_id' => $userId]
        );

        if ($teacherBudget) {
            $remainingTeacher = $teacherBudget['max_tokens'] - $teacherBudget['used_tokens'];
            if ($remainingTeacher < $tokensNeeded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update budgets after successful IA interaction
     *
     * @param string $tenantId
     * @param string $userId
     * @param int $tokensUsed
     */
    private function updateBudgets($tenantId, $userId, $tokensUsed) {
        // Update tenant budget
        db()->execute(
            'UPDATE ia_budgets
             SET used_tokens = used_tokens + :tokens,
                 used_requests = used_requests + 1
             WHERE tenant_id = :tenant_id
             AND budget_type = "tenant"
             AND period_end >= NOW()',
            ['tokens' => $tokensUsed, 'tenant_id' => $tenantId]
        );

        // Update teacher budget
        db()->execute(
            'UPDATE ia_budgets
             SET used_tokens = used_tokens + :tokens,
                 used_requests = used_requests + 1
             WHERE tenant_id = :tenant_id
             AND budget_type = "teacher"
             AND user_id = :user_id
             AND period_end >= NOW()',
            ['tokens' => $tokensUsed, 'tenant_id' => $tenantId, 'user_id' => $userId]
        );

        // Check if alert threshold reached
        $this->checkAlertThresholds($tenantId, $userId);
    }

    /**
     * Check if budget alert thresholds are reached
     *
     * @param string $tenantId
     * @param string $userId
     */
    private function checkAlertThresholds($tenantId, $userId) {
        // Check tenant budget
        $tenantBudget = db()->queryOne(
            'SELECT * FROM ia_budgets
             WHERE tenant_id = :tenant_id
             AND budget_type = "tenant"
             AND period_end >= NOW()
             ORDER BY period_end DESC
             LIMIT 1',
            ['tenant_id' => $tenantId]
        );

        if ($tenantBudget && !$tenantBudget['alert_sent']) {
            $usagePercent = ($tenantBudget['used_tokens'] / $tenantBudget['max_tokens']) * 100;
            if ($usagePercent >= $tenantBudget['alert_threshold_percent']) {
                $this->sendBudgetAlert($tenantBudget, $usagePercent);
            }
        }

        // Check teacher budget
        $teacherBudget = db()->queryOne(
            'SELECT * FROM ia_budgets
             WHERE tenant_id = :tenant_id
             AND budget_type = "teacher"
             AND user_id = :user_id
             AND period_end >= NOW()
             ORDER BY period_end DESC
             LIMIT 1',
            ['tenant_id' => $tenantId, 'user_id' => $userId]
        );

        if ($teacherBudget && !$teacherBudget['alert_sent']) {
            $usagePercent = ($teacherBudget['used_tokens'] / $teacherBudget['max_tokens']) * 100;
            if ($usagePercent >= $teacherBudget['alert_threshold_percent']) {
                $this->sendBudgetAlert($teacherBudget, $usagePercent);
            }
        }
    }

    /**
     * Send budget alert (placeholder - should integrate with notification system)
     *
     * @param array $budget
     * @param float $usagePercent
     */
    private function sendBudgetAlert($budget, $usagePercent) {
        // Mark alert as sent
        db()->execute(
            'UPDATE ia_budgets
             SET alert_sent = 1, alert_sent_at = NOW()
             WHERE id = :id',
            ['id' => $budget['id']]
        );

        // TODO: Send email notification or create in-app notification
        error_log("Budget alert: {$budget['budget_type']} budget at {$usagePercent}%");
    }

    /**
     * Get usage stats for a tenant
     *
     * @param string $tenantId
     * @param int $days Number of days to look back
     * @return array
     */
    public function getUsageStats($tenantId, $days = 30) {
        return db()->query(
            'SELECT
                DATE(created_at) as date,
                COUNT(*) as requests,
                SUM(tokens_total) as tokens_used,
                AVG(latency_ms) as avg_latency_ms
             FROM audit_ia_log
             WHERE tenant_id = :tenant_id
             AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC',
            ['tenant_id' => $tenantId, 'days' => $days]
        );
    }

    /**
     * Get user usage stats
     *
     * @param string $tenantId
     * @param string $userId
     * @param int $days
     * @return array
     */
    public function getUserUsageStats($tenantId, $userId, $days = 30) {
        return db()->queryOne(
            'SELECT
                COUNT(*) as total_requests,
                SUM(tokens_total) as total_tokens,
                AVG(latency_ms) as avg_latency_ms,
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successful_requests
             FROM audit_ia_log
             WHERE tenant_id = :tenant_id
             AND user_id = :user_id
             AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'days' => $days]
        );
    }
}

/**
 * Helper function
 */
function iaAudit() {
    return IAAuditService::getInstance();
}
