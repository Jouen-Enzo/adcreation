-- ============================================================
-- ADCreation v3 — Schéma MySQL
-- Base de données centrale, source de vérité avant sync
-- Sync vers : Active Directory, OpenLDAP, Aurion
-- ============================================================

CREATE DATABASE IF NOT EXISTS adcreation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE adcreation;

-- ------------------------------------------------------------
-- Table principale : utilisateurs
-- Contient tous les champs AD standard + champs métier
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilisateurs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- === Identité (champs de base) ===
    nom              VARCHAR(100) NOT NULL,
    prenom           VARCHAR(100) NOT NULL,
    date_naissance   DATE         NULL,
    lieu_naissance   VARCHAR(100) NULL,
    genre            ENUM('M','F') NULL,
    nationalite      VARCHAR(100) NULL,

    -- === Compte AD ===
    login            VARCHAR(50)  NOT NULL UNIQUE,   -- sAMAccountName
    email            VARCHAR(200) NULL,              -- mail
    upn              VARCHAR(250) NULL,              -- userPrincipalName
    password_tmp     VARCHAR(255) NULL,              -- INUTILISÉ depuis le retrait du stockage du mdp en base (toujours vide, gardé pour compat schéma — voir SyncManager::syncToAD)
    user_type        ENUM('permanent','stagiaires','vacataire') NOT NULL,
    dn_ad            VARCHAR(500) NULL,              -- Distinguished Name dans l'AD
    account_enabled  TINYINT(1)   DEFAULT 0,

    -- === Coordonnées ===
    telephone        VARCHAR(50)  NULL,              -- telephoneNumber
    telephone_mobile VARCHAR(50)  NULL,              -- mobile
    fax              VARCHAR(50)  NULL,              -- facsimileTelephoneNumber

    -- === Organisation (champs avancés AD) ===
    titre            VARCHAR(100) NULL,              -- title
    poste            VARCHAR(100) NULL,              -- description du poste
    departement      VARCHAR(100) NULL,              -- department
    societe          VARCHAR(200) NULL,              -- company
    service          VARCHAR(100) NULL,              -- physicalDeliveryOfficeName → bureau
    manager_login    VARCHAR(50)  NULL,              -- manager (DN résolu au moment de la sync)

    -- === Adresse / Bureau ===
    bureau           VARCHAR(100) NULL,              -- physicalDeliveryOfficeName
    adresse_rue      VARCHAR(255) NULL,              -- streetAddress
    ville            VARCHAR(100) NULL,              -- l (localityName)
    code_postal      VARCHAR(20)  NULL,              -- postalCode
    etat_province    VARCHAR(100) NULL,              -- st
    pays             VARCHAR(100) DEFAULT 'FR',      -- c / co / countryCode

    -- === Profil réseau (champs AD avancés) ===
    home_drive       VARCHAR(5)   NULL,              -- homeDrive (ex: X:)
    home_directory   VARCHAR(255) NULL,              -- homeDirectory (\\srv\user)
    profile_path     VARCHAR(255) NULL,              -- profilePath
    logon_script     VARCHAR(255) NULL,              -- scriptPath

    -- === Expiration / Durée de vie ===
    date_expiration  DATE         NULL,              -- accountExpires
    date_debut       DATE         NULL DEFAULT (CURRENT_DATE),

    -- === Aurion ===
    aurion_id        VARCHAR(50)  NULL,              -- identifiant dans Aurion
    promo            VARCHAR(50)  NULL,              -- promotion (pour stagiaires)

    -- === Métadonnées ===
    created_by       VARCHAR(100) NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes            TEXT         NULL,

    INDEX idx_login      (login),
    INDEX idx_user_type  (user_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table de synchronisation — état par cible
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_status (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id  INT UNSIGNED NOT NULL,
    cible           ENUM('ad','openldap','aurion') NOT NULL,

    etat            ENUM('pending','synced','error','skipped') DEFAULT 'pending',
    last_sync_at    TIMESTAMP NULL,
    last_error      TEXT NULL,
    retry_count     TINYINT UNSIGNED DEFAULT 0,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_cible (utilisateur_id, cible),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    INDEX idx_etat (etat),
    INDEX idx_cible (cible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Journal d'audit complet
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id  INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,   -- CREATE, UPDATE, SYNC_AD, SYNC_LDAP, SYNC_AURION, DELETE
    cible           VARCHAR(50)  NULL,       -- ad / openldap / aurion / db
    details         TEXT         NULL,
    ip_address      VARCHAR(45)  NULL,
    operateur       VARCHAR(100) NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_utilisateur (utilisateur_id),
    INDEX idx_action      (action),
    INDEX idx_created_at  (created_at),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Groupes AD disponibles (référentiel)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS groupes_ad (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50)  NOT NULL UNIQUE,
    nom         VARCHAR(100) NOT NULL,
    dn          VARCHAR(500) NOT NULL,
    description VARCHAR(255) NULL,
    actif       TINYINT(1)   DEFAULT 1,
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Appartenance aux groupes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilisateur_groupes (
    utilisateur_id INT UNSIGNED NOT NULL,
    groupe_id      INT UNSIGNED NOT NULL,
    added_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (utilisateur_id, groupe_id),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    FOREIGN KEY (groupe_id)      REFERENCES groupes_ad(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Données initiales
-- ------------------------------------------------------------
INSERT IGNORE INTO groupes_ad (code, nom, dn, description) VALUES
('cpii',        'CPII',         'CN=CPII,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,DC=test,DC=local',        'Élèves CPII'),
('google',      'GOOGLE_APPS',  'CN=GOOGLE_APPS,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,DC=test,DC=local', 'Intégration Google Apps'),
('intervenant', 'INTERVENANTS', 'CN=INTERVENANTS,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,DC=test,DC=local','Intervenants'),
('irseem',      'IRSEEM',       'CN=IRSEEM,OU=ELEVES,OU=DISTRIBUTION,OU=GROUPES,DC=test,DC=local',      'IRSEEM');
