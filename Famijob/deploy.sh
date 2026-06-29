#!/bin/bash

# Script de déploiement FamiJob
# Exécute ce script à la racine de ton hébergeur (où se trouvent public/ et famijob/)

set -e

HEBERGEUR_ROOT="."
PUBLIC_DIR="${HEBERGEUR_ROOT}/public"
FAMIJOB_DIR="${HEBERGEUR_ROOT}/famijob"

echo "=== Déploiement FamiJob ==="

# 1. Copier interim_horaires.php
echo "Copie interim_horaires.php..."
cp "${PUBLIC_DIR}/interim_horaires.php" "${FAMIJOB_DIR}/interim_horaires.php"

# 2. Adapter le contrôle de rôle dans interim_horaires.php
echo "Adaptation du contrôle de rôle..."
sed -i.bak \
  "s/\$role = getCurrentRole();/\$role = getCurrentRole();\\nif (!in_array(\$role, ['admin', 'teamcoach'], true)) {\\n    header('Location: ..\/public\/index.php');\\n    exit();\\n}/" \
  "${FAMIJOB_DIR}/interim_horaires.php"

# 3. Copier admin_disponibilites_etudiants.php
echo "Copie admin_disponibilites_etudiants.php..."
cp "${PUBLIC_DIR}/admin_disponibilites_etudiants.php" "${FAMIJOB_DIR}/admin_disponibilites_etudiants.php"

# 4. Adapter le contrôle de rôle dans admin_disponibilites_etudiants.php
echo "Adaptation du contrôle de rôle..."
sed -i.bak \
  "s/\$currentRole = getCurrentRole();/\$currentRole = getCurrentRole();\\nif (!in_array(\$currentRole, ['admin', 'teamcoach'], true)) {\\n    header('Location: ..\/public\/index.php');\\n    exit();\\n}/" \
  "${FAMIJOB_DIR}/admin_disponibilites_etudiants.php"

# 5. Créer le dossier uploads s'il n'existe pas
echo "Vérification du dossier uploads..."
mkdir -p "${FAMIJOB_DIR}/uploads/profils"
chmod 755 "${FAMIJOB_DIR}/uploads"
chmod 755 "${FAMIJOB_DIR}/uploads/profils"

# 6. Nettoyer les fichiers de backup
rm -f "${FAMIJOB_DIR}/"*.bak

echo "=== Déploiement terminé ==="
echo ""
echo "Structure créée:"
echo "  ✓ ${FAMIJOB_DIR}/config.php"
echo "  ✓ ${FAMIJOB_DIR}/index.php"
echo "  ✓ ${FAMIJOB_DIR}/interim_horaires_demandes.php"
echo "  ✓ ${FAMIJOB_DIR}/interim_horaires.php (copié et adapté)"
echo "  ✓ ${FAMIJOB_DIR}/admin_disponibilites_etudiants.php (copié et adapté)"
echo "  ✓ ${FAMIJOB_DIR}/includes/ (doit être créé)"
echo "  ✓ ${FAMIJOB_DIR}/.env (doit exister)"
echo ""
echo "Étapes restantes:"
echo "  1. Copier le dossier 'includes/' depuis public/ vers famijob/"
echo "  2. Copier le fichier '.env' depuis public/ vers famijob/"
echo "  3. Configurer votre DNS pour que famijob.be pointe vers le dossier famijob/"
echo "  4. Tester l'accès sur famijob.be"
