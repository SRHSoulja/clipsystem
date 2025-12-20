<?php
/**
 * clip_filter.php - Helper to apply content filters to clip queries
 */

require_once __DIR__ . '/../db_config.php';

class ClipFilter {
    private $pdo;
    private $login;
    private $blockedWords = [];
    private $blockedClippers = [];

    public function __construct($pdo, $login) {
        $this->pdo = $pdo;
        $this->login = $login;
        $this->loadFilters();
    }

    private function loadFilters() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT blocked_words, blocked_clippers
                FROM channel_settings WHERE login = ?
            ");
            $stmt->execute([$this->login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $this->blockedWords = json_decode($row['blocked_words'] ?: '[]', true) ?: [];
                $this->blockedClippers = json_decode($row['blocked_clippers'] ?: '[]', true) ?: [];
            }
        } catch (PDOException $e) {
            // Filters not available, continue without them
        }
    }

    /**
     * Get additional WHERE clauses for filtering
     * Returns: ['sql' => string, 'params' => array]
     */
    public function getWhereClause() {
        $clauses = [];
        $params = [];

        // Word filtering
        foreach ($this->blockedWords as $word) {
            $clauses[] = "title NOT ILIKE ?";
            $params[] = '%' . $word . '%';
        }

        // Clipper filtering
        if (!empty($this->blockedClippers)) {
            $placeholders = implode(',', array_fill(0, count($this->blockedClippers), '?'));
            $clauses[] = "(creator_name IS NULL OR creator_name NOT IN ({$placeholders}))";
            $params = array_merge($params, $this->blockedClippers);
        }

        return [
            'sql' => $clauses ? ' AND ' . implode(' AND ', $clauses) : '',
            'params' => $params
        ];
    }

    /**
     * Check if a specific clip passes the filters
     */
    public function passesFilter($title, $creatorName) {
        // Check blocked words
        $titleLower = strtolower($title);
        foreach ($this->blockedWords as $word) {
            if (stripos($titleLower, strtolower($word)) !== false) {
                return false;
            }
        }

        // Check blocked clippers
        if ($creatorName && in_array($creatorName, $this->blockedClippers)) {
            return false;
        }

        return true;
    }
}
