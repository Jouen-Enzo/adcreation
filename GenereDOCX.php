<?php
/**
 * Classe GenereDOCX - Génération de documents DOCX
 * Utilise PHPWord pour la génération
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

class GenereDOCX {
    private static $outputDir = __DIR__ . "/documents";
    
    /**
     * Génère un document DOCX pour un utilisateur
     */
    public static function generateUserDoc($nom, $prenom, $login, $password, $promo, $options = array()) {
        try {
            if (!is_dir(self::$outputDir)) {
                mkdir(self::$outputDir, 0755, true);
            }
            
            $phpWord = new PhpWord();
            
            // Section et styles
            $section = $phpWord->addSection();
            $phpWord->addParagraphStyle('Title', array(
                'name' => 'Arial',
                'size' => 16,
                'bold' => true
            ));
            
            $phpWord->addParagraphStyle('Normal', array(
                'name' => 'Arial',
                'size' => 11
            ));
            
            // Titre
            $section->addText('Fiche Utilisateur ESIGELEC', 'Title');
            $section->addText('');
            
            // Informations personnelles
            $section->addText('Informations Personnelles:', 'Title');
            $table = $section->addTable();
            
            $table->addRow();
            $table->addCell(2000)->addText('Nom:');
            $table->addCell(4000)->addText($nom);
            
            $table->addRow();
            $table->addCell(2000)->addText('Prénom:');
            $table->addCell(4000)->addText($prenom);
            
            $table->addRow();
            $table->addCell(2000)->addText('Promotion:');
            $table->addCell(4000)->addText($promo);
            
            $section->addText('');
            
            // Accès utilisateur
            $section->addText('Accès Utilisateur:', 'Title');
            $table = $section->addTable();
            
            $table->addRow();
            $table->addCell(2000)->addText('Login:');
            $table->addCell(4000)->addText($login);
            
            $table->addRow();
            $table->addCell(2000)->addText('Mot de passe:');
            $table->addCell(4000)->addText($password);
            
            $section->addText('');
            $section->addText('⚠️ Important: Changez votre mot de passe lors de votre première connexion.');
            
            // Détails supplémentaires si disponibles
            if (isset($options['email'])) {
                $section->addText('');
                $section->addText('Email: ' . $options['email']);
            }
            
            if (isset($options['expiration'])) {
                $section->addText('Validité du compte: ' . $options['expiration']);
            }
            
            // Date de création
            $section->addText('');
            $section->addText('Document généré le: ' . date('d/m/Y à H:i'), 'Normal');
            
            // Sauvegarde du fichier
            $filename = "fiche_" . $login . "_" . date('YmdHis') . ".docx";
            $filepath = self::$outputDir . "/" . $filename;
            
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($filepath);
            
            Logging::log("Document DOCX généré: $filename");
            return $filepath;
        } catch (Exception $e) {
            Logging::log("Erreur génération DOCX: " . $e->getMessage(), "ERROR");
            return null;
        }
    }
    
    /**
     * Génère des documents pour plusieurs utilisateurs
     */
    public static function generateBatchDocs($users) {
        $results = array();
        
        foreach ($users as $user) {
            try {
                $docFile = self::generateUserDoc(
                    $user['nom'],
                    $user['prenom'],
                    $user['login'],
                    $user['password'],
                    $user['promo'],
                    $user
                );
                
                $results[] = array(
                    'login' => $user['login'],
                    'file' => $docFile,
                    'status' => 'success'
                );
            } catch (Exception $e) {
                $results[] = array(
                    'login' => $user['login'],
                    'error' => $e->getMessage(),
                    'status' => 'failed'
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Récupère les documents générés
     */
    public static function getDocuments($limit = 50) {
        $files = array();
        
        if (is_dir(self::$outputDir)) {
            $dir = new DirectoryIterator(self::$outputDir);
            
            foreach ($dir as $file) {
                if ($file->isFile() && $file->getExtension() === 'docx') {
                    $files[] = array(
                        'name' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size' => $file->getSize(),
                        'date' => date('Y-m-d H:i:s', $file->getMTime())
                    );
                }
            }
        }
        
        // Trie par date décroissante
        usort($files, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return array_slice($files, 0, $limit);
    }
}
?>
