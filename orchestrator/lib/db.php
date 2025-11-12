<?php
/**
 * Study-mate School Orchestrator - Database Layer
 */

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            logError('Database connection failed', ['error' => $e->getMessage()]);
            throw new Exception('Database connection failed');
        }
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Test de connexion
     */
    public function testConnection() {
        try {
            $start = microtime(true);
            $stmt = $this->pdo->query('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;
            
            return [
                'status' => 'ok',
                'latency_ms' => round($latency, 2)
            ];
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Exécuter une requête SELECT
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            logError('Query failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Exécuter une requête INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            logError('Execute failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Récupérer une seule ligne
     */
    public function queryOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return $result[0] ?? null;
    }
    
    /**
     * Insérer et retourner l'ID
     */
    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);
        
        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            $stmt->execute();
            return $this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            logError('Insert failed', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Mettre à jour
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "$key = :$key";
        }
        $setClause = implode(', ', $set);
        
        $sql = "UPDATE $table SET $setClause WHERE $where";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            foreach ($whereParams as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
            
        } catch (PDOException $e) {
            logError('Update failed', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Supprimer
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            logError('Delete failed', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Commencer une transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Valider une transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Annuler une transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Vérifier si un enregistrement existe
     */
    public function exists($table, $where, $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $where";
        $result = $this->queryOne($sql, $params);
        return ($result['count'] ?? 0) > 0;
    }
    
    /**
     * Compter les enregistrements
     */
    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $where";
        $result = $this->queryOne($sql, $params);
        return (int)($result['count'] ?? 0);
    }
}

/**
 * Helper global
 */
function db() {
    return Database::getInstance();
}
