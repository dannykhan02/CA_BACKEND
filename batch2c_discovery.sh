#!/usr/bin/env bash

echo "=== everywhere ->kpis()->create( or ->charts()->create( or ->pageFlags()->create( is called ==="
grep -rn "kpis()->create\|charts()->create\|pageFlags()->create" --include="*.php" app/ database/ 2>/dev/null || echo "[none found]"

echo ""
echo "=== DocumentObserver content ==="
cat app/Observers/DocumentObserver.php || echo "[FAILED: DocumentObserver.php]"

echo ""
echo "=== GenerateInsightsJob (or similarly named job that creates KPIs/charts) ==="
find . -iname "*Insight*Job*.php" -exec cat {} \; 2>/dev/null || echo "[not found by that name]"

echo ""
echo "=== DONE ==="
