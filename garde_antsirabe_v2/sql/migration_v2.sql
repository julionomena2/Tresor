-- =====================================================================
-- MIGRATION v1 -> v2  (base "garde_antsirabe_db" DEJA EXISTANTE)
--
-- Conserve TOUTES tes données (agents, pointages, OP générés) et ton
-- compte admin actuel (même identifiant, même mot de passe).
--
-- A EXECUTER UNE SEULE FOIS dans phpMyAdmin (onglet "SQL").
-- Fais une sauvegarde avant : phpMyAdmin > base > Exporter.
-- =====================================================================

USE garde_antsirabe_db;

-- 1) Nouvelle table utilisateurs (admin + utilisateurs)
CREATE TABLE utilisateurs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)  NOT NULL UNIQUE,
    password         VARCHAR(255) NOT NULL,
    nom_complet      VARCHAR(100) NOT NULL,
    role             ENUM('admin','utilisateur') NOT NULL DEFAULT 'utilisateur',
    statut           ENUM('en_attente','actif','refuse','suspendu') NOT NULL DEFAULT 'en_attente',
    date_inscription TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_validation  DATETIME NULL,
    valide_par       INT NULL
) ENGINE=InnoDB;

-- 2) Reprend l'ancien compte admin tel quel (même identifiant / mot de passe)
INSERT INTO utilisateurs (username, password, nom_complet, role, statut)
SELECT username, password, 'Administrateur', 'admin', 'actif' FROM admin;

-- 3) Trace de qui a saisi chaque garde (les anciennes gardes restent sans auteur : NULL)
ALTER TABLE pointages
    ADD COLUMN saisi_par INT NULL AFTER reference_om,
    ADD CONSTRAINT fk_pointage_user FOREIGN KEY (saisi_par)
        REFERENCES utilisateurs(id) ON DELETE SET NULL;

-- 4) Trace de qui a généré chaque OP
ALTER TABLE ordres_paiement
    ADD COLUMN genere_par INT NULL AFTER montant,
    ADD CONSTRAINT fk_op_user FOREIGN KEY (genere_par)
        REFERENCES utilisateurs(id) ON DELETE SET NULL;

-- 5) Journal d'audit
CREATE TABLE historique (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    date_action     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utilisateur_id  INT NULL,
    username        VARCHAR(50) NOT NULL,
    role            VARCHAR(20) NOT NULL,
    action          VARCHAR(50) NOT NULL,
    table_cible     VARCHAR(50) NULL,
    cible_id        VARCHAR(50) NULL,
    description     VARCHAR(500) NOT NULL,
    donnees_avant   TEXT NULL,
    donnees_apres   TEXT NULL,
    ip_adresse      VARCHAR(45) NULL,
    INDEX idx_hist_date (date_action),
    INDEX idx_hist_user (utilisateur_id),
    INDEX idx_hist_action (action),
    CONSTRAINT fk_hist_user FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6) L'ancienne table "admin" n'est plus utilisée (remplacée par utilisateurs)
DROP TABLE admin;
