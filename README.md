# Door Access ESP32 Web Control
# GISDEV Team
![Login](images/login.png)

![tests](https://img.shields.io/badge/tests-passing-brightgreen)
![status](https://img.shields.io/badge/status-stable-blue)
![version](https://img.shields.io/badge/version-1.5.0-blue)

# Door Access ESP32 Web Control

ระบบควบคุมการเข้า-ออกประตูสำหรับ ESP32/ESP8266 พร้อมแผงผู้ดูแล (Admin) และ API แบบ JSON
สร้างด้วย PHP + MySQL + Bootstrap 5 และมีตัวอย่างสเก็ตช์สำหรับ ESP8266 (Keypad)

## ภาพรวม
- แผง Admin สำหรับจัดการประตู, พนักงาน, บัตร NFC, และสิทธิ์เข้า-ออก (ACL)
- API สำหรับอุปกรณ์ตรวจสอบสิทธิ์และส่ง heartbeat
- บันทึก Log การเข้าออกทั้งหมด
- ตัวอย่างสเก็ตช์ ESP8266 พร้อมหน้าเว็บตั้งค่าอุปกรณ์
- มีตัวอย่าง API collection สำหรับ Bruno

## Tech Stack
- PHP (PDO)
- MySQL/MariaDB
- Bootstrap 5
- (ออปชัน) Python/Flask API: `api/app.py`
- ESP8266 Arduino Sketch: `EPS2866/sketch_jan29a.ino`

## โครงสร้างโฟลเดอร์
- `admin/` หน้า Admin ทั้งหมด
- `api/` API สำหรับอุปกรณ์ (PHP) และ Flask app (ออปชัน)
- `config/` คอนฟิกฐานข้อมูลและฟังก์ชันความปลอดภัย
- `sql/schema.sql` โครงสร้างฐานข้อมูล
- `assets/` ไฟล์สแตติก
- `EPS2866/` สเก็ตช์ ESP8266 (Keypad + Web Config)
- `testAPI1.bru` ตัวอย่าง API collection (Bruno)

## ฟีเจอร์หลัก
- Login สำหรับผู้ดูแลระบบ
- จัดการประตู (เพิ่ม/แก้ไข/ปิดใช้งาน/หมุน token/แสดง last seen)
- จัดการพนักงานและบัตร NFC
- กำหนดสิทธิ์เข้า-ออก (ACL) รายบัตรต่อประตู
- บันทึก Access Logs
- API สำหรับอุปกรณ์ตรวจสอบสิทธิ์และ heartbeat

## การติดตั้ง (XAMPP)
1) สร้างฐานข้อมูลชื่อ `door_access`
2) Import โครงสร้างตารางจาก `sql/schema.sql`
3) ตั้งค่า DB ใน `config/db.php`
4) สร้างผู้ดูแลระบบครั้งแรก
   - แก้ไขค่า username/password ใน `admin/create_admin.php`
   - เปิดไฟล์นี้ผ่านเบราว์เซอร์ 1 ครั้งเพื่อสร้าง admin
   - แนะนำให้ลบหรือจำกัดการเข้าถึงไฟล์นี้หลังใช้งาน
5) เข้าใช้งาน Admin: `/admin/login.php`

> หมายเหตุ: มีไฟล์ `config/db1.php` ที่เป็นตัวอย่างคอนฟิกอีกชุดหนึ่ง

## API (PHP) – JSON
Base URL ตามโดเมนที่ติดตั้ง เช่น `http://<host>/api/...`

### 1) POST `/api/heartbeat.php`
ใช้สำหรับอัปเดตสถานะประตู (last seen)

Request:
```json
{ "door_id": "D01", "ts": 1700000000, "doors_token": "<token>" }
```
Response:
```json
{ "ok": true }
```

### 2) POST `/api/access_check.php`
ใช้ตรวจสอบสิทธิ์เข้า-ออก

Request:
```json
{ "door_id": "D01", "card_uid": "A1B2C3D4", "ts": 1700000000, "doors_token": "<token>" }
```
Response:
```json
{ "allowed": true, "reason": "OK" }
```

### กฎสำคัญ
- `ts` เป็น Unix time (วินาที) ต้องไม่ต่างจากเวลาบน server เกิน 60 วินาที
- token ของประตูถูกเก็บเป็น hash (bcrypt) ใน DB
- token ตัวจริงจะแสดง “ครั้งเดียว” ตอนสร้าง/หมุน token

## API (Python/Flask) – ออปชัน
ไฟล์: `api/app.py`
- ให้ endpoint `/api/access_check` เหมือนกับ PHP
- รันด้วยคำสั่ง (ตัวอย่าง):
```bash
python api/app.py
```
- เปิดพอร์ต 8889 (`http://<host>:8889/api/access_check`)

## ESP8266 (Keypad) – สเก็ตช์ตัวอย่าง
ไฟล์: `EPS2866/sketch_jan29a.ino`

ฟีเจอร์หลัก:
- หน้า Web Config บนตัวอุปกรณ์ (AP Mode) สำหรับตั้งค่า Wi‑Fi, Door ID, Token, API URL
- ส่งคำขอไปยัง API เมื่อกดรหัสที่ Keypad แล้วกด `#`
- มีเสียงบี๊บและควบคุมรีเลย์

ค่า/พินสำคัญ:
- Relay: D8 (GPIO15)
- Buzzer: RX (GPIO3)
- Keypad: D0–D7

พฤติกรรมการใช้งาน:
- `*` ล้างรหัสที่กด
- `#` ส่งรหัสไปตรวจสอบสิทธิ์
- หากอนุญาต จะสั่งรีเลย์เปิดตามเวลา `RELAY_ACTION_MS`

## การทดสอบ API ด้วย Bruno
ใช้ไฟล์ `testAPI1.bru` เพื่อทดสอบ API ได้ทันที

## Security Notes
- หาก token รั่ว ให้ Rotate Token และอัปเดตที่อุปกรณ์
- หลังสร้าง admin ครั้งแรก ให้ลบ/จำกัดการเข้าถึง `admin/create_admin.php`
- แนะนำให้รันระบบหลัง reverse proxy + HTTPS ในงานจริง

## Troubleshooting
- เข้า Admin ไม่ได้: ตรวจ `config/db.php` และสิทธิ์ฐานข้อมูล
- อุปกรณ์เข้าไม่ได้: ตรวจ `doors_token`, `door_id` และเวลาบน ESP (NTP)
- ts ไม่ตรง: อุปกรณ์ต้อง sync เวลาให้ได้ก่อนส่ง API

## License
GISDEV Team
