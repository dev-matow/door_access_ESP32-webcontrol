from flask import Flask, request, jsonify
import time
import pymysql
import bcrypt

app = Flask(__name__)

# ---- DB config ----
DB = dict(
    host="127.0.0.1",
    user="root",
    password="*****",
    database="door_access",
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=True,
)

def db_conn():
    return pymysql.connect(**DB)

def verify_door_token(plain: str, token_hash: str) -> bool:
    # token_hash ต้องเป็น hash ที่เก็บไว้ใน DB (bcrypt)
    return bcrypt.checkpw(plain.encode("utf-8"), token_hash.encode("utf-8"))

@app.post("/api/access_check")
def access_check():
    body = request.get_json(silent=True) or {}

    door_id = str(body.get("door_id", "")).strip()
    card_uid = str(body.get("card_uid", "")).strip().upper()
    ts = int(body.get("ts", 0) or 0)
    doors_token = str(body.get("doors_token", "")).strip()

    if not door_id or not card_uid or not ts or not doors_token:
        return jsonify({"allowed": False, "reason": "INVALID_INPUT"}), 400

    now = int(time.time())
    if abs(now - ts) > 60:
        # log deny (TS_EXPIRED)
        try:
            with db_conn() as conn:
                with conn.cursor() as cur:
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
                           VALUES (%s,%s,%s,'DENY','TS_EXPIRED',%s)""",
                        (ts, door_id, card_uid, request.remote_addr),
                    )
        except Exception:
            pass
        return jsonify({"allowed": False, "reason": "TS_EXPIRED"}), 401

    try:
        with db_conn() as conn:
            with conn.cursor() as cur:
                # 1) verify door + token
                cur.execute("SELECT status, doors_token_hash FROM doors WHERE door_id=%s LIMIT 1", (door_id,))
                door = cur.fetchone()
                if not door:
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
                           VALUES (%s,%s,%s,'DENY','DOOR_NOT_FOUND',%s)""",
                        (ts, door_id, card_uid, request.remote_addr),
                    )
                    return jsonify({"allowed": False, "reason": "DOOR_NOT_FOUND"}), 404

                if door["status"] != "active":
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
                           VALUES (%s,%s,%s,'DENY','DOOR_DISABLED',%s)""",
                        (ts, door_id, card_uid, request.remote_addr),
                    )
                    return jsonify({"allowed": False, "reason": "DOOR_DISABLED"}), 403

                if not verify_door_token(doors_token, door["doors_token_hash"]):
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
                           VALUES (%s,%s,%s,'DENY','INVALID_DOOR_TOKEN',%s)""",
                        (ts, door_id, card_uid, request.remote_addr),
                    )
                    return jsonify({"allowed": False, "reason": "INVALID_DOOR_TOKEN"}), 401

                # update last_seen_at
                cur.execute("UPDATE doors SET last_seen_at=NOW() WHERE door_id=%s", (door_id,))

                # 2) verify card
                cur.execute("SELECT employee_id, status FROM nfc_cards WHERE card_uid=%s LIMIT 1", (card_uid,))
                card = cur.fetchone()
                if not card:
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, ip_addr)
                           VALUES (%s,%s,%s,'DENY','CARD_NOT_FOUND',%s)""",
                        (ts, door_id, card_uid, request.remote_addr),
                    )
                    return jsonify({"allowed": False, "reason": "CARD_NOT_FOUND"}), 404

                if card["status"] != "active":
                    cur.execute(
                        """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, employee_id, ip_addr)
                           VALUES (%s,%s,%s,'DENY','CARD_BLOCKED',%s,%s)""",
                        (ts, door_id, card_uid, int(card["employee_id"]), request.remote_addr),
                    )
                    return jsonify({"allowed": False, "reason": "CARD_BLOCKED"}), 403

                employee_id = int(card["employee_id"])

                # 3) check ACL
                cur.execute(
                    "SELECT allow FROM acl_permissions WHERE card_uid=%s AND door_id=%s LIMIT 1",
                    (card_uid, door_id),
                )
                perm = cur.fetchone()
                allowed = bool(perm and int(perm["allow"]) == 1)

                # 4) log result
                cur.execute(
                    """INSERT INTO access_logs (ts_client, door_id, card_uid, result, reason, employee_id, ip_addr)
                       VALUES (%s,%s,%s,%s,%s,%s,%s)""",
                    (
                        ts, door_id, card_uid,
                        "ALLOW" if allowed else "DENY",
                        "OK" if allowed else "NO_PERMISSION",
                        employee_id,
                        request.remote_addr,
                    ),
                )

                return jsonify({"allowed": allowed, "reason": "OK" if allowed else "NO_PERMISSION"}), 200

    except Exception:
        return jsonify({"allowed": False, "reason": "ERROR"}), 500

if __name__ == "__main__":
    # ฟังทุก interface เพื่อให้ ESP ยิงเข้ามาได้
    app.run(host="0.0.0.0", port=8889)