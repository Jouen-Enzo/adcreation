<?php
/**
 * HomeDirectoryManager — Création du répertoire personnel d'un utilisateur permanent
 *
 * Deux méthodes disponibles, configurées via PERMANENT_HOME_DIR_METHOD dans config.php :
 *
 *   'php'        → mkdir() PHP natif.
 *                  Prérequis : Apache/PHP tourne sous un compte domaine avec droits Write
 *                  sur le partage. Rapide mais sans gestion de propriété NTFS.
 *
 *   'powershell' → Script PowerShell exécuté via exec() avec les credentials AD admin.
 *                  Crée le dossier, définit l'utilisateur comme propriétaire NTFS,
 *                  lui donne FullControl en ACE explicite, coupe l'héritage.
 *                  Recommandé sur WAMP où PHP tourne sous SYSTEM/LocalService.
 *
 * Architecture cible :
 *   - Machine WAMP (PHP/Apache) ≠ machine AD/fichiers
 *   - Le partage \\192.168.21.131\partage correspond à C:\partage sur le serveur AD
 *   - PowerShell tourne sur la machine WAMP et accède au partage via réseau
 *   - SYSTEM/LocalService n'ont pas de droits réseau → on utilise Invoke-Command
 *     en loopback avec -Credential pour exécuter le bloc sous le compte admin AD
 *
 * Usage :
 *   HomeDirectoryManager::create($login, $homePath);
 */

require_once __DIR__ . '/Logging.php';
require_once __DIR__ . '/config.php';

class HomeDirectoryManager
{
    /**
     * Crée le répertoire personnel.
     * Tente la méthode configurée, puis l'autre en fallback si ça échoue.
     * Non bloquant : log l'erreur mais ne lève pas d'exception.
     */
    public static function create(string $login, string $homePath): bool
    {
        $method = defined('PERMANENT_HOME_DIR_METHOD') ? PERMANENT_HOME_DIR_METHOD : 'php';

        if ($method === 'powershell') {
            $ok = self::createViaPowerShell($login, $homePath);
            if (!$ok) {
                Logging::log("[HomeDir] PowerShell échoué — tentative PHP fallback pour $homePath", 'WARNING');
                $ok = self::createViaPHP($login, $homePath);
            }
        } else {
            $ok = self::createViaPHP($login, $homePath);
            if (!$ok) {
                Logging::log("[HomeDir] PHP mkdir échoué — tentative PowerShell fallback pour $homePath", 'WARNING');
                $ok = self::createViaPowerShell($login, $homePath);
            }
        }

        return $ok;
    }

    // ================================================================
    // MÉTHODE PHP
    // ================================================================

    private static function createViaPHP(string $login, string $homePath): bool
    {
        if (is_dir($homePath)) {
            Logging::log("[HomeDir][PHP] Dossier déjà existant : $homePath");
            return true;
        }

        if (@mkdir($homePath, 0755, true)) {
            Logging::log("[HomeDir][PHP] Dossier créé : $homePath");
            return true;
        }

        $err = error_get_last()['message'] ?? 'erreur inconnue';
        Logging::log("[HomeDir][PHP] Échec mkdir($homePath) : $err", 'WARNING');
        return false;
    }

    // ================================================================
    // MÉTHODE POWERSHELL
    // ================================================================

    private static function createViaPowerShell(string $login, string $homePath): bool
    {
        // Contexte : PHP tourne sous SYSTEM/LocalService sur la machine WAMP,
        // qui est DIFFÉRENTE du serveur de fichiers (\\192.168.21.131\partage).
        // SYSTEM n'a aucun droit réseau → net use échoue avec l'erreur 55.
        //
        // Solution : Invoke-Command en loopback (-ComputerName localhost) avec
        // -Credential force l'exécution du bloc sous le compte admin AD.
        // Ce compte a les droits sur le partage réseau, même depuis la machine WAMP.
        //
        // Le ScriptBlock reçoit les variables via -ArgumentList pour éviter
        // tout problème d'injection ou d'échappement dans la here-string.

        $psPath     = self::toPsSingleQuote($homePath);
        $psLogin    = self::toPsSingleQuote($login);
        $psAdminUpn = self::toPsSingleQuote(AD_ADMIN_UPN);
        $psAdminPwd = self::toPsDoubleQuote(AD_ADMIN_PASSWORD);
        $psDomain   = self::toPsSingleQuote(AD_DOMAIN);
        // Nom NetBIOS du domaine (avant le premier point, ex: "test" pour test.local)
        $psNetbios  = self::toPsSingleQuote(explode('.', AD_DOMAIN)[0]);

        // Note sur Invoke-Command -ComputerName localhost :
        // Cela nécessite que WinRM soit actif sur la machine WAMP.
        // Si WinRM est désactivé, le script tombe en fallback sur Start-Process
        // (voir section FALLBACK ci-dessous dans le script PS).

        $script = <<<PS
\$ErrorActionPreference = "Stop"

# ── Credentials admin AD ─────────────────────────────────────────────
\$adminPwd  = ConvertTo-SecureString "$psAdminPwd" -AsPlainText -Force
\$cred      = New-Object System.Management.Automation.PSCredential($psAdminUpn, \$adminPwd)

\$sharePath = $psPath
\$login     = $psLogin
\$domain    = $psDomain
\$netbios   = $psNetbios

# ── Fonction interne : créer le dossier + poser les ACL ──────────────
# Encapsulée dans un ScriptBlock pour pouvoir être appelée soit via
# Invoke-Command (avec credential), soit directement si on est déjà admin.
\$createBlock = {
    param(\$sharePath, \$login, \$domain, \$netbios, \$cred)

    \$ErrorActionPreference = "Continue"

    # Créer le dossier s'il n'existe pas
    if (-not (Test-Path \$sharePath)) {
        try {
            New-Item -ItemType Directory -Path \$sharePath -Force -ErrorAction Stop | Out-Null
            Write-Host "CREATED: \$sharePath"
        } catch {
            Write-Host "ERREUR New-Item: \$_"
            exit 1
        }
    } else {
        Write-Host "EXISTS: \$sharePath"
    }

    # ── Résolution SID de l'utilisateur ──────────────────────────────
    \$userAccount = \$null

    # Tentative 1 : module ActiveDirectory (RSAT)
    try {
        Import-Module ActiveDirectory -ErrorAction Stop
        \$adUser      = Get-ADUser -Identity \$login -Server \$domain -Credential \$cred -ErrorAction Stop
        \$userAccount = New-Object System.Security.Principal.SecurityIdentifier(\$adUser.SID)
        Write-Host "SID via AD module: \$(\$adUser.SID)"
    } catch {
        Write-Host "AD module indisponible : \$_"
    }

    # Tentative 2 : NTAccount (ne nécessite pas RSAT)
    if (-not \$userAccount) {
        try {
            \$ntAcc       = New-Object System.Security.Principal.NTAccount(\$netbios, \$login)
            \$userAccount = \$ntAcc.Translate([System.Security.Principal.SecurityIdentifier])
            Write-Host "SID via NTAccount: \$userAccount"
        } catch {
            Write-Host "NTAccount résolution échouée : \$_"
        }
    }

    if (-not \$userAccount) {
        Write-Host "ERREUR : impossible de résoudre le SID de \$login"
        exit 2
    }

    # ── ACL : propriétaire + FullControl ─────────────────────────────
    try {
        \$acl = Get-Acl -Path \$sharePath -ErrorAction Stop
        \$acl.SetOwner(\$userAccount)
        \$acl.SetAccessRuleProtection(\$true, \$false)

        foreach (\$rule in @(\$acl.Access)) {
            \$acl.RemoveAccessRule(\$rule) | Out-Null
        }

        \$inherit   = [System.Security.AccessControl.InheritanceFlags]"ContainerInherit, ObjectInherit"
        \$propagate = [System.Security.AccessControl.PropagationFlags]::None
        \$allow     = [System.Security.AccessControl.AccessControlType]::Allow
        \$fc        = [System.Security.AccessControl.FileSystemRights]::FullControl

        # ACE 1 : utilisateur → FullControl
        \$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            \$userAccount, \$fc, \$inherit, \$propagate, \$allow
        )))

        # ACE 2 : SYSTEM → FullControl
        \$system = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-18")
        \$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            \$system, \$fc, \$inherit, \$propagate, \$allow
        )))

        # ACE 3 : Domain Admins → FullControl (ignoré si non trouvé)
        try {
            \$da = (New-Object System.Security.Principal.NTAccount(\$netbios, "Domain Admins")).Translate(
                [System.Security.Principal.SecurityIdentifier]
            )
            \$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
                \$da, \$fc, \$inherit, \$propagate, \$allow
            )))
        } catch { Write-Host "Domain Admins ACE ignoré : \$_" }

        Set-Acl -Path \$sharePath -AclObject \$acl -ErrorAction Stop
        Write-Host "ACL OK: proprietaire=\$login FullControl positionné"

    } catch {
        Write-Host "ERREUR ACL : \$_"
        # Non bloquant : le dossier existe, seule l'ACL a raté
    }

    Write-Host "DONE"
}

# ── Exécution via Invoke-Command -Credential (méthode principale) ────
# Force le ScriptBlock à tourner sous le compte admin AD, qui a les droits
# réseau sur \\192.168.21.131\partage — contrairement à SYSTEM/LocalService.
\$icmOk = \$false
try {
    Invoke-Command -ComputerName localhost -Credential \$cred -ScriptBlock \$createBlock `
        -ArgumentList \$sharePath, \$login, \$domain, \$netbios, \$cred -ErrorAction Stop
    \$icmOk = \$true
} catch {
    Write-Host "Invoke-Command échoué (WinRM indisponible ?) : \$_"
}

# ── FALLBACK : New-PSDrive avec credential ───────────────────────────
# Si WinRM n'est pas disponible, on monte un PSDrive temporaire avec les
# credentials admin, puis on crée le dossier via ce lecteur.
# New-PSDrive accepte -Credential contrairement à net use depuis SYSTEM.
if (-not \$icmOk) {
    Write-Host "Fallback New-PSDrive..."
    \$ErrorActionPreference = "Continue"

    \$shareRoot  = [System.IO.Path]::GetDirectoryName(\$sharePath)  # \\192.168.21.131\partage
    \$subFolder  = [System.IO.Path]::GetFileName(\$sharePath)        # login
    \$driveName  = "ADCTmp"

    # Supprimer si déjà existant
    if (Get-PSDrive -Name \$driveName -ErrorAction SilentlyContinue) {
        Remove-PSDrive -Name \$driveName -Force
    }

    try {
        New-PSDrive -Name \$driveName -PSProvider FileSystem -Root \$shareRoot `
            -Credential \$cred -Persist:\$false -ErrorAction Stop | Out-Null
        Write-Host "PSDrive monté sur \$shareRoot"

        \$drivePath = "\${driveName}:\\\$subFolder"

        if (-not (Test-Path \$drivePath)) {
            New-Item -ItemType Directory -Path \$drivePath -Force -ErrorAction Stop | Out-Null
            Write-Host "CREATED via PSDrive: \$drivePath"
        } else {
            Write-Host "EXISTS via PSDrive: \$drivePath"
        }

        # ACL via chemin UNC (Set-Acl fonctionne mieux en UNC qu'en PSDrive réseau)
        & \$createBlock \$sharePath \$login \$domain \$netbios \$cred

    } catch {
        Write-Host "ERREUR fallback PSDrive : \$_"
        if (Get-PSDrive -Name \$driveName -ErrorAction SilentlyContinue) {
            Remove-PSDrive -Name \$driveName -Force
        }
        exit 1
    }

    if (Get-PSDrive -Name \$driveName -ErrorAction SilentlyContinue) {
        Remove-PSDrive -Name \$driveName -Force
    }
}

exit 0
PS;

        return self::runPsScript($script, "homedir_$login");
    }

    // ================================================================
    // EXÉCUTION DU SCRIPT PS
    // ================================================================

    private static function runPsScript(string $script, string $tag): bool
    {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adcreation_' . $tag . '_' . uniqid() . '.ps1';
        // BOM UTF-8 requis par PowerShell sur certains Windows pour les accents
        file_put_contents($tmpFile, "\xEF\xBB\xBF" . $script);

        $cmd    = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $tmpFile . '" 2>&1';
        $output = [];
        $retval = 0;
        exec($cmd, $output, $retval);

        @unlink($tmpFile);

        $outputStr = implode("\n", $output);
        Logging::log("[HomeDir][PS][$tag] code=$retval | $outputStr");

        return $retval === 0;
    }

    // ================================================================
    // HELPERS D'ÉCHAPPEMENT POWERSHELL
    // ================================================================

    /**
     * Entoure d'apostrophes PS (single-quote).
     * En PS, les single-quotes sont littérales — seule ' doit être doublée en ''.
     * Idéal pour les chemins UNC et les valeurs sans variables.
     */
    private static function toPsSingleQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Échappe pour insertion dans une chaîne PS entre guillemets doubles.
     * Le backtick est le caractère d'échappement PS (`$`, `"`, etc.).
     * Utilisé uniquement pour le mot de passe qui va dans ConvertTo-SecureString.
     */
    private static function toPsDoubleQuote(string $value): string
    {
        $value = str_replace('`', '``', $value);
        $value = str_replace('"', '`"', $value);
        return $value;
    }
}
?>
