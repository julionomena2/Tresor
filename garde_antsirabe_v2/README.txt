=====================================================================
 GESTION DES GARDES - 45 TG ANTSIRABE  -  VERSION 2
 (séparation Admin / Utilisateur, inscriptions, historique)
=====================================================================

NOUVEAUTES DE LA VERSION 2
--------------------------
 * Deux espaces séparés :
     - UTILISATEUR : s'occupe du pointage (saisie des gardes de 24h,
       modification de SES propres saisies).
     - ADMINISTRATEUR : gère les agents, les pointages (modif/suppression),
       les utilisateurs, les paramètres, les indemnités/PDF et consulte
       l'historique.
 * Inscription des utilisateurs (register.php) + VALIDATION par l'admin.
   Un compte "en attente", "refusé" ou "suspendu" ne peut pas se connecter.
 * HISTORIQUE (journal d'audit) : qui / quand / quoi + valeurs AVANT et
   APRES pour chaque action. Lecture seule, non supprimable depuis l'app.
 * Correction du "montant en lettres" sur le PDF (ex: 300 000 ->
   "TROIS CENT MILLE" et non "TROIS CENTS MILLE" ; 77 000 ne plante plus).

---------------------------------------------------------------------
 STRUCTURE DU PROJET  (dans C:\xampp\htdocs\garde_antsirabe\)
---------------------------------------------------------------------
 login.php, register.php, logout.php, index.php    pages publiques
 admin/        pages réservées à l'administrateur
 user/         pages de pointage (utilisateur + admin)
 includes/     auth.php, functions.php, header.php, footer.php
 config/       database.php, app.php
 templates/    op_template.php (modèle du PDF)
 sql/          schema.sql (install neuve) / migration_v2.sql (mise à jour)
 install/      setup_admin.php (install neuve seulement)
 assets/       css
 vendor/       Dompdf (créé par Composer - NE PAS SUPPRIMER)

---------------------------------------------------------------------
 CAS A : TU AS DEJA LA VERSION 1 INSTALLEE (ton cas) -> MISE A JOUR
---------------------------------------------------------------------
 1. SAUVEGARDE : phpMyAdmin > base garde_antsirabe_db > Exporter.
    Copie aussi ton dossier htdocs\garde_antsirabe ailleurs.

 2. Dans htdocs\garde_antsirabe\ :
      - SUPPRIME ces anciens fichiers (remplacés par admin/ et user/) :
            agents.php, pointage.php, generer_pdf.php, generer_pdf_tous.php
      - CONSERVE le dossier "vendor" (Dompdf déjà installé).
      - Copie par-dessus tous les fichiers du zip v2 (accepte de remplacer).
      - Si tu avais modifié config/database.php (mot de passe MySQL...),
        remets tes valeurs dedans.

 3. phpMyAdmin > clique sur la base garde_antsirabe_db > onglet SQL >
    colle TOUT le contenu de sql/migration_v2.sql > Exécuter.
    (Tes agents, gardes et OP sont conservés. Ton compte admin garde
     le même identifiant et le même mot de passe.)

 4. Va sur http://localhost/garde_antsirabe/login.php
    Connecte-toi avec ton compte admin habituel -> tu arrives sur le
    tableau de bord administrateur.

 NB : les gardes saisies avant la mise à jour affichent "—" dans la
 colonne "Saisi par" (on ne savait pas qui les avait saisies).

---------------------------------------------------------------------
 CAS B : INSTALLATION NEUVE
---------------------------------------------------------------------
 1. Copie le dossier dans C:\xampp\htdocs\garde_antsirabe\
 2. phpMyAdmin > onglet SQL > colle sql/schema.sql > Exécuter.
 3. Va sur http://localhost/garde_antsirabe/install/setup_admin.php
    (crée l'admin : identifiant "admin", mot de passe W0SzN!HkTAI# )
    puis SUPPRIME le dossier install/.
 4. Dans le dossier du projet : composer require dompdf/dompdf
 5. Connexion : http://localhost/garde_antsirabe/login.php

---------------------------------------------------------------------
 FONCTIONNEMENT
---------------------------------------------------------------------
 INSCRIPTION D'UN UTILISATEUR
   1. Il clique sur "Demander une inscription" (page de connexion) et
      remplit : nom complet, identifiant, mot de passe (8 car. min).
   2. Son compte est "en attente". Il ne peut pas encore se connecter.
   3. L'admin voit un badge sur "Utilisateurs" et sur le tableau de bord,
      puis clique sur Valider (ou Refuser).
   4. Une fois validé, l'utilisateur se connecte et accède au pointage.

 L'ADMIN PEUT AUSSI : suspendre / réactiver un compte (la personne est
 déconnectée à sa prochaine action), réinitialiser un mot de passe,
 supprimer un compte.

 DROITS
   Utilisateur : ajouter une garde ; modifier SES gardes uniquement.
   Admin       : tout + supprimer des gardes + agents + comptes +
                 paramètres + PDF + historique.

 HISTORIQUE (admin > Historique) - actions enregistrées :
   connexion / déconnexion / tentative échouée / compte refusé,
   inscription, validation / refus / suspension / réactivation /
   suppression de compte, réinitialisation de mot de passe (le mot de
   passe n'est JAMAIS enregistré), ajout / modification / suppression
   d'agent, ajout / modification / suppression de garde, modification
   des paramètres, génération d'OP (PDF).
   Pour les modifications, seules les valeurs CHANGEES sont conservées
   (avant -> après). Filtres : utilisateur, action, table, dates.

---------------------------------------------------------------------
 SECURITE
---------------------------------------------------------------------
 * Les dossiers config/, includes/, templates/, sql/ sont bloqués au
   navigateur par un fichier .htaccess (Apache XAMPP le gère par défaut).
 * Mots de passe stockés hachés (bcrypt). Session régénérée à la connexion.
 * Les pages admin/ refusent l'accès (403) à un utilisateur simple.
 * Pense à changer le mot de passe admin par défaut.

=====================================================================
