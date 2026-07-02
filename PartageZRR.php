<?php
require_once __DIR__ . '/Logging.php';

class PartageZRR
{
    /** URL de base de l'API Partage */
    private string $apiUrl = 'https://api.partage.renater.fr/service/domain';

    /** Token d'authentification obtenu après Auth, valable ~1h */
    private ?string $token = null;

    // ──────────────────────────────────────────────────────────────
    // Point d'entrée principal
    // ──────────────────────────────────────────────────────────────

    /**
     * Crée une boîte mail ZRR via l'API Partage.
     *
     * @param string $prenom   Prénom de l'utilisateur
     * @param string $nom      Nom de famille
     * @param string $email    Adresse mail complète (ex: p.dupont@zrr.esigelec.fr)
     * @param string $password Mot de passe en clair (même que celui créé dans l'AD)
     * @return array           ['success' => bool, 'message' => string]
     */
    public function creerBoiteMailZRR(string $prenom, string $nom, string $email, string $password): array
    {
        // Vérification de la configuration
        if (!defined('PARTAGE_DOMAIN') || empty(PARTAGE_DOMAIN)) {
            return ['success' => false, 'message' => 'PARTAGE_DOMAIN non configuré dans config_ldap.php'];
        }
        if (!defined('PARTAGE_PREAUTH_KEY') || empty(PARTAGE_PREAUTH_KEY)) {
            return ['success' => false, 'message' => 'PARTAGE_PREAUTH_KEY non configurée dans config_ldap.php'];
        }

        try {
            // 1. S'authentifier pour obtenir un token
            $this->token = $this->authentifier();

            // 2. Créer le compte
            $resultat = $this->createAccount($email, $password, $prenom, $nom);

            Logging::log("[ZRR] Boîte mail créée : $email");
            return ['success' => true, 'message' => "Boîte mail ZRR créée : $email"];

        } catch (Exception $e) {
            Logging::log("[ZRR] Erreur création boîte mail $email : " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'message' => 'Erreur ZRR : ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Authentification (section 3 de la doc API)
    // ──────────────────────────────────────────────────────────────

    /**
     * Génère le token d'authentification.
     *
     * Le mécanisme utilise un HMAC-SHA1 calculé à partir du nom de domaine,
     * du timestamp courant et de la clé partagée (PARTAGE_PREAUTH_KEY).
     *
     * preauth = HMAC-SHA1("domain.com|timestamp", preAuthKey)
     *
     * @return string Token d'authentification
     * @throws Exception si l'authentification échoue
     */
    private function authentifier(): string
    {
        $domain    = PARTAGE_DOMAIN;
        $preauthKey = PARTAGE_PREAUTH_KEY;
        $timestamp = time();

        // Construction de la signature HMAC-SHA1
        $data    = $domain . '|' . $timestamp;
        $preauth = hash_hmac('sha1', $data, $preauthKey);

        // Appel à l'API
        $url = $this->apiUrl . '/Auth'
             . '?domain='    . urlencode($domain)
             . '&timestamp=' . $timestamp
             . '&preauth='   . $preauth;

        $reponse = $this->appelerAPI($url);

        // Vérification du statut (0 = succès d'après la doc)
        if ((int)($reponse['status'] ?? -1) !== 0) {
            throw new Exception('Authentification Partage échouée : ' . ($reponse['message'] ?? 'erreur inconnue'));
        }

        if (empty($reponse['token'])) {
            throw new Exception('Token Partage absent de la réponse');
        }

        return (string)$reponse['token'];
    }

    // ──────────────────────────────────────────────────────────────
    // Création de compte (section 4.4 de la doc API)
    // ──────────────────────────────────────────────────────────────

    /**
     * Appelle CreateAccount sur l'API Partage.
     *
     * Paramètres obligatoires : name (email) et password.
     * Paramètres optionnels passés ici : givenName, sn, displayName, company.
     *
     *  À COMPLÉTER selon les besoins :
     *   - zimbraCOSId       : ID de la classe de service ZRR (à récupérer via GetAllCos)
     *   - zimbraMailQuota   : quota en octets (0 = illimité)
     *   - zimbraHideInGal   : masquer dans l'annuaire global (TRUE/FALSE)
     *
     * @param string $email    Adresse mail complète
     * @param string $password Mot de passe en clair
     * @param string $prenom   Prénom
     * @param string $nom      Nom de famille
     * @return array           Réponse de l'API
     * @throws Exception si la création échoue
     */
    private function createAccount(string $email, string $password, string $prenom, string $nom): array
    {
        $url = $this->apiUrl . '/CreateAccount/' . urlencode($this->token)
             . '?name='        . urlencode($email)
             . '&password='    . urlencode($password)
             . '&givenName='   . urlencode($prenom)
             . '&sn='          . urlencode($nom)
             . '&displayName=' . urlencode("$prenom $nom")
             . '&company='     . urlencode('ESIGELEC')
             // ↓ À configurer selon la COS ZRR fournie par RENATER
             // . '&zimbraCOSId=' . urlencode(PARTAGE_COS_ID_ZRR)
             // . '&zimbraMailQuota=0'   // 0 = illimité
             // . '&zimbraHideInGal=FALSE'
             ;

        $reponse = $this->appelerAPI($url);

        if ((int)($reponse['status'] ?? -1) !== 0) {
            throw new Exception('CreateAccount Partage échoué : ' . ($reponse['message'] ?? 'erreur inconnue'));
        }

        return $reponse;
    }

    // ──────────────────────────────────────────────────────────────
    // Appel HTTP générique
    // ──────────────────────────────────────────────────────────────

    /**
     * Effectue un appel GET à l'API et retourne la réponse parsée (XML → tableau).
     *
     * L'API retourne du XML, par exemple :
     *   <Response>
     *     <status>0</status>
     *     <message>Opération réalisée avec succès !</message>
     *     <Token>abc123...</Token>
     *   </Response>
     *
     * @param string $url URL complète de l'appel API
     * @return array      Tableau associatif issu du XML
     * @throws Exception  Si la requête HTTP échoue ou le XML est invalide
     */
    private function appelerAPI(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                // ⚠ En production, laisser verify_peer à true
                // et s'assurer que le certificat RENATER est valide.
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $reponseXml = @file_get_contents($url, false, $ctx);

        if ($reponseXml === false) {
            throw new Exception("Impossible de joindre l'API Partage : $url");
        }

        // Parse du XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($reponseXml);

        if ($xml === false) {
            $erreurs = libxml_get_errors();
            throw new Exception('Réponse XML Partage invalide : ' . ($erreurs[0]->message ?? 'parsing error'));
        }

        // Conversion XML → tableau associatif (1 niveau, suffit pour les réponses Partage)
        $tableau = [];
        foreach ($xml->children() as $enfant) {
            $tableau[strtolower($enfant->getName())] = (string)$enfant;
        }

        return $tableau;
    }
}
?>
