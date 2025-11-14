/**
 * Sprint 15 - Student Sync Service for ErgoMate
 *
 * Service pour synchroniser les élèves avec leurs UUID
 * entre l'orchestrator et ErgoMate
 */

class StudentSyncService {
    constructor(config) {
        this.orchestratorUrl = config.orchestratorUrl || 'http://localhost:8000';
        this.tenantId = config.tenantId;
        this.apiToken = config.apiToken;
    }

    /**
     * Synchronize student from Orchestrator
     *
     * @param {string} studentUuid - UUID pédagogique de l'élève
     * @returns {Promise<Object>} Student data
     */
    async syncStudent(studentUuid) {
        try {
            const response = await fetch(
                `${this.orchestratorUrl}/api/students/${studentUuid}`,
                {
                    headers: {
                        'Authorization': `Bearer ${this.apiToken}`,
                        'X-Orchestrator-Id': this.tenantId,
                        'Content-Type': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to sync student: ${response.statusText}`);
            }

            const data = await response.json();
            return this.mapStudentData(data.data);

        } catch (error) {
            console.error('Error syncing student:', error);
            throw error;
        }
    }

    /**
     * Map student data from Orchestrator to ErgoMate format
     *
     * @param {Object} orchestratorStudent - Student data from Orchestrator
     * @returns {Object} Mapped student data
     */
    mapStudentData(orchestratorStudent) {
        return {
            uuid_student: orchestratorStudent.uuid_student,
            uuid_social: orchestratorStudent.uuid_social,
            id: orchestratorStudent.id,
            firstname: orchestratorStudent.firstname,
            lastname: orchestratorStudent.lastname,
            class_id: orchestratorStudent.class_id,
            class_name: orchestratorStudent.class_name,
            promo_id: orchestratorStudent.promo_id,
            promo_name: orchestratorStudent.promo_name,
            rgpd_status: orchestratorStudent.rgpd_status,
            created_at: orchestratorStudent.created_at
        };
    }

    /**
     * Create or update student in ErgoMate local storage
     *
     * @param {Object} studentData - Student data
     * @param {Object} db - Database connection
     */
    async upsertStudentLocal(studentData, db) {
        try {
            // Check if student exists
            const existingStudent = await db.query(
                'SELECT * FROM students WHERE uuid_student = ?',
                [studentData.uuid_student]
            );

            if (existingStudent.length > 0) {
                // Update existing student
                await db.query(
                    `UPDATE students
                     SET firstname = ?,
                         lastname = ?,
                         class_id = ?,
                         promo_id = ?,
                         rgpd_status = ?,
                         updated_at = NOW()
                     WHERE uuid_student = ?`,
                    [
                        studentData.firstname,
                        studentData.lastname,
                        studentData.class_id,
                        studentData.promo_id,
                        studentData.rgpd_status,
                        studentData.uuid_student
                    ]
                );

                console.log(`Student ${studentData.uuid_student} updated in ErgoMate`);
            } else {
                // Insert new student
                await db.query(
                    `INSERT INTO students
                     (uuid_student, uuid_social, id, firstname, lastname, class_id, promo_id, rgpd_status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())`,
                    [
                        studentData.uuid_student,
                        studentData.uuid_social,
                        studentData.id,
                        studentData.firstname,
                        studentData.lastname,
                        studentData.class_id,
                        studentData.promo_id,
                        studentData.rgpd_status
                    ]
                );

                console.log(`Student ${studentData.uuid_student} created in ErgoMate`);
            }

            return true;
        } catch (error) {
            console.error('Error upserting student in ErgoMate:', error);
            throw error;
        }
    }

    /**
     * Sync all students from Orchestrator
     *
     * @param {string} classId - Class ID to sync
     * @param {Object} db - Database connection
     */
    async syncClassStudents(classId, db) {
        try {
            const response = await fetch(
                `${this.orchestratorUrl}/api/students?classId=${classId}`,
                {
                    headers: {
                        'Authorization': `Bearer ${this.apiToken}`,
                        'X-Orchestrator-Id': this.tenantId,
                        'Content-Type': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to sync class students: ${response.statusText}`);
            }

            const data = await response.json();
            const students = data.data;

            console.log(`Syncing ${students.length} students for class ${classId}`);

            for (const student of students) {
                const mappedStudent = this.mapStudentData(student);
                await this.upsertStudentLocal(mappedStudent, db);
            }

            console.log(`✅ Synced ${students.length} students successfully`);
            return students.length;

        } catch (error) {
            console.error('Error syncing class students:', error);
            throw error;
        }
    }

    /**
     * Handle student pseudonymization
     * Update local data when student is pseudonymized in Orchestrator
     *
     * @param {string} studentUuid - UUID of pseudonymized student
     * @param {Object} db - Database connection
     */
    async handlePseudonymization(studentUuid, db) {
        try {
            await db.query(
                `UPDATE students
                 SET firstname = 'ANONYME',
                     lastname = 'ANONYME',
                     rgpd_status = 'pseudonymized',
                     updated_at = NOW()
                 WHERE uuid_student = ?`,
                [studentUuid]
            );

            console.log(`Student ${studentUuid} pseudonymized in ErgoMate`);
            return true;
        } catch (error) {
            console.error('Error handling pseudonymization:', error);
            throw error;
        }
    }

    /**
     * Handle student deletion
     * Mark student as deleted in local storage
     *
     * @param {string} studentUuid - UUID of deleted student
     * @param {Object} db - Database connection
     */
    async handleDeletion(studentUuid, db) {
        try {
            await db.query(
                `UPDATE students
                 SET rgpd_status = 'deleted',
                     updated_at = NOW()
                 WHERE uuid_student = ?`,
                [studentUuid]
            );

            console.log(`Student ${studentUuid} marked as deleted in ErgoMate`);
            return true;
        } catch (error) {
            console.error('Error handling deletion:', error);
            throw error;
        }
    }

    /**
     * Get student by UUID
     *
     * @param {string} studentUuid - Student UUID
     * @param {Object} db - Database connection
     * @returns {Promise<Object|null>} Student data or null
     */
    async getStudentByUuid(studentUuid, db) {
        try {
            const result = await db.query(
                'SELECT * FROM students WHERE uuid_student = ? AND rgpd_status = "active"',
                [studentUuid]
            );

            return result.length > 0 ? result[0] : null;
        } catch (error) {
            console.error('Error getting student by UUID:', error);
            throw error;
        }
    }

    /**
     * Get student by social UUID
     *
     * @param {string} socialUuid - Social UUID
     * @param {Object} db - Database connection
     * @returns {Promise<Object|null>} Student data or null
     */
    async getStudentBySocialUuid(socialUuid, db) {
        try {
            const result = await db.query(
                'SELECT uuid_student, uuid_social, class_id, rgpd_status FROM students WHERE uuid_social = ?',
                [socialUuid]
            );

            return result.length > 0 ? result[0] : null;
        } catch (error) {
            console.error('Error getting student by social UUID:', error);
            throw error;
        }
    }

    /**
     * Validate student access (check RGPD status)
     *
     * @param {string} studentUuid - Student UUID
     * @param {Object} db - Database connection
     * @returns {Promise<boolean>} True if student can access
     */
    async validateStudentAccess(studentUuid, db) {
        try {
            const student = await this.getStudentByUuid(studentUuid, db);

            if (!student) {
                return false;
            }

            // Only active students can access
            return student.rgpd_status === 'active';
        } catch (error) {
            console.error('Error validating student access:', error);
            return false;
        }
    }

    /**
     * Sync student progress to Orchestrator
     *
     * @param {string} studentUuid - Student UUID
     * @param {Object} progressData - Progress data to sync
     */
    async syncProgressToOrchestrator(studentUuid, progressData) {
        try {
            const response = await fetch(
                `${this.orchestratorUrl}/api/students/${studentUuid}/progress`,
                {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.apiToken}`,
                        'X-Orchestrator-Id': this.tenantId,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(progressData)
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to sync progress: ${response.statusText}`);
            }

            console.log(`Progress synced for student ${studentUuid}`);
            return true;
        } catch (error) {
            console.error('Error syncing progress:', error);
            // Don't throw - progress sync failures shouldn't break the app
            return false;
        }
    }
}

module.exports = StudentSyncService;
