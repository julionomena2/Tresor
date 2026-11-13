-- =====================================================================
-- BASE DE DONNEES : garde_antsirabe_db        (VERSION 2)
-- Application de gestion des gardes et indemnités - 45 TG Antsirabe
--
-- >>> POUR UNE INSTALLATION NEUVE UNIQUEMENT <<<
-- (si ta base existe déjà en version 1, utilise sql/migration_v2.sql)
--
-- A EXECUTER DANS phpMyAdmin (onglet "SQL")
-- =====================================================================

CREATE DATABASE IF NOT EXISTS garde_antsirabe_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE garde_antsirabe_db;

-- ---------------------------------------------------------------------
-- Table : agents  (feuille "IDENTIFICATION" du fichier Excel)
-- Matricule = clé primaire saisie MANUELLEMENT (pas d'auto-incrément).
-- ---------------------------------------------------------------------
CREATE TABLE agents (
    matricule      VARCHAR(20)  NOT NULL COMMENT 'Matricule agent, ex: 391.082 - saisi manuellement',
    nom_prenoms    VARCHAR(150) NOT NULL COMMENT 'NOM ET PRENOMS de l agent',
    statut         ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    date_creation  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (matricule)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table : utilisateurs  (NOUVEAU en v2 - remplace l'ancienne table "admin")
-- Contient l'administrateur ET les utilisateurs (agents de pointage).
--   role   : 'admin' (gère les données + utilisateurs) ou 'utilisateur' (pointage)
--   statut : 'en_attente' (inscrit, pas encore validé par l'admin)
--            'actif'      (peut se connecter)
--            'refuse'     (inscription refusée)
--            'suspendu'   (compte bloqué par l'admin)
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Identifiant de connexion',
    password         VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt (password_hash), jamais en clair',
    nom_complet      VARCHAR(100) NOT NULL,
    role             ENUM('admin','utilisateur') NOT NULL DEFAULT 'utilisateur',
    statut           ENUM('en_attente','actif','refuse','suspendu') NOT NULL DEFAULT 'en_attente',
    date_inscription TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_validation  DATETIME NULL COMMENT 'Date de la dernière activation par l admin',
    valide_par       INT NULL COMMENT 'Id de l admin ayant pris la dernière décision'
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table : pointages  (une ligne = une garde de 24h)
-- saisi_par = utilisateur qui a enregistré la garde (traçabilité)
-- ---------------------------------------------------------------------
CREATE TABLE pointages (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    matricule      VARCHAR(20) NOT NULL,
    date_debut     DATE NOT NULL,
    heure_debut    TIME NOT NULL DEFAULT '08:00:00',
    date_fin       DATE NOT NULL COMMENT 'Calculée = début + 24h',
    heure_fin      TIME NOT NULL DEFAULT '08:00:00',
    duree_heures   INT NOT NULL DEFAULT 24,
    montant        DECIMAL(10,2) NOT NULL DEFAULT 30000.00,
    reference_om   VARCHAR(100) DEFAULT NULL,
    saisi_par      INT NULL COMMENT 'Utilisateur ayant saisi la garde',
    date_saisie    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pointage_agent FOREIGN KEY (matricule)
        REFERENCES agents(matricule) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pointage_user FOREIGN KEY (saisi_par)
        REFERENCES utilisateurs(id) ON DELETE SET NULL,
    INDEX idx_pointage_matricule_date (matricule, date_debut)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table : ordres_paiement  (historique des PDF générés)
-- ---------------------------------------------------------------------
CREATE TABLE ordres_paiement (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    numero_op       VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Ex: 01/35 /2026',
    matricule       VARCHAR(20)  NOT NULL,
    periode_debut   DATE NOT NULL,
    periode_fin     DATE NOT NULL,
    nb_gardes       INT NOT NULL DEFAULT 0,
    montant         DECIMAL(10,2) NOT NULL,
    genere_par      INT NULL COMMENT 'Admin ayant généré le PDF',
    date_generation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_op_agent FOREIGN KEY (matricule)
        REFERENCES agents(matricule) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_op_user FOREIGN KEY (genere_par)
        REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Table : parametres  (constantes fixes du modèle "OP à imprimer")
-- ---------------------------------------------------------------------
CREATE TABLE parametres (
    cle    VARCHAR(50) NOT NULL PRIMARY KEY,
    valeur TEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO parametres (cle, valeur) VALUES
('ministere',                 'MINISTERE'),
('ministere_code',            '21 MEF'),
('republique',                'REPUBLIQUE DE MADAGASCAR'),
('ordonnateur',               '00-22Y-8-00000'),
('commune_beneficiaire',      '00000'),
('mission',                   '220'),
('exercice',                  '2026'),
('budget',                    '00'),
('categorie',                 '3'),
('code_cf',                   '10101.220'),
('tp_assignataire',           '10101.100'),
('texte_credits',             'En vertu des credits sur le paragraphe ci-dessous,\nl\'ordonnateur soussigne mandate les creances ci-apres'),
('programme',                 '117'),
('activite_projet',           '117-1-1-03'),
('imputation_administrative', '00-21-T-223-00000'),
('compte',                    '6241 Indemnites de mission interieure'),
('sect_conv',                 'SECT-CONV :      000\nRESSOURCES PROPRES'),
('financement',               '10-001-001-A'),
('poste_comptable_nom',       'TG Antsirabe'),
('poste_comptable_code',      '35'),
('lieu',                      'Antsirabe'),
('indemnite_par_garde',       '30000'),
('taxes',                     '0'),
('a_precompter',              '0');

-- ---------------------------------------------------------------------
-- Table : historique  (NOUVEAU en v2 - journal d'audit)
-- Chaque modification importante y laisse une trace : QUI, QUAND, QUOI,
-- avec les valeurs AVANT / APRES au format JSON.
-- Aucun écran de l'application ne permet de supprimer ces lignes.
-- Pas de clé étrangère stricte : l'historique doit survivre même si
-- l'utilisateur concerné est supprimé (le nom est copié dans "username").
-- ---------------------------------------------------------------------
CREATE TABLE historique (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    date_action     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utilisateur_id  INT NULL,
    username        VARCHAR(50) NOT NULL COMMENT 'Copie du nom au moment de l action',
    role            VARCHAR(20) NOT NULL,
    action          VARCHAR(50) NOT NULL COMMENT 'Ex: ajout_pointage, modification_agent, validation_utilisateur...',
    table_cible     VARCHAR(50) NULL,
    cible_id        VARCHAR(50) NULL COMMENT 'Id ou matricule de la ligne concernée',
    description     VARCHAR(500) NOT NULL,
    donnees_avant   TEXT NULL COMMENT 'JSON : valeurs avant modification',
    donnees_apres   TEXT NULL COMMENT 'JSON : valeurs après modification',
    ip_adresse      VARCHAR(45) NULL,
    INDEX idx_hist_date (date_action),
    INDEX idx_hist_user (utilisateur_id),
    INDEX idx_hist_action (action),
    CONSTRAINT fk_hist_user FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Le compte administrateur est créé par install/setup_admin.php
