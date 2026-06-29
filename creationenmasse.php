<?php
/**
 * Création en Masse de Comptes
 */

session_start();
require_once 'Logging.php';
require_once 'vendor/autoload.php';

$loader = new \Twig\Loader\FilesystemLoader('templates');
$twig = new \Twig\Environment($loader);

$message = null;
$messageType = null;
$results = null;
$preview_students = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'preview';
        
        if ($action === 'import_xml' || $action === 'preview') {
            // Récupère les données XML depuis Aurion
            $favoriteId = $_POST['favorite_id'] ?? '1813866';
            $lecteur = new LecteurXml($favoriteId);
            $preview_students = $lecteur->fetchNewStudents();
            
            if (empty($preview_students)) {
                throw new Exception("Aucun apprenant trouvé dans les données XML");
            }
            
            if ($action === 'preview') {
                $message = "Aperçu: " . count($preview_students) . " apprenant(s) trouvé(s)";
                $messageType = 'info';
            }
        }
        
        if ($action === 'create_batch') {
            // Récupère les données JSON envoyées
            $jsonData = $_POST['students_json'] ?? '[]';
            $students = json_decode($jsonData, true);
            
            if (empty($students)) {
                throw new Exception("Aucun apprenant à créer");
            }
            
            // Options communes
            $commonOptions = array(
                'generate_docx' => isset($_POST['generate_docx']),
                'send_email' => isset($_POST['send_email']),
                'groups' => array()
            );
            
            // Crée tous les comptes
            $compte = new CompteUtilisateur();
            $results = array(
                'success' => 0,
                'failed' => 0,
                'created' => array(),
                'errors' => array()
            );
            
            foreach ($students as $student) {
                try {
                    $options = array_merge($commonOptions, array(
                        'email' => $student['email'] ?? ''
                    ));
                    
                    $user = $compte->creerCompte(
                        $student['nom'],
                        $student['prenom'],
                        $student['promo'],
                        $options
                    );
                    
                    $results['success']++;
                    $results['created'][] = array(
                        'login' => $user['login'],
                        'nom' => $student['nom'],
                        'prenom' => $student['prenom']
                    );
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = $student['prenom'] . " " . $student['nom'] . ": " . $e->getMessage();
                }
            }
            
            $message = "Résultat: " . $results['success'] . " compte(s) créé(s), " . $results['failed'] . " erreur(s)";
            $messageType = $results['failed'] === 0 ? 'success' : 'warning';
        }
        
    } catch (Exception $e) {
        $message = "Erreur: " . $e->getMessage();
        $messageType = 'error';
        Logging::log("Erreur création batch: " . $e->getMessage(), "ERROR");
    }
}

$data = array(
    'title' => 'Création en Masse de Comptes',
    'current_page' => 'creationenmasse',
    'message' => $message,
    'messageType' => $messageType,
    'preview_students' => $preview_students,
    'results' => $results
);

echo $twig->render('creationenmasse.html.twig', $data);
?>
