#!/bin/bash
# =============================================================
# test-fixes.sh — Prueba integral de los 7 fixes de auditoría
# 
# Uso:  bash test-fixes.sh
# Requiere: curl, jq, php, servidor corriendo en :8000
# =============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

pass() { echo -e "  ${GREEN}✅ $1${NC}"; }
fail() { echo -e "  ${RED}❌ $1${NC}"; }

echo ""
echo "┌─────────────────────────────────────────────────────┐"
echo "│  PRUEBA INTEGRAL — FIXES AUDITORÍA TYPE-A          │"
echo "└─────────────────────────────────────────────────────┘"
echo ""

TOTAL=0
PASS=0

# ── 1. Verificar QUEUE_CONNECTION ──────────────────────────
echo "[1/7] QUEUE_CONNECTION"
((TOTAL++))
if grep -q "QUEUE_CONNECTION=database" .env 2>/dev/null; then
    pass "QUEUE_CONNECTION=database"
    ((PASS++))
else
    fail "QUEUE_CONNECTION debe ser 'database' (está 'sync')"
fi

# ── 2. Verificar jobs table ────────────────────────────────
echo "[2/7] Tabla jobs"
((TOTAL++))
RESULT=$(php artisan tinker --execute="echo Schema::hasTable('jobs') ? '1' : '0';" 2>/dev/null)
if [ "$RESULT" = "1" ]; then
    pass "Tabla 'jobs' existe"
    ((PASS++))
else
    fail "Tabla 'jobs' no existe — ejecutar 'php artisan migrate'"
fi

# ── 3. Verificar ShouldQueue en ProjectStatusChanged ───────
echo "[3/7] ShouldQueue"
((TOTAL++))
if grep -q 'ShouldQueue' app/Notifications/ProjectStatusChanged.php 2>/dev/null; then
    pass "ProjectStatusChanged implements ShouldQueue"
    ((PASS++))
else
    fail "Falta 'implements ShouldQueue' en ProjectStatusChanged"
fi

# ── 4. Verificar que registerProviders NO muta config global ─
echo "[4/7] Sin config() global en AIEvaluationService"
((TOTAL++))
COUNT=$(grep -c 'config(\["ai\.' app/Services/AI/AIEvaluationService.php 2>/dev/null || echo 0)
if [ "$COUNT" -eq 0 ]; then
    pass "registerProviders no muta config() global (0 calls)"
    ((PASS++))
else
    fail "registerProviders aún llama config() — $COUNT llamada(s) encontrada(s)"
fi

# ── 5. Verificar que OpenAIProvider no usa config() ────────
echo "[5/7] Providers sin llamadas a config()"
((TOTAL++))
ALL_CLEAN=true
for f in app/Services/AI/Providers/*.php; do
    C=$(grep -c "config(" "$f" 2>/dev/null || echo 0)
    if [ "$C" -gt 0 ]; then
        fail "$(basename $f): $C llamada(s) a config()"
        ALL_CLEAN=false
    fi
done
if $ALL_CLEAN; then
    pass "Ningún provider llama a config()"
    ((PASS++))
fi

# ── 6. Verificar que OpenAIProvider acepta array $config ────
echo "[6/7] Constructor OpenAIProvider acepta array config"
((TOTAL++))
if grep -q '__construct(array \$config' app/Services/AI/Providers/OpenAIProvider.php 2>/dev/null; then
    pass "OpenAIProvider::__construct(array \$config)"
    ((PASS++))
else
    fail "OpenAIProvider constructor no acepta array config"
fi

# ── 7. Verificar estimateCost() keys corregidas ────────────
echo "[7/7] estimateCost() keys corregidas"
((TOTAL++))
HAS_OPENAI=$(grep -c "'openai'" app/Services/AI/AIEvaluationService.php 2>/dev/null || echo 0)
HAS_CHATGPT=$(grep -c "'chatgpt'" app/Services/AI/AIEvaluationService.php 2>/dev/null || echo 0)
if [ "$HAS_OPENAI" -gt 0 ] && [ "$HAS_CHATGPT" -eq 0 ]; then
    pass "Keys corregidas: openai/anthropic (no chatgpt/claude)"
    ((PASS++))
else
    fail "Keys incorrectas — revisar array \$pricing en estimateCost()"
fi

# ── Resumen ─────────────────────────────────────────────────
echo ""
echo "┌─────────────────────────────────────────────────────┐"
echo "│  RESULTADO: $PASS/$TOTAL pruebas pasaron"
if [ "$PASS" -eq "$TOTAL" ]; then
    echo "│  ${GREEN}✅ TODOS LOS FIXES VERIFICADOS${NC}"
else
    echo "│  ${RED}❌ HAY FALLOS QUE CORREGIR${NC}"
fi
echo "└─────────────────────────────────────────────────────┘"
echo ""
