# WorkSync Mobile API

Base URL: `http://192.168.1.111/worksync/api/`

## ติดตั้ง
1. รัน `api_schema.sql` ใน phpMyAdmin (สร้างตาราง api_tokens)
2. วางโฟลเดอร์ api/ ใน worksync/

## Endpoints

### Auth (auth.php)
| Method | URL | Body | คำอธิบาย |
|--------|-----|------|----------|
| POST | `auth.php?action=register` | `{username, password, email}` | สมัครสมาชิก (ส่ง OTP ไปทางอีเมล) |
| POST | `auth.php?action=verify` | `{username, code}` | ยืนยันอีเมลด้วย OTP 6 หลัก (ต้องยืนยันก่อน login) |
| POST | `auth.php?action=resend` | `{username}` | ขอ OTP ใหม่ |
| POST | `auth.php?action=login` | `{username, password}` | เข้าสู่ระบบ → ได้ token |
| POST | `auth.php?action=logout` | (Bearer token) `{all?:true}` | ออกจากระบบ (all = ทุกอุปกรณ์) |
| GET | `auth.php?action=me` | (Bearer token) | ดูข้อมูลตัวเอง |

### Products (products.php)
| Method | URL | คำอธิบาย |
|--------|-----|----------|
| GET | `products.php?action=list&q=&cat=` | รายการสินค้า + ค้นหา + กรอง |
| GET | `products.php?action=detail&id=1` | รายละเอียดสินค้า + รีวิว |
| GET | `products.php?action=categories` | หมวดหมู่ทั้งหมด |

### Cart (cart.php) — ต้องมี Bearer token
| Method | URL | Body |
|--------|-----|------|
| GET | `cart.php?action=get` | - |
| POST | `cart.php?action=add` | `{product_id, qty}` |
| POST | `cart.php?action=update` | `{cart_id, qty}` |
| POST | `cart.php?action=remove` | `{cart_id}` |

### Orders (orders.php) — ต้องมี Bearer token
| Method | URL | Body |
|--------|-----|------|
| GET | `orders.php?action=list` | - |
| GET | `orders.php?action=detail&id=1` | - |
| POST | `orders.php?action=create` | `{address_id, cart_ids:[]}` (ยอดเงิน/ค่าส่งคำนวณที่เซิร์ฟเวอร์) |

## Authentication
ส่ง token ใน header ทุก request ที่ต้อง login:
```
Authorization: Bearer <token>
```
