#!/usr/bin/env bash
set -euo pipefail

for f in \
  app/Http/Controllers/Api/AuthController.php \
  app/Http/Controllers/Api/DocumentUploadController.php \
  app/Services/WorkspaceService.php \
; do
  echo
  echo "===== $f ====="
  if [ -f "$f" ]; then
    cat "$f"
  else
    echo "(FILE NOT FOUND — check path)"
  fi
done