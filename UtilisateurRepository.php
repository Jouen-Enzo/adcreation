<?php
/**
 * UtilisateurRepository — CRUD MySQL pour la table utilisateurs
 * Source de vérité avant synchronisation vers AD / OpenLDAP / Aurion
 */
require_once __DIR__ . '/DBConfig.php';
require_once __DIR__ . '/Logging.php';

class UtilisateurRepository
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = DBConfig::getConnection();
    }

    // ----------------------------------------------------------------
    // CRÉATION
    // ----------------------------------------------------------------

    /**
     * Insère un nouvel utilisateur + initialise les lignes sync_status.
     * @return int  l'ID MySQL inséré
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO utilisateurs (
            nom, prenom, date_naissance, lieu_naissance, genre, nationalite,
            login, email, upn, password_tmp, user_type, dn_ad, account_enabled,
            telephone, telephone_mobile, fax,
            titre, poste, departement, societe, service, manager_login,
            bureau, adresse_rue, ville, code_postal, etat_province, pays,
            home_drive, home_directory, profile_path, logon_script,
            date_expiration, date_debut,
            aurion_id, promo,
            created_by, notes
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?
        )";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->db->error);
        }

        $stmt->bind_param(
            'ssssssssssssisssssssssssssssssssssssss',
            $data['nom'],
            $data['prenom'],
            $data['date_naissance'],
            $data['lieu_naissance'],
            $data['genre'],
            $data['nationalite'],
            $data['login'],
            $data['email'],
            $data['upn'],
            $data['password_tmp'],
            $data['user_type'],
            $data['dn_ad'],
            $data['account_enabled'],
            $data['telephone'],
            $data['telephone_mobile'],
            $data['fax'],
            $data['titre'],
            $data['poste'],
            $data['departement'],
            $data['societe'],
            $data['service'],
            $data['manager_login'],
            $data['bureau'],
            $data['adresse_rue'],
            $data['ville'],
            $data['code_postal'],
            $data['etat_province'],
            $data['pays'],
            $data['home_drive'],
            $data['home_directory'],
            $data['profile_path'],
            $data['logon_script'],
            $data['date_expiration'],
            $data['date_debut'],
            $data['aurion_id'],
            $data['promo'],
            $data['created_by'],
            $data['notes']
        );

        if (!$stmt->execute()) {
            throw new Exception('Insert utilisateur failed: ' . $stmt->error);
        }

        $id = (int) $this->db->insert_id;
        $stmt->close();

        // Initialiser les lignes de sync pour chaque cible
        $this->initSyncStatus($id);
        $this->audit($id, 'CREATE', 'db', "Utilisateur {$data['login']} créé en base", $data['created_by'] ?? null);

        return $id;
    }

    // ----------------------------------------------------------------
    // LECTURE
    // ----------------------------------------------------------------

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM utilisateurs WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM utilisateurs WHERE login = ?');
        $stmt->bind_param('s', $login);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function findAll(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        $types = '';

        if (!empty($filters['user_type'])) {
            $where[] = 'user_type = ?';
            $params[] = $filters['user_type'];
            $types .= 's';
        }
        if (!empty($filters['search'])) {
            $q = '%' . $filters['search'] . '%';
            $where[] = '(nom LIKE ? OR prenom LIKE ? OR login LIKE ? OR email LIKE ?)';
            $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
            $types .= 'ssss';
        }
        if (isset($filters['account_enabled'])) {
            $where[] = 'account_enabled = ?';
            $params[] = (int)$filters['account_enabled'];
            $types .= 'i';
        }

        $sql = 'SELECT * FROM utilisateurs WHERE ' . implode(' AND ', $where)
             . ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->db->prepare($sql);
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    // ----------------------------------------------------------------
    // MISE À JOUR
    // ----------------------------------------------------------------

    public function update(int $id, array $data, string $operateur = 'system'): bool
    {
        $allowed = [
            'nom','prenom','date_naissance','lieu_naissance','genre','nationalite',
            'login','email','upn','user_type','dn_ad','account_enabled',
            'telephone','telephone_mobile','fax',
            'titre','poste','departement','societe','service','manager_login',
            'bureau','adresse_rue','ville','code_postal','etat_province','pays',
            'home_drive','home_directory','profile_path','logon_script',
            'date_expiration','date_debut','aurion_id','promo','notes'
        ];

        $sets = [];
        $params = [];
        $types = '';

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = ?";
                $params[] = $data[$col];
                $types .= 's';
            }
        }

        if (empty($sets)) return false;

        $params[] = $id;
        $types .= 'i';

        $sql = 'UPDATE utilisateurs SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            // Marquer comme "pending" pour re-synchronisation
            $this->markAllPending($id);
            $this->audit($id, 'UPDATE', 'db', json_encode(array_keys($data)), $operateur);
        }

        return $ok;
    }

    public function updateDn(int $id, string $dn): void
    {
        $stmt = $this->db->prepare('UPDATE utilisateurs SET dn_ad = ? WHERE id = ?');
        $stmt->bind_param('si', $dn, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function updateAccountEnabled(int $id, bool $enabled): void
    {
        $val = $enabled ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE utilisateurs SET account_enabled = ? WHERE id = ?');
        $stmt->bind_param('ii', $val, $id);
        $stmt->execute();
        $stmt->close();
    }

    // ----------------------------------------------------------------
    // SYNCHRONISATION
    // ----------------------------------------------------------------

    private function initSyncStatus(int $userId): void
    {
        foreach (['ad', 'openldap', 'aurion'] as $cible) {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO sync_status (utilisateur_id, cible, etat) VALUES (?, ?, ?)'
            );
            $pending = 'pending';
            $stmt->bind_param('iss', $userId, $cible, $pending);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function markSynced(int $userId, string $cible): void
    {
        $sql = 'INSERT INTO sync_status (utilisateur_id, cible, etat, last_sync_at, last_error)
                VALUES (?, ?, "synced", NOW(), NULL)
                ON DUPLICATE KEY UPDATE etat = "synced", last_sync_at = NOW(), last_error = NULL, retry_count = 0';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('is', $userId, $cible);
        $stmt->execute();
        $stmt->close();
    }

    public function markSyncError(int $userId, string $cible, string $error): void
    {
        $sql = 'INSERT INTO sync_status (utilisateur_id, cible, etat, last_sync_at, last_error, retry_count)
                VALUES (?, ?, "error", NOW(), ?, 1)
                ON DUPLICATE KEY UPDATE etat = "error", last_sync_at = NOW(), last_error = ?, retry_count = retry_count + 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('isss', $userId, $cible, $error, $error);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Cible volontairement non applicable (ex: OpenLDAP pour un stagiaire/vacataire).
     * Distinct de "error" : pas de retry automatique côté cron.
     */
    public function markSkipped(int $userId, string $cible, string $raison = ''): void
    {
        $sql = 'INSERT INTO sync_status (utilisateur_id, cible, etat, last_sync_at, last_error, retry_count)
                VALUES (?, ?, "skipped", NOW(), ?, 0)
                ON DUPLICATE KEY UPDATE etat = "skipped", last_sync_at = NOW(), last_error = ?, retry_count = 0';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('isss', $userId, $cible, $raison, $raison);
        $stmt->execute();
        $stmt->close();
    }

    public function markAllPending(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE sync_status SET etat = 'pending' WHERE utilisateur_id = ?"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function getSyncStatus(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sync_status WHERE utilisateur_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[$row['cible']] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function getPendingSync(?string $cible = null): array
    {
        if ($cible) {
            $stmt = $this->db->prepare(
                "SELECT u.*, ss.cible, ss.retry_count
                 FROM utilisateurs u
                 JOIN sync_status ss ON ss.utilisateur_id = u.id
                 WHERE ss.etat = 'pending' AND ss.cible = ?
                 ORDER BY u.created_at ASC LIMIT 50"
            );
            $stmt->bind_param('s', $cible);
        } else {
            $stmt = $this->db->prepare(
                "SELECT u.*, ss.cible, ss.retry_count
                 FROM utilisateurs u
                 JOIN sync_status ss ON ss.utilisateur_id = u.id
                 WHERE ss.etat = 'pending'
                 ORDER BY u.created_at ASC LIMIT 50"
            );
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    // ----------------------------------------------------------------
    // AUDIT
    // ----------------------------------------------------------------

    public function audit(?int $userId, string $action, ?string $cible = null, ?string $details = null, ?string $operateur = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (utilisateur_id, action, cible, details, ip_address, operateur) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $action, $cible, $details, $ip, $operateur);
        $stmt->execute();
        $stmt->close();
    }

    // ----------------------------------------------------------------
    // GROUPES
    // ----------------------------------------------------------------

    public function getGroupes(): array
    {
        $result = $this->db->query('SELECT * FROM groupes_ad WHERE actif = 1 ORDER BY nom');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function addGroupe(int $userId, int $groupeId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO utilisateur_groupes (utilisateur_id, groupe_id) VALUES (?, ?)'
        );
        $stmt->bind_param('ii', $userId, $groupeId);
        $stmt->execute();
        $stmt->close();
    }

    public function getUserGroupes(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT g.* FROM groupes_ad g
             JOIN utilisateur_groupes ug ON ug.groupe_id = g.id
             WHERE ug.utilisateur_id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
