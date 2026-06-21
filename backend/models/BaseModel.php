<?php

require_once __DIR__ . '/../config/Database.php';

class BaseModel {
    protected $pdo;
    protected $table;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollBack() {
        return $this->pdo->rollBack();
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function findAll($where = [], $orderBy = 'id DESC', $limit = null) {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY {$orderBy}";

        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert($data) {
        $keys = array_keys($data);
        $placeholders = array_map(function($key) {
            return ":{$key}";
        }, $keys);

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $keys) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        return $this->pdo->lastInsertId();
    }

    public function update($id, $data) {
        $sets = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }

        $sets[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
