<?php
/**
 * clip_weighting.php - Custom clip weighting/scoring for streamers
 *
 * Allows streamers to customize how clips are ranked in their player:
 * - Adjust weight of views vs recency
 * - Boost/penalize duration ranges
 * - Boost/penalize specific categories
 * - Boost/penalize specific clippers
 * - Manual "golden clips" boost list
 */

class ClipWeighting {
    private $pdo;
    private $login;
    private $config;

    // Default configuration
    const DEFAULT_CONFIG = [
        'enabled' => true,
        'weights' => [
            'recency' => 1.0,      // How much newer clips matter (0-2.0)
            'views' => 1.0,        // View count importance (0-2.0)
            'play_penalty' => 1.0, // Penalty for recently played (0-2.0)
            'voting' => 1.0,       // Community votes impact (0-2.0)
        ],
        'duration_boosts' => [
            'short' => ['enabled' => false, 'max' => 30, 'boost' => 0],
            'medium' => ['enabled' => false, 'min' => 30, 'max' => 60, 'boost' => 0],
            'long' => ['enabled' => false, 'min' => 60, 'boost' => 0],
        ],
        'category_boosts' => [],    // [{game_id, name, boost}]
        'clipper_boosts' => [],     // [{name, boost}]
        'golden_clips' => [],       // [{seq, boost}]
    ];

    public function __construct($pdo, $login) {
        $this->pdo = $pdo;
        $this->login = strtolower(trim($login));
        $this->config = null;
    }

    /**
     * Ensure the weighting_config column exists
     */
    public function ensureTableColumn() {
        if (!$this->pdo) return false;

        try {
            $this->pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS weighting_config TEXT DEFAULT '{}'");
            return true;
        } catch (PDOException $e) {
            error_log("ClipWeighting: Failed to add column: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get streamer's weighting configuration
     */
    public function getConfig() {
        if ($this->config !== null) {
            return $this->config;
        }

        if (!$this->pdo || !$this->login) {
            return self::DEFAULT_CONFIG;
        }

        try {
            $this->ensureTableColumn();

            $stmt = $this->pdo->prepare("SELECT weighting_config FROM channel_settings WHERE login = ?");
            $stmt->execute([$this->login]);
            $row = $stmt->fetch();

            if ($row && !empty($row['weighting_config'])) {
                $saved = json_decode($row['weighting_config'], true);
                if (is_array($saved)) {
                    // Merge with defaults to ensure all keys exist
                    $this->config = array_replace_recursive(self::DEFAULT_CONFIG, $saved);
                    return $this->config;
                }
            }
        } catch (PDOException $e) {
            error_log("ClipWeighting: Failed to get config: " . $e->getMessage());
        }

        $this->config = self::DEFAULT_CONFIG;
        return $this->config;
    }

    /**
     * Save weighting configuration
     */
    public function saveConfig($config) {
        if (!$this->pdo || !$this->login) {
            return false;
        }

        // Validate and sanitize config
        $validated = $this->validateConfig($config);
        if (!$validated) {
            return false;
        }

        try {
            $this->ensureTableColumn();
            $json = json_encode($validated, JSON_UNESCAPED_SLASHES);

            $stmt = $this->pdo->prepare("
                INSERT INTO channel_settings (login, weighting_config)
                VALUES (?, ?)
                ON CONFLICT (login) DO UPDATE SET weighting_config = EXCLUDED.weighting_config
            ");
            $stmt->execute([$this->login, $json]);

            $this->config = $validated;
            return true;
        } catch (PDOException $e) {
            error_log("ClipWeighting: Failed to save config: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate configuration input
     */
    public function validateConfig($config) {
        if (!is_array($config)) {
            return false;
        }

        $validated = self::DEFAULT_CONFIG;

        // Enabled flag
        if (isset($config['enabled'])) {
            $validated['enabled'] = (bool)$config['enabled'];
        }

        // Weights (0.0 - 2.0 range)
        if (isset($config['weights']) && is_array($config['weights'])) {
            foreach (['recency', 'views', 'play_penalty', 'voting'] as $key) {
                if (isset($config['weights'][$key])) {
                    $val = (float)$config['weights'][$key];
                    $validated['weights'][$key] = max(0, min(2.0, $val));
                }
            }
        }

        // Duration boosts (-2.0 to 2.0)
        if (isset($config['duration_boosts']) && is_array($config['duration_boosts'])) {
            foreach (['short', 'medium', 'long'] as $key) {
                if (isset($config['duration_boosts'][$key]) && is_array($config['duration_boosts'][$key])) {
                    $db = $config['duration_boosts'][$key];
                    $validated['duration_boosts'][$key]['enabled'] = (bool)($db['enabled'] ?? false);
                    $validated['duration_boosts'][$key]['boost'] = max(-2.0, min(2.0, (float)($db['boost'] ?? 0)));
                }
            }
        }

        // Category boosts (array of {game_id, name, boost})
        if (isset($config['category_boosts']) && is_array($config['category_boosts'])) {
            $validated['category_boosts'] = [];
            foreach ($config['category_boosts'] as $cat) {
                if (isset($cat['game_id']) && isset($cat['boost'])) {
                    $validated['category_boosts'][] = [
                        'game_id' => (string)$cat['game_id'],
                        'name' => (string)($cat['name'] ?? ''),
                        'boost' => max(-2.0, min(2.0, (float)$cat['boost'])),
                    ];
                }
            }
        }

        // Clipper boosts (array of {name, boost})
        if (isset($config['clipper_boosts']) && is_array($config['clipper_boosts'])) {
            $validated['clipper_boosts'] = [];
            foreach ($config['clipper_boosts'] as $clipper) {
                if (isset($clipper['name']) && isset($clipper['boost'])) {
                    $validated['clipper_boosts'][] = [
                        'name' => strtolower(trim((string)$clipper['name'])),
                        'boost' => max(-2.0, min(2.0, (float)$clipper['boost'])),
                    ];
                }
            }
        }

        // Golden clips (array of {seq, boost})
        if (isset($config['golden_clips']) && is_array($config['golden_clips'])) {
            $validated['golden_clips'] = [];
            foreach ($config['golden_clips'] as $golden) {
                if (isset($golden['seq'])) {
                    $validated['golden_clips'][] = [
                        'seq' => (int)$golden['seq'],
                        'boost' => max(0, min(5.0, (float)($golden['boost'] ?? 2.0))),
                        'title' => (string)($golden['title'] ?? ''),
                    ];
                }
            }
        }

        return $validated;
    }

    /**
     * Calculate score for a single clip
     *
     * @param array $clip Clip data (id, seq, view_count, duration, game_id, creator_name, created_at)
     * @param int $netVotes Net vote score (up - down)
     * @param int $playCount How many times this clip has played
     * @param int $minutesSincePlayed Minutes since last played
     * @return float Calculated score
     */
    public function calculateScore($clip, $netVotes = 0, $playCount = 0, $minutesSincePlayed = 999) {
        $config = $this->getConfig();

        if (!$config['enabled']) {
            // Return default scoring if weighting disabled
            return $this->defaultScore($clip, $netVotes, $playCount, $minutesSincePlayed);
        }

        $weights = $config['weights'];
        $score = 1.0; // Base score

        // Recency bonus (time since last played, not creation date)
        // Up to 5 points based on how long since played, scaled by weight
        $recencyBonus = min(5, $minutesSincePlayed / 15) * $weights['recency'];
        $score += $recencyBonus;

        // Play count penalty (0-3 points, scaled by weight)
        $playPenalty = max(0, 3 - $playCount) * $weights['play_penalty'];
        $score += $playPenalty;

        // View count bonus (log scale, max 1.8, scaled by weight)
        $views = (int)($clip['view_count'] ?? 0);
        $viewBonus = min(1.8, log10($views + 1) * 0.55) * $weights['views'];
        $score += $viewBonus;

        // Voting bonus (-5 to +5, scaled by weight)
        $voteBonus = max(-5, min(5, $netVotes * 0.5)) * $weights['voting'];
        $score += $voteBonus;

        // Duration boost
        $duration = (float)($clip['duration'] ?? 30);
        foreach ($config['duration_boosts'] as $key => $db) {
            if (!$db['enabled']) continue;

            $matches = false;
            if ($key === 'short' && $duration < ($db['max'] ?? 30)) {
                $matches = true;
            } elseif ($key === 'medium' && $duration >= ($db['min'] ?? 30) && $duration <= ($db['max'] ?? 60)) {
                $matches = true;
            } elseif ($key === 'long' && $duration > ($db['min'] ?? 60)) {
                $matches = true;
            }

            if ($matches) {
                $score += $db['boost'];
            }
        }

        // Category boost
        $gameId = $clip['game_id'] ?? '';
        foreach ($config['category_boosts'] as $cat) {
            if ($cat['game_id'] === $gameId) {
                $score += $cat['boost'];
                break;
            }
        }

        // Clipper boost
        $clipper = strtolower($clip['creator_name'] ?? '');
        foreach ($config['clipper_boosts'] as $cb) {
            if ($cb['name'] === $clipper) {
                $score += $cb['boost'];
                break;
            }
        }

        // Golden clip boost
        $seq = (int)($clip['seq'] ?? 0);
        foreach ($config['golden_clips'] as $golden) {
            if ($golden['seq'] === $seq) {
                $score += $golden['boost'];
                break;
            }
        }

        return max(0, $score); // Never go negative
    }

    /**
     * Default scoring (original algorithm)
     */
    private function defaultScore($clip, $netVotes, $playCount, $minutesSincePlayed) {
        $score = 1;
        $score += min(5, $minutesSincePlayed / 15);
        $score += max(0, 3 - $playCount);
        $views = (int)($clip['view_count'] ?? 0);
        $score += min(1.8, log10($views + 1) * 0.55);
        $score += max(-5, min(5, $netVotes * 0.5));
        return $score;
    }

    /**
     * Add a golden clip
     */
    public function addGoldenClip($seq, $boost = 2.0, $title = '') {
        $config = $this->getConfig();

        // Check if already exists
        foreach ($config['golden_clips'] as $g) {
            if ($g['seq'] === (int)$seq) {
                return false; // Already exists
            }
        }

        $config['golden_clips'][] = [
            'seq' => (int)$seq,
            'boost' => max(0, min(5.0, (float)$boost)),
            'title' => (string)$title,
        ];

        return $this->saveConfig($config);
    }

    /**
     * Remove a golden clip
     */
    public function removeGoldenClip($seq) {
        $config = $this->getConfig();

        $config['golden_clips'] = array_values(array_filter(
            $config['golden_clips'],
            function($g) use ($seq) { return $g['seq'] !== (int)$seq; }
        ));

        return $this->saveConfig($config);
    }

    /**
     * Get available categories for this streamer (for UI dropdown)
     */
    public function getCategories() {
        if (!$this->pdo || !$this->login) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT c.game_id, gc.name, COUNT(*) as count
                FROM clips c
                LEFT JOIN games_cache gc ON c.game_id = gc.game_id
                WHERE c.login = ? AND c.blocked = FALSE AND c.game_id IS NOT NULL AND c.game_id != ''
                GROUP BY c.game_id, gc.name
                ORDER BY count DESC
                LIMIT 50
            ");
            $stmt->execute([$this->login]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get top clippers for this streamer (for UI dropdown)
     */
    public function getClippers() {
        if (!$this->pdo || !$this->login) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT creator_name as name, COUNT(*) as count
                FROM clips
                WHERE login = ? AND blocked = FALSE AND creator_name IS NOT NULL AND creator_name != ''
                GROUP BY creator_name
                ORDER BY count DESC
                LIMIT 50
            ");
            $stmt->execute([$this->login]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Export config as JSON for client-side use
     */
    public function getConfigForPlayer() {
        $config = $this->getConfig();

        // Return only what the player needs
        return [
            'enabled' => $config['enabled'],
            'weights' => $config['weights'],
            'duration_boosts' => $config['duration_boosts'],
            'category_boosts' => array_map(function($c) {
                return ['game_id' => $c['game_id'], 'boost' => $c['boost']];
            }, $config['category_boosts']),
            'clipper_boosts' => array_map(function($c) {
                return ['name' => $c['name'], 'boost' => $c['boost']];
            }, $config['clipper_boosts']),
            'golden_clips' => array_map(function($g) {
                return ['seq' => $g['seq'], 'boost' => $g['boost']];
            }, $config['golden_clips']),
        ];
    }
}
