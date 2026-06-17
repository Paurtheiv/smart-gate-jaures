#!/bin/bash
# SmartGate V4 - Démarrage
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LOG_DIR="$PROJECT_DIR/logs"
GREEN='\033[0;32m'; RED='\033[0;31m'; NC='\033[0m'

echo ""
echo "=================================="
echo "   SmartGate V4 — Démarrage"
echo "   Lycée Jean Jaurès"
echo "=================================="
echo ""

mkdir -p "$LOG_DIR"

# MySQL
echo -n "🔍 MySQL... "
if systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql 2>/dev/null; then
    echo -e "${GREEN}✅ OK${NC}"
else
    sudo systemctl start mariadb 2>/dev/null || sudo systemctl start mysql 2>/dev/null
    sleep 2
    echo -e "${GREEN}✅ Démarré${NC}"
fi

# Arrêter anciens processus
pkill -f "api.api_server" 2>/dev/null
pkill -f "web.app" 2>/dev/null
sleep 1

# API (port 5000)
echo -n "🚀 API server (port 5000)... "
cd "$PROJECT_DIR"
nohup python3 -m api.api_server > "$LOG_DIR/api.log" 2>&1 &
echo $! > "$LOG_DIR/api.pid"
sleep 2
kill -0 $(cat "$LOG_DIR/api.pid") 2>/dev/null && echo -e "${GREEN}✅ PID $(cat $LOG_DIR/api.pid)${NC}" || echo -e "${RED}❌ Voir $LOG_DIR/api.log${NC}"

# Web (port 8000)
echo -n "🌐 Interface web (port 8000)... "
nohup python3 -m web.app > "$LOG_DIR/web.log" 2>&1 &
echo $! > "$LOG_DIR/web.pid"
sleep 2
kill -0 $(cat "$LOG_DIR/web.pid") 2>/dev/null && echo -e "${GREEN}✅ PID $(cat $LOG_DIR/web.pid)${NC}" || echo -e "${RED}❌ Voir $LOG_DIR/web.log${NC}"

# Cron archivage
CRON_JOB="0 0 * * * cd $PROJECT_DIR && python3 database/archive_logs.py >> $LOG_DIR/archive.log 2>&1"
( crontab -l 2>/dev/null | grep -v "archive_logs.py" ; echo "$CRON_JOB" ) | crontab -

IP=$(hostname -I | awk '{print $1}')
echo ""
echo "=================================="
echo -e "${GREEN}✅ SmartGate V4 démarré !${NC}"
echo "=================================="
echo "  🔌 API      : http://$IP:5000"
echo "  🌐 Interface : http://$IP:8000"
echo "  📺 Terminal  : http://$IP:8000/terminal"
echo "  🛑 Arrêter   : bash stop.sh"
echo "=================================="
