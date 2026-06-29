<?php
/**
 * Classe EnvoiMail - Gestion de l'envoi d'emails
 * Utilise PHPMailer pour la compatibilité
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EnvoiMail {
    private static $mailHost = "mail.esigelec.fr";
    private static $mailFrom = "noreply@esigelec.fr";
    private static $mailPort = 25;
    
    /**
     * Envoie un email de bienvenue
     */
    public static function sendWelcomeEmail($toEmail, $login, $password, $nom, $prenom, $docFile = null) {
        try {
            $mail = new PHPMailer(true);
            
            $mail->isSMTP();
            $mail->Host = self::$mailHost;
            $mail->Port = self::$mailPort;
            $mail->setFrom(self::$mailFrom, 'ESIGELEC - Gestion Comptes');
            $mail->addAddress($toEmail, "$prenom $nom");
            
            $mail->isHTML(true);
            $mail->Subject = "Bienvenue à ESIGELEC - Accès à votre compte";
            
            $body = self::getWelcomeEmailTemplate($login, $password, $nom, $prenom);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            // Ajoute le document s'il existe
            if ($docFile && file_exists($docFile)) {
                $mail->addAttachment($docFile);
            }
            
            if ($mail->send()) {
                Logging::log("Email envoyé à: $toEmail ($login)");
                return true;
            } else {
                throw new Exception("Erreur envoi email: " . $mail->ErrorInfo);
            }
        } catch (Exception $e) {
            Logging::log("Erreur envoi email: " . $e->getMessage(), "ERROR");
            return false;
        }
    }
    
    /**
     * Envoie un email en masse (création batch)
     */
    public static function sendBatchEmails($users) {
        $results = array(
            "success" => 0,
            "failed" => 0,
            "errors" => array()
        );
        
        foreach ($users as $user) {
            try {
                if (self::sendWelcomeEmail(
                    $user['email'],
                    $user['login'],
                    $user['password'],
                    $user['nom'],
                    $user['prenom'],
                    $user['docFile'] ?? null
                )) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = $user['login'] . ": " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Génère le template HTML de l'email de bienvenue
     */
    private static function getWelcomeEmailTemplate($login, $password, $nom, $prenom) {
        return <<<EOT
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background-color: #003366; color: white; padding: 20px; }
                .content { padding: 20px; }
                .footer { background-color: #f0f0f0; padding: 10px; text-align: center; }
                .credentials { background-color: #e8f4f8; padding: 10px; border-left: 4px solid #003366; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Bienvenue à ESIGELEC</h1>
            </div>
            <div class="content">
                <p>Bonjour $prenom $nom,</p>
                <p>Votre compte utilisateur a été créé avec succès.</p>
                <p>Veuillez conserver précieusement vos identifiants :</p>
                <div class="credentials">
                    <p><strong>Login :</strong> <code>$login</code></p>
                    <p><strong>Mot de passe :</strong> <code>$password</code></p>
                    <p><small>⚠️ Changez votre mot de passe lors de votre première connexion.</small></p>
                </div>
                <p>Vous trouverez ci-joint votre fiche utilisateur.</p>
                <p>Pour toute question, contactez le service informatique.</p>
            </div>
            <div class="footer">
                <p>ESIGELEC - École d'ingénieurs généralistes</p>
            </div>
        </body>
        </html>
        EOT;
    }
}
?>
