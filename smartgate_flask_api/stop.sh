#!/bin/bash
# SmartGate V4 - Arrêt
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOG_DIR="$PROJECT_DIR/logs"
echo "🛑 Arrêt SmartGate V4..."
for s in api web; do
    PF="$LOG_DIR/$s.pid"
    [ -f "$PF" ] && kill $(cat "$PF") 2>/dev/null && echo "  ✅ $s arrêté" && rm -f "$PF"
done
pkill -f "api.api_server" 2>/dev/null
pkill -f "web.app"        2>/dev/null
echo "✅ Arrêté."
