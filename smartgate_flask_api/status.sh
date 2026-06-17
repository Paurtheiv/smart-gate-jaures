#!/bin/bash
# SmartGate V4 - Statut
G='\033[0;32m'; R='\033[0;31m'; NC='\033[0m'
echo ""; echo "📊 SmartGate V4 — Statut"; echo "========================"
pgrep -f "api.api_server" > /dev/null && echo -e "  🔌 API  : ${G}✅ En cours${NC} (port 5000)" || echo -e "  🔌 API  : ${R}❌ Arrêté${NC}"
pgrep -f "web.app"        > /dev/null && echo -e "  🌐 Web  : ${G}✅ En cours${NC} (port 8000)" || echo -e "  🌐 Web  : ${R}❌ Arrêté${NC}"
(systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql 2>/dev/null) && echo -e "  🗄️  MySQL: ${G}✅ En cours${NC}" || echo -e "  🗄️  MySQL: ${R}❌ Arrêté${NC}"
echo "  🌍 IP   : $(hostname -I | awk '{print $1}')"; echo "========================"; echo ""
