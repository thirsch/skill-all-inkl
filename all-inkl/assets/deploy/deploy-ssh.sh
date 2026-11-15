#!/usr/bin/env bash
set -euo pipefail

# Uses an SSH config alias (key, hostname, user, port and verified known_hosts).
: "${DEPLOY_SSH_HOST:?Set an SSH config alias}"
: "${DEPLOY_ROOT:?Set the absolute remote project root}"
: "${DEPLOY_PHP:=php85}"
[[ $# == 1 && -f "$1" && -f "$1.sha256" ]] || { echo 'Usage: deploy-ssh.sh RELEASE.zip (plus .sha256)' >&2; exit 1; }
[[ "$DEPLOY_SSH_HOST" =~ ^[a-zA-Z0-9][a-zA-Z0-9_-]*$ ]] || { echo 'Invalid SSH alias' >&2; exit 1; }
[[ "$DEPLOY_ROOT" =~ ^/[a-zA-Z0-9_./-]+$ && "$DEPLOY_ROOT" != / && "$DEPLOY_ROOT" != */ && "/$DEPLOY_ROOT/" != */../* && "/$DEPLOY_ROOT/" != */./* ]] || { echo 'Use an absolute remote root without spaces, dot segments or trailing slash' >&2; exit 1; }
[[ "$DEPLOY_PHP" =~ ^[a-zA-Z0-9_./-]+$ && "$DEPLOY_PHP" != -* ]] || { echo 'Invalid PHP command' >&2; exit 1; }
read -r digest < "$1.sha256"
[[ "$digest" =~ ^[a-f0-9]{64}$ ]] || { echo 'Invalid SHA-256 sidecar' >&2; exit 1; }
ssh_options=(-T -o BatchMode=yes -o StrictHostKeyChecking=yes)
remote_zip="$DEPLOY_ROOT/incoming/$digest.zip"
# Stream into a unique temporary file; publish only after the full transfer succeeds.
ssh "${ssh_options[@]}" "$DEPLOY_SSH_HOST" \
  "set -eu; umask 077; mkdir -p '$DEPLOY_ROOT/incoming'; tmp=\$(mktemp '$DEPLOY_ROOT/incoming/upload.XXXXXXXX'); trap 'rm -f \"\$tmp\"' EXIT; cat > \"\$tmp\"; mv \"\$tmp\" '$remote_zip'" < "$1"
ssh "${ssh_options[@]}" "$DEPLOY_SSH_HOST" \
  "'$DEPLOY_PHP' '$DEPLOY_ROOT/deploy/deploy.php' deploy '$DEPLOY_ROOT/deploy/config.json' '$remote_zip' '$digest'"
