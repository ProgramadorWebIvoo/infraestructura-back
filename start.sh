#!/bin/bash
# =============================================================
# start.sh — Arranca el backend (serve + scheduler) en Windows
# Uso:  bash start.sh
# Ctrl+C para detener ambos procesos
# =============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ── Validaciones ──────────────────────────────────────────────
if ! command -v php &>/dev/null; then
    echo "[ERROR] PHP no encontrado. Verifica que esté en PATH."
    exit 1
fi

if [ ! -f "artisan" ]; then
    echo "[ERROR] No se encuentra 'artisan'. ¿Estás en la raíz del proyecto?"
    exit 1
fi

# ── Puerto ────────────────────────────────────────────────────
PORT=${1:-8000}
PID_FILE="/tmp/infra-backend-pids"

cleanup() {
    echo ""
    echo "[INFO] Deteniendo procesos..."
    if [ -f "$PID_FILE" ]; then
        while IFS= read -r pid; do
            kill "$pid" 2>/dev/null || true
        done < "$PID_FILE"
        rm -f "$PID_FILE"
    fi
    echo "[OK] Backend detenido."
    exit 0
}

trap cleanup SIGINT SIGTERM EXIT

# ── Arrancar servidor ─────────────────────────────────────────
echo "┌─────────────────────────────────────────────────────┐"
echo "│  IVOO Infraestructura — Backend                     │"
echo "└─────────────────────────────────────────────────────┘"
echo ""

echo "[OK] Servidor:       http://localhost:${PORT}"
php artisan serve --port="$PORT" > /dev/null 2>&1 &
echo "$!" >> "$PID_FILE"

echo "[OK] Scheduler:      corriendo cada minuto (Ctrl+C para salir)"
php artisan schedule:work > /dev/null 2>&1 &
echo "$!" >> "$PID_FILE"

echo ""
echo "───────────────────────────────────────────────────────"
echo "  Procesos corriendo en background."
echo "  Presiona Ctrl+C para detener todo."
echo "───────────────────────────────────────────────────────"

# Mantener vivo el script; esperar señal
wait
