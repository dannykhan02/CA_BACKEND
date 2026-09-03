#!/usr/bin/env bash

echo "=== DocumentUploadController@store (validation + authorization) ==="
find . -iname "DocumentUploadController.php" -exec cat {} \;

echo ""
echo "=== does the route have role-based middleware/policy attached? ==="
grep -n "documents.store\|Route::post.*documents" routes/api.php

echo ""
echo "=== does 'No 18 (1).pdf' still exist on disk from Phase A local testing? ==="
find . -iname "No 18*" 2>/dev/null
find /home/dan -iname "No 18*" 2>/dev/null | head -5

echo ""
echo "=== DONE ==="
