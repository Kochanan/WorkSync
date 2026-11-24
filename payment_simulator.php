<?php
require_once 'init.php';
require_login();

$uid = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: dashboard.php');
    exit;
}

/* โหลด order */
$stmt = $conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
$stmt->bind_param("ii", $orderId, $uid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "Order not found";
    exit;
}

// ออเดอร์ที่จ่ายแล้วหรือถูกยกเลิกไปแล้ว ไม่ต้องมาหน้าจ่ายเงินอีก
if ($order['status'] !== 'pending') {
    header("Location: order_detail.php?id=" . $orderId);
    exit;
}

/* === เมื่อกดจ่ายเงิน === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); // ตรวจสอบความปลอดภัย CSRF

    // อัปเดตสถานะ → Paid
    // เงื่อนไข status='pending' กันการกดซ้ำ และกันการปลุกออเดอร์ที่ยกเลิกไปแล้วให้กลับมาเป็นจ่ายแล้ว
    $upd = $conn->prepare(
        "UPDATE orders SET status='paid', paid_at=NOW()
         WHERE id=? AND user_id=? AND status='pending'
           AND created_at >= NOW() - INTERVAL ? HOUR" // เกินเวลาชำระแล้ว จ่ายไม่ได้ (จะถูกยกเลิกอัตโนมัติ)
    );
    $hrs = PENDING_ORDER_HOURS;
    $upd->bind_param("iii", $orderId, $uid, $hrs);
    $upd->execute();
    $upd->close();

    // เด้งไปหน้า Timeline Detail
    header("Location: order_detail.php?id=" . $orderId);
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Simulator — WorkSync</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.container{max-width:500px;margin:40px auto}
.pay-box{ background:#fff; border:1px solid #eee; border-radius:18px; padding:24px; text-align:center; }
.pay-title{ font-size:20px; font-weight:900; margin-bottom:6px; }
.pay-amount{ font-size:28px; color:var(--red, #b00020); font-weight:900; margin:12px 0 18px; }
.qr{ width:180px; margin:10px auto; display:block; }
.btn-pay{ background:var(--red, #b00020); color:#fff; border:none; padding:12px 16px; border-radius:12px; font-weight:900; cursor:pointer; width:100%; font-size:16px; }
.btn-pay:hover{ opacity:0.9; }
.note{ font-size:12px; color:#777; margin-top:14px; }
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
  <div class="pay-box">
    <div class="pay-title">เลือกช่องทางชำระเงิน</div>
    <div class="small" style="margin-bottom:16px;">Order #<?= (int)$orderId ?></div>
    <div class="pay-amount">฿<?= number_format((float)$order['total'], 2) ?></div>

    <!-- เลือกช่องทาง -->
    <div style="display:flex; gap:10px; margin:16px 0; justify-content:center;">
      <button type="button" onclick="setMethod('promptpay')" id="tab_promptpay"
              style="flex:1; padding:10px; border-radius:12px; border:2px solid #0d2137;
                     background:#0d2137; color:#fff; font-weight:700; font-size:13px; cursor:pointer;">
        📱 PromptPay
      </button>
      <button type="button" onclick="setMethod('card')" id="tab_card"
              style="flex:1; padding:10px; border-radius:12px; border:2px solid #eee;
                     background:#fff; color:#3d5066; font-weight:700; font-size:13px; cursor:pointer;">
        💳 บัตรเครดิต
      </button>
      <button type="button" onclick="setMethod('transfer')" id="tab_transfer"
              style="flex:1; padding:10px; border-radius:12px; border:2px solid #eee;
                     background:#fff; color:#3d5066; font-weight:700; font-size:13px; cursor:pointer;">
        🏦 โอนเงิน
      </button>
    </div>

    <!-- PromptPay -->
    <div id="method_promptpay">
      <div style="font-size:13px;color:#666;margin-bottom:8px;">สแกน QR Code ด้วยแอปธนาคาร</div>
      <img class="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode('PROMPTPAY_'.$orderId.'_'.number_format((float)$order['total'],2)) ?>" alt="PromptPay QR">
      <div style="font-size:12px;color:#aaa;margin:8px 0 4px;">หมายเลข PromptPay: <b style="color:#0d2137;">098-987-1362</b></div>

      <!-- อัปโหลดสลิป -->
      <div style="margin:16px 0; text-align:left;">
        <label style="font-size:13px;font-weight:700;color:#3d5066;display:block;margin-bottom:6px;">📎 แนบสลิปการโอนเงิน</label>
        <div id="slipZone" onclick="document.getElementById('slipFile').click()"
             style="border:2px dashed rgba(13,33,55,.2);border-radius:12px;padding:16px;
                    text-align:center;cursor:pointer;background:#fafafa;transition:border-color .2s;">
          <div id="slipContent">
            <div style="font-size:24px;margin-bottom:4px;">🖼️</div>
            <div style="font-size:13px;color:#888;">คลิกเพื่อเลือกรูปสลิป</div>
          </div>
          <img id="slipPreview" style="max-width:100%;max-height:150px;border-radius:8px;display:none;">
        </div>
        <input type="file" id="slipFile" accept="image/*" style="display:none"
               onchange="prevSlip(this)">
      </div>
    </div>

    <!-- Credit Card (จำลอง) -->
    <div id="method_card" style="display:none; text-align:left;">
      <div style="background:#f7f8fb;border-radius:14px;padding:16px;margin:8px 0;">
        <div style="font-size:13px;font-weight:700;color:#3d5066;margin-bottom:10px;">ข้อมูลบัตร (จำลอง)</div>
        <input type="text" placeholder="1234 5678 9012 3456" maxlength="19"
               style="width:100%;padding:9px 12px;border:1.5px solid #ddd;border-radius:8px;
                      font-size:14px;margin-bottom:8px;box-sizing:border-box;font-family:monospace;">
        <div style="display:flex;gap:8px;">
          <input type="text" placeholder="MM/YY" maxlength="5"
                 style="flex:1;padding:9px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:14px;">
          <input type="text" placeholder="CVV" maxlength="3"
                 style="flex:1;padding:9px 12px;border:1.5px solid #ddd;border-radius:8px;font-size:14px;">
        </div>
      </div>
    </div>

    <!-- โอนเงิน -->
    <div id="method_transfer" style="display:none; text-align:left;">
      <div style="background:#f7f8fb;border-radius:14px;padding:16px;margin:8px 0;font-size:13px;line-height:2;">
        <div style="font-weight:700;color:#0d2137;margin-bottom:4px;">ข้อมูลบัญชีธนาคาร (จำลอง)</div>
        <div>🏦 ธนาคารกสิกรไทย (KBANK)</div>
        <div>เลขบัญชี: <b>123-4-56789-0</b></div>
        <div>ชื่อบัญชี: <b>WorkSync Platform</b></div>
        <div>จำนวน: <b style="color:#ff5c35;">฿<?= number_format((float)$order['total'], 2) ?></b></div>
      </div>
      <!-- อัปโหลดสลิป -->
      <div style="margin-top:12px;">
        <label style="font-size:13px;font-weight:700;color:#3d5066;display:block;margin-bottom:6px;">📎 แนบสลิปการโอนเงิน</label>
        <div onclick="document.getElementById('slipFile2').click()"
             style="border:2px dashed rgba(13,33,55,.2);border-radius:12px;padding:14px;
                    text-align:center;cursor:pointer;background:#fafafa;">
          <div style="font-size:22px;margin-bottom:4px;">🖼️</div>
          <div style="font-size:13px;color:#888;">คลิกเพื่อเลือกรูปสลิป</div>
        </div>
        <input type="file" id="slipFile2" accept="image/*" style="display:none">
      </div>
    </div>

    <form method="POST" style="margin-top:16px;">
      <?= csrf_tag(); ?>
      <button class="btn-pay" type="submit">
        ✅ ยืนยันการชำระเงิน
      </button>
    </form>

    <div class="note">* ระบบจำลองเพื่อทดสอบ Flow การชำระเงินเท่านั้น</div>
  </div>
</div>

<script>
function setMethod(m) {
  ['promptpay','card','transfer'].forEach(k => {
    document.getElementById('method_'+k).style.display = k===m ? 'block' : 'none';
    const tab = document.getElementById('tab_'+k);
    if (tab) {
      tab.style.background = k===m ? '#0d2137' : '#fff';
      tab.style.color      = k===m ? '#fff' : '#3d5066';
      tab.style.borderColor= k===m ? '#0d2137' : '#eee';
    }
  });
}
function prevSlip(input) {
  const file = input.files[0];
  if (!file) return;
  const r = new FileReader();
  r.onload = e => {
    document.getElementById('slipContent').style.display = 'none';
    const img = document.getElementById('slipPreview');
    img.src = e.target.result;
    img.style.display = 'block';
  };
  r.readAsDataURL(file);
}
setMethod('promptpay');
</script>

</body>
</html>