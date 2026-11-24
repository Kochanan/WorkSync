# ล็อก IP เครื่อง Windows 11

ตั้ง IP ของเครื่องให้คงที่ ไม่เปลี่ยนทุกครั้งที่รีสตาร์ต และถอดกลับเป็นอัตโนมัติได้ด้วยคำสั่งเดียว

## วิธีใช้ (ง่ายสุด)

1. แตกโฟลเดอร์นี้ไว้ที่ไหนก็ได้ เช่น Desktop
2. คลิกขวา `lock-ip.cmd` แล้วเลือก **Run as administrator**
3. สคริปต์จะล็อก **IP ที่เครื่องได้อยู่ตอนนี้** (ใช้ gateway และ DNS เดิม) แล้วแสดงผล `OK locked: ...`

## เลือก IP เอง

เปิด PowerShell แบบ Administrator ในโฟลเดอร์นี้ แล้วรันคำสั่งใดคำสั่งหนึ่ง:

```powershell
# ดูการ์ดแลน/Wi-Fi และ IP ตอนนี้ (ไม่เปลี่ยนอะไร)
powershell -ExecutionPolicy Bypass -File lock-ip.ps1 -List

# ล็อก IP ที่เลือก (gateway/DNS/subnet ใช้ค่าเดิม)
powershell -ExecutionPolicy Bypass -File lock-ip.ps1 -IP 192.168.1.50

# กำหนดทุกค่าเอง
powershell -ExecutionPolicy Bypass -File lock-ip.ps1 -IP 192.168.1.50 -Prefix 24 -Gateway 192.168.1.1 -Dns 192.168.1.1,8.8.8.8

# เครื่องมีหลายการ์ด ให้ระบุชื่อ (ดูชื่อจาก -List)
powershell -ExecutionPolicy Bypass -File lock-ip.ps1 -Adapter "Ethernet"

# ถอดกลับเป็นอัตโนมัติ (DHCP)
powershell -ExecutionPolicy Bypass -File lock-ip.ps1 -Dhcp
```

`-Prefix 24` คือ subnet mask `255.255.255.0`

## สคริปต์กันพลาดให้

- ถ้า IP ที่เลือกมีเครื่องอื่นใช้อยู่ → หยุด ไม่เปลี่ยนอะไร
- ถ้า gateway อยู่คนละวงกับ IP → หยุด
- ถ้าตั้ง IP ไม่สำเร็จ → คืนเป็นอัตโนมัติให้ทันที (เครื่องไม่หลุดเน็ตค้าง)
- บันทึกค่าเดิมก่อนเปลี่ยนทุกครั้งที่ `C:\ProgramData\LockIP\`

## ควรรู้

- **ล็อก IP เดิมที่ router แจกมา**: router อาจแจก IP นี้ให้เครื่องอื่นซ้ำในภายหลัง
  ทางที่ปลอดภัยกว่าคือให้ IT ทำ **DHCP reservation** ใน router (จอง IP ให้ MAC ของเครื่องนี้)
  หรือเลือก IP ที่อยู่**นอกช่วงที่ router แจก** (เช่น router แจก .100–.200 ให้ใช้ .50)
- **ถ้าเป็น Wi-Fi**: บน Windows IP ที่ล็อกจะใช้กับการ์ด Wi-Fi นี้ในทุกวง Wi-Fi ที่ต่อ
  ถ้าย้ายไปต่อ Wi-Fi วงอื่นจะออกเน็ตไม่ได้ ให้รัน `-Dhcp` ก่อนย้าย
- ถ้ารีโมตเข้ามาตั้ง (Remote Desktop) แล้วเปลี่ยนเป็น IP ใหม่ การเชื่อมต่อจะหลุด ให้ต่อใหม่ด้วย IP ใหม่
