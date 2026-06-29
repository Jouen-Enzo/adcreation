<?php
/**
 * AurionBadgeSync.php
 * Traduction PHP du programme Java SynchAurion-Ldap.
 * Synchronise les attributs de badges depuis Aurion → OpenLDAP.
 *
 * Attributs mis à jour :
 *   uidNumber                         ← codebasemetier_2
 *   supannEtuId   (étudiants)         ← codebasemetier_2
 *   supannEmpId   (personnel)         ← codebasemetier_2
 *   supannCodeINE (étudiants)         ← ine
 *   employeeNumber                    ← employeeNumber
 *   schacExpiryDate (étudiants)       ← datefindroits  (format YYYYMMDD000000Z)
 *   supannEntiteAffectationPrincipale ← etapelibelle   (étudiants)
 */

require_once __DIR__ . '/config_ldap.php';
require_once __DIR__ . '/Logging.php';

class AurionBadgeSync
{
    /** Résultats de la dernière exécution */
    private array $log     = [];
    private int   $updated = 0;
    private int   $skipped = 0;
    private int   $errors  = 0;

    // ──────────────────────────────────────────────────────────────
    // Point d'entrée principal
    // ──────────────────────────────────────────────────────────────

    public function run(): array
    {
        $this->log     = [];
        $this->updated = 0;
        $this->skipped = 0;
        $this->errors  = 0;

        $this->info("Démarrage sync badges Aurion → OpenLDAP");

        // 1. Récupération Aurion
        $aurionEleves    = $this->fetchAurion(AURION_URL_ELEVES,    'élèves');
        $aurionPersonnel = $this->fetchAurion(AURION_URL_PERSONNEL, 'personnel');
        $aurionAll       = array_merge($aurionEleves, $aurionPersonnel);

        if (empty($aurionAll)) {
            $this->error("Aucune donnée reçue d'Aurion — abandon.");
            return $this->result();
        }

        $this->info(count($aurionAll) . " personnes reçues d'Aurion (" . count($aurionEleves) . " élèves + " . count($aurionPersonnel) . " personnel)");

        // Index Aurion par login pour accès O(1)
        $aurionByLogin = [];
        foreach ($aurionAll as $p) {
            $aurionByLogin[$p['login']] = $p;
        }

        // 2. Lecture OpenLDAP
        $ldapConn = $this->connectLdap();
        if (!$ldapConn) return $this->result();

        $ldapPeople = $this->fetchLdap($ldapConn);
        $this->info(count($ldapPeople) . " entrées lues dans OpenLDAP");

        // 3. Comparaison et mise à jour
        foreach ($ldapPeople as $person) {
            $uid = $person['uid'];
            if (!isset($aurionByLogin[$uid])) {
                $this->skipped++;
                continue;
            }

            $aurion      = $aurionByLogin[$uid];
            $affiliation = $person['eduPersonPrimaryAffiliation'] ?? '';
            $isStudent   = ($affiliation === 'student');
            $isEmployee  = ($affiliation === 'employee');

            $mods = [];

            // uidNumber ← codebasemetier_2
            if (!empty($aurion['codebasemetier_2'])
                && $aurion['codebasemetier_2'] !== ($person['uidNumber'] ?? '')) {
                $mods['uidNumber'] = $aurion['codebasemetier_2'];
            }

            // supannEtuId / supannEmpId ← codebasemetier_2
            if ($isStudent && !empty($aurion['codebasemetier_2'])
                && $aurion['codebasemetier_2'] !== ($person['supannEtuId'] ?? '')) {
                $mods['supannEtuId'] = $aurion['codebasemetier_2'];
            }
            if ($isEmployee && !empty($aurion['codebasemetier_2'])
                && $aurion['codebasemetier_2'] !== ($person['supannEmpId'] ?? '')) {
                $mods['supannEmpId'] = $aurion['codebasemetier_2'];
            }

            // supannCodeINE ← ine (étudiants uniquement)
            if ($isStudent) {
                $ine = !empty($aurion['ine']) ? $aurion['ine'] : '00000000000';
                if ($ine !== ($person['supannCodeINE'] ?? '')) {
                    $mods['supannCodeINE'] = $ine;
                }
            }

            // employeeNumber ← employeeNumber Aurion
            if (!empty($aurion['employeeNumber'])
                && $aurion['employeeNumber'] !== ($person['employeeNumber'] ?? '')) {
                $mods['employeeNumber'] = $aurion['employeeNumber'];
            }

            // schacExpiryDate ← datefindroits (étudiants, format YYYYMMDD000000Z)
            if ($isStudent && !empty($aurion['datefindroits'])) {
                $expiry = $this->formatExpiry($aurion['datefindroits']);
                if ($expiry !== ($person['schacExpiryDate'] ?? '')) {
                    $mods['schacExpiryDate'] = $expiry;
                }
            }

            // supannEntiteAffectationPrincipale ← etapelibelle (étudiants)
            if ($isStudent && !empty($aurion['etapelibelle'])
                && $aurion['etapelibelle'] !== ($person['supannEntiteAffectationPrincipale'] ?? '')) {
                $mods['supannEntiteAffectationPrincipale'] = $aurion['etapelibelle'];
            }

            if (empty($mods)) {
                $this->skipped++;
                continue;
            }

            // Écriture dans l'OpenLDAP
            if (@ldap_mod_replace($ldapConn, $person['dn'], $mods)) {
                $this->updated++;
                $attrs = implode(', ', array_keys($mods));
                $this->info("✓ $uid — $attrs");
                Logging::log("[BadgeSync] $uid mis à jour : $attrs");
            } else {
                $this->errors++;
                $err = ldap_error($ldapConn);
                $this->error("✗ $uid — ldap_mod_replace : $err");
                Logging::log("[BadgeSync] ERREUR $uid : $err", 'ERROR');
            }
        }

        ldap_unbind($ldapConn);
        $this->info("Terminé — {$this->updated} mis à jour, {$this->skipped} inchangés, {$this->errors} erreurs");

        return $this->result();
    }

    // ──────────────────────────────────────────────────────────────
    // Récupération Aurion (XML via HTTP)
    // ──────────────────────────────────────────────────────────────

    private function fetchAurion(string $url, string $label): array
    {
        $this->info("Connexion Aurion ($label)…");
        $ctx = stream_context_create(['http' => ['timeout' => 30]]);
        $xml = @file_get_contents($url, false, $ctx);

        if ($xml === false) {
            $this->error("Impossible de contacter Aurion ($label) : $url");
            return [];
        }

        try {
            $doc = new SimpleXMLElement($xml);
        } catch (Exception $e) {
            $this->error("Parsing XML Aurion ($label) : " . $e->getMessage());
            return [];
        }

        $people = [];
        foreach ($doc->xpath('//row') as $row) {
            $login = trim((string)($row->{'login.Individu'} ?? $row->login ?? ''));
            if (empty($login)) continue;

            $people[] = [
                'login'           => $login,
                'codebasemetier_2'=> trim((string)($row->codebasemetier_2 ?? '')),
                'ine'             => trim((string)($row->ine ?? '')),
                'employeeNumber'  => trim((string)($row->employeeNumber ?? '')),
                'nomsurcarte'     => trim((string)($row->nomsurcarte ?? '')),
                'datefindroits'   => trim((string)($row->datefindroits ?? '')),
                'etapelibelle'    => trim((string)($row->etapelibelle ?? '')),
            ];
        }

        $this->info(count($people) . " $label reçus");
        return $people;
    }

    // ──────────────────────────────────────────────────────────────
    // Connexion OpenLDAP badges
    // ──────────────────────────────────────────────────────────────

    private function connectLdap()
    {
        $host = OPENLDAP_BADGES_HOST;
        $uri  = (str_starts_with($host, 'ldap') ? $host : "ldap://$host")
              . ':' . OPENLDAP_BADGES_PORT;

        $conn = @ldap_connect($uri);
        if (!$conn) { $this->error("ldap_connect échoué : $uri"); return null; }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if (!@ldap_bind($conn, OPENLDAP_BADGES_ADMIN_DN, OPENLDAP_BADGES_PASSWORD)) {
            $this->error("Bind OpenLDAP badges échoué : " . ldap_error($conn));
            return null;
        }

        $this->info("Connecté à OpenLDAP badges (" . OPENLDAP_BADGES_BASE_DN . ")");
        return $conn;
    }

    // ──────────────────────────────────────────────────────────────
    // Lecture de toutes les entrées OpenLDAP ayant un employeeNumber
    // (fidèle à la logique Java : filtre sur objectClass=organizationalPerson
    //  + présence employeeNumber)
    // ──────────────────────────────────────────────────────────────

    private function fetchLdap($conn): array
    {
        $attrs = [
            'dn', 'uid', 'uidNumber', 'cn',
            'eduPersonPrimaryAffiliation',
            'employeeNumber',
            'supannEtuId', 'supannEmpId',
            'supannCodeINE',
            'schacExpiryDate',
            'supannEntiteAffectationPrincipale',
        ];

        $sr = @ldap_search($conn, OPENLDAP_BADGES_BASE_DN,
            '(&(objectClass=organizationalPerson)(employeeNumber=*))',
            $attrs, 0, 0, 30);

        if (!$sr) {
            $this->error("ldap_search échoué : " . ldap_error($conn));
            return [];
        }

        $entries = ldap_get_entries($conn, $sr);
        $people  = [];

        for ($i = 0; $i < $entries['count']; $i++) {
            $e = $entries[$i];
            $people[] = [
                'dn'                               => $e['dn'],
                'uid'                              => $e['uid'][0]            ?? '',
                'uidNumber'                        => $e['uidnumber'][0]      ?? '',
                'eduPersonPrimaryAffiliation'      => $e['edupersonprimaryaffiliation'][0] ?? '',
                'employeeNumber'                   => $e['employeenumber'][0] ?? '',
                'supannEtuId'                      => $e['supannetuid'][0]    ?? '',
                'supannEmpId'                      => $e['supannempid'][0]    ?? '',
                'supannCodeINE'                    => $e['supanncodeine'][0]  ?? '',
                'schacExpiryDate'                  => $e['schacexpirydate'][0] ?? '',
                'supannEntiteAffectationPrincipale'=> $e['supannentiteaffectationprincipale'][0] ?? '',
            ];
        }

        return $people;
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /** Convertit "YYYY-MM-DD" → "YYYYMMDD000000Z" (format schacExpiryDate) */
    private function formatExpiry(string $date): string
    {
        return str_replace('-', '', $date) . '000000Z';
    }

    private function info(string $msg):  void { $this->log[] = ['level' => 'info',  'msg' => $msg]; }
    private function error(string $msg): void { $this->log[] = ['level' => 'error', 'msg' => $msg]; }

    private function result(): array
    {
        return [
            'log'     => $this->log,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors'  => $this->errors,
        ];
    }
}
