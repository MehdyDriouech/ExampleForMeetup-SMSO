-- ============================================================
-- Migration 003: Enhanced Tenant Isolation
-- Sprint 3: Multi-tenant & RBAC
-- Date: 2025-11-12
-- ============================================================
-- Description:
--   Adds tenant_id column to assignment_targets table for
--   complete tenant isolation across all data tables.
--   This ensures no cross-tenant access is possible via
--   assignment target relationships.
-- ============================================================

-- Add tenant_id to assignment_targets
ALTER TABLE assignment_targets
ADD COLUMN tenant_id VARCHAR(50) NOT NULL AFTER id,
ADD FOREIGN KEY fk_assignment_targets_tenant (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
ADD INDEX idx_tenant (tenant_id);

-- Update existing records to inherit tenant_id from assignments
-- This is safe because all assignments already have tenant_id
UPDATE assignment_targets at
INNER JOIN assignments a ON at.assignment_id = a.id
SET at.tenant_id = a.tenant_id
WHERE at.tenant_id IS NULL OR at.tenant_id = '';

-- Add compound index for efficient tenant-filtered queries
ALTER TABLE assignment_targets
ADD INDEX idx_tenant_assignment (tenant_id, assignment_id);

-- Add compound index for target lookups within tenant
ALTER TABLE assignment_targets
ADD INDEX idx_tenant_target (tenant_id, target_type, target_id);

-- ============================================================
-- Verification Queries (run these manually to verify)
-- ============================================================

-- 1. Verify all assignment_targets have tenant_id
-- SELECT COUNT(*) as missing_tenant_id
-- FROM assignment_targets
-- WHERE tenant_id IS NULL OR tenant_id = '';
-- Expected: 0

-- 2. Verify tenant_id matches parent assignment
-- SELECT COUNT(*) as mismatched_tenants
-- FROM assignment_targets at
-- INNER JOIN assignments a ON at.assignment_id = a.id
-- WHERE at.tenant_id != a.tenant_id;
-- Expected: 0

-- 3. Check index creation
-- SHOW INDEX FROM assignment_targets;
-- Expected: Should see idx_tenant, idx_tenant_assignment, idx_tenant_target

-- ============================================================
-- Rollback (if needed)
-- ============================================================

-- DROP INDEX idx_tenant_target ON assignment_targets;
-- DROP INDEX idx_tenant_assignment ON assignment_targets;
-- DROP INDEX idx_tenant ON assignment_targets;
-- ALTER TABLE assignment_targets DROP FOREIGN KEY fk_assignment_targets_tenant;
-- ALTER TABLE assignment_targets DROP COLUMN tenant_id;
