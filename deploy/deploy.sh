#!/bin/bash
# Deploys the plugin from a clone of the private repository to the live plugin folder.
# Run as the site user:  bash deploy.sh
#
# 1. pulls the latest code (read-only deploy key, fast-forward only)
# 2. checks every PHP file for syntax errors
# 3. only if all files pass, copies them to the plugin folder
# A broken file therefore never reaches the live site.

set -euo pipefail

SRC="/home/tasgnaturals/tasg_abo-src"
DEST="/home/tasgnaturals/public_html/wp-content/plugins/tasg_abo"
KEY="/home/tasgnaturals/.ssh/tasg_abo_deploy"

export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

cd "$SRC"
BEFORE="$(git rev-parse --short HEAD)"
git pull --ff-only --quiet origin main
AFTER="$(git rev-parse --short HEAD)"

BAD=0
while IFS= read -r -d '' file; do
  if ! php -l "$file" > /dev/null 2>&1; then
    echo "SYNTAX ERROR in $file"
    php -l "$file" || true
    BAD=1
  fi
done < <(find "$SRC" -name '*.php' -not -path '*/.git/*' -print0)

if [ "$BAD" -ne 0 ]; then
  echo "Deploy stopped, nothing was copied. The live plugin is unchanged."
  exit 1
fi

rsync -a --delete --exclude='.git' --exclude='.gitattributes' --exclude='/deploy/' "$SRC"/ "$DEST"/

echo "Deployed $AFTER (was $BEFORE) to $DEST"
