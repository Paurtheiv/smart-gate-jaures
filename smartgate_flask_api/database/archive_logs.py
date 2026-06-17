#!/usr/bin/env python3
"""
SmartGate V4 - Archivage automatique des logs
----------------------------------------------
Archive les logs de la veille chaque nuit à 00h00.
Usage : python3 archive_logs.py [YYYY-MM-DD]
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import mysql.connector
from datetime import datetime, timedelta
from config.config import DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD


def get_conn():
    return mysql.connector.connect(
        host=DB_HOST, port=DB_PORT, database=DB_NAME,
        user=DB_USER, password=DB_PASSWORD, charset="utf8mb4"
    )


def archive_yesterday(target_date=None):
    conn = get_conn()
    c    = conn.cursor()
    date = target_date or (datetime.now() - timedelta(days=1)).strftime("%Y-%m-%d")
    print(f"📦 Archivage du {date}...")

    # access_logs
    c.execute("""
        INSERT INTO access_logs_archive
        (id,user_id,authentication_method,access_status,terminal,note,timestamp)
        SELECT id,user_id,authentication_method,access_status,terminal,note,timestamp
        FROM access_logs WHERE DATE(timestamp)=%s
    """, (date,))
    n1 = c.rowcount
    c.execute("DELETE FROM access_logs WHERE DATE(timestamp)=%s", (date,))

    # system_events
    c.execute("""
        INSERT INTO system_events_archive
        (id,event_type,description,user_id,extra_data,timestamp)
        SELECT id,event_type,description,user_id,extra_data,timestamp
        FROM system_events WHERE DATE(timestamp)=%s
    """, (date,))
    n2 = c.rowcount
    c.execute("DELETE FROM system_events WHERE DATE(timestamp)=%s", (date,))

    conn.commit(); c.close(); conn.close()
    print(f"✅ {n1} accès + {n2} événements archivés")


if __name__ == "__main__":
    archive_yesterday(sys.argv[1] if len(sys.argv) > 1 else None)
