<?php
require_once 'init.php';
require_login();
check_csrf();
$uid = (int)$_SESSION['user_id'];
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { header('Location: dashboard.php'); exit; }
$st = $conn->prepare("SELECT * FROM products WHERE id=? AND seller_id=? LIMIT 1");
$st->bind_param('ii', $pid, $uid); $st->execute();
$product = $st->get_result()->fetch_assoc(); $st->close();
if (!$product) { header('Location: dashboard.php'); exit; }
$cats = $conn->query("SELECT id,icon,name FROM categories ORDER BY sort ASC")->fetch_all(MYSQLI_ASSOC);

$lst = $conn->prepare("SELECT is_lendable, lending_days_limit, deposit_required, lending_note FROM product_lending_info WHERE product_id=? LIMIT 1");
$lst->bind_param('i', $pid); $lst->execute();
$lendInfo = $lst->get_result()->fetch_assoc(); $lst->close();

// อ่านค่า POST แบบข้อความเท่านั้น กันส่งมาเป็น array แล้ว trim() พังเป็น Fatal error
$ps = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

/** ลบไฟล์รูปเก่าเฉพาะไฟล์ที่อัปโหลดไว้ใน assets/uploads และไม่มีสินค้าอื่นใช้รูปเดียวกันอยู่
 *  (รูปประจำหมวด cat-*.svg และรูปตัวอย่างใช้ร่วมกันหลายสินค้า ห้ามลบ) */
function remove_product_image(mysqli $conn, ?string $img, int $exceptPid): void {
    if (!$img || !preg_match('#^assets/uploads/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$#', $img)) return;
    $c = $conn->prepare("SELECT COUNT(*) FROM products WHERE image = ? AND id <> ?");
    $c->bind_param('si', $img, $exceptPid); $c->execute();
    $inUse = (int)$c->get_result()->fetch_row()[0]; $c->close();
    if ($inUse > 0) return;
    $path = __DIR__ . '/' . $img;
    if (is_file($path)) @unlink($path);
}

$err = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = trim($ps('title'));
    $desc   = trim($ps('description'));
    $price  = (float)($_POST['price'] ?? 0);
    $stock  = (int)($_POST['stock'] ?? 0);
    $catId  = (int)($_POST['category_id'] ?? 0) ?: null;
    // หมวดหมู่ต้องมีอยู่จริง
    if ($catId !== null && !in_array($catId, array_map('intval', array_column($cats, 'id')), true)) { $catId = null; }
    if ($title === '' || !is_finite($price) || $price <= 0 || $stock < 0) { $err = 'กรุณากรอกข้อมูลให้ถูกต้อง'; }
    elseif (mb_strlen($title) > 255 || mb_strlen($desc) > 10000) { $err = 'ชื่อสินค้ายาวได้ไม่เกิน 255 ตัวอักษร และคำอธิบายไม่เกิน 10,000 ตัวอักษร'; }
    elseif ($price > 9999999 || $stock > 1000000) { $err = 'ราคาต้องไม่เกิน 9,999,999 บาท และสต็อกต้องไม่เกิน 1,000,000 ชิ้น'; }
    else {
        $imagePath = $product['image'];
        if (!empty($_FILES['image']['name'])) {
            $f = $_FILES['image'];
            $ext = is_string($f['name']) ? strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) : '';
            $allowedExt = ['jpg','jpeg','png','webp'];
            // นามสกุลที่บันทึกจริงเลือกจากชนิดไฟล์ที่ตรวจได้ ไม่ใช้นามสกุลที่ผู้ใช้ส่งมา
            $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!is_array($f['error']) && $f['error'] === UPLOAD_ERR_OK && in_array($ext, $allowedExt, true) && $f['size'] <= 2*1024*1024) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
                $info = @getimagesize($f['tmp_name']);
                $raw  = (string)@file_get_contents($f['tmp_name']);
                if (isset($mimeExt[$mime]) && $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
                    && $info[0] > 0 && $info[1] > 0 && stripos($raw, '<?php') === false) {
                    $uploadDir = __DIR__ . '/assets/uploads';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $newName = bin2hex(random_bytes(16)).'.'.$mimeExt[$mime];
                    if (move_uploaded_file($f['tmp_name'], $uploadDir.'/'.$newName)) {
                        $imagePath = 'assets/uploads/'.$newName;
                    } else { $err = 'ไม่สามารถบันทึกไฟล์รูปภาพได้'; }
                } else { $err = 'ไฟล์ไม่ใช่รูปภาพจริง'; }
            } else { $err = 'ไฟล์ต้องเป็น jpg/png/webp ไม่เกิน 2MB'; }
        }
        if ($err === '') {
            $upd = $conn->prepare("UPDATE products SET title=?,description=?,price=?,stock=?,category_id=?,image=?,updated_at=NOW() WHERE id=? AND seller_id=?");
            // ชนิดต้องตรงลำดับคอลัมน์: category_id = i, image = s (เดิมสลับกัน ทำให้ path รูปถูกบันทึกเป็น "0")
            $upd->bind_param('ssdiisii',$title, $desc, $price, $stock, $catId, $imagePath, $pid, $uid);
            if ($upd->execute()) {
                $ok = 'บันทึกสำเร็จ';
                if ($imagePath !== $product['image']) {
                    remove_product_image($conn, $product['image'], $pid);
                }
                $product = array_merge($product, ['title'=>$title,'description'=>$desc,'price'=>$price,'stock'=>$stock,'category_id'=>$catId,'image'=>$imagePath]);

                if (isset($_POST['is_lendable'])) {
                    $lendDays = max(1, min(30, (int)($_POST['lending_days_limit'] ?? 7)));
                    $deposit  = (float)($_POST['deposit_required'] ?? 0);
                    $deposit  = is_finite($deposit) ? max(0, min(9999999, $deposit)) : 0;
                    $lendNote = mb_substr(trim($ps('lending_note')), 0, 300);
                    $lendUp = $conn->prepare(
                        "INSERT INTO product_lending_info (product_id, is_lendable, lending_days_limit, deposit_required, lending_note)
                         VALUES (?, 1, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE is_lendable=1, lending_days_limit=VALUES(lending_days_limit),
                             deposit_required=VALUES(deposit_required), lending_note=VALUES(lending_note)"
                    );
                    $lendUp->bind_param('iids', $pid, $lendDays, $deposit, $lendNote);
                    $lendUp->execute();
                    $lendUp->close();
                    $lendInfo = ['is_lendable'=>1, 'lending_days_limit'=>$lendDays, 'deposit_required'=>$deposit, 'lending_note'=>$lendNote];
                } else {
                    $lendOff = $conn->prepare("UPDATE product_lending_info SET is_lendable=0 WHERE product_id=?");
                    $lendOff->bind_param('i', $pid);
                    $lendOff->execute();
                    $lendOff->close();
                    if ($lendInfo) { $lendInfo['is_lendable'] = 0; }
                }
            }
            else { $err = 'เกิดข้อผิดพลาด'; }
            $upd->close();
        } elseif ($imagePath !== $product['image']) {
            // บันทึกไม่สำเร็จ ลบไฟล์ใหม่ที่เพิ่งอัปโหลดทิ้ง ไม่ให้ค้างในเซิร์ฟเวอร์
            remove_product_image($conn, $imagePath, $pid);
        }
    }
}
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
<title>แก้ไขสินค้า — WorkSync</title></head><body>
<?php include 'navbar.php'; ?>
<div class="container" style="max-width:760px">
  <div class="box" style="margin-top:24px;padding:24px">
    <h2 style="margin-bottom:20px">แก้ไขสินค้า</h2>
    <?php if ($err): ?><div style="background:#fdf0f2;color:#c8102e;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-weight:600">✕ <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div style="background:#eaf6f0;color:#0f9b5e;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-weight:600">✓ <?= htmlspecialchars($ok) ?></div><?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_tag() ?>
      <div style="margin-bottom:14px">
        <label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">รูปภาพปัจจุบัน</label>
        <div style="display:flex;align-items:center;gap:16px">
          <img src="<?= img_src($product['image']) ?>" id="imgPreview" style="width:80px;height:80px;object-fit:cover;border-radius:10px;border:1px solid #eee">
          <div><input type="file" name="image" accept="image/*" onchange="prev(this)" style="font-size:13px">
          <div style="font-size:11px;color:#aaa;margin-top:3px">JPG/PNG/WEBP · ไม่เกิน 2MB</div></div>
        </div>
      </div>
      <div style="margin-bottom:14px"><label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">ชื่อสินค้า *</label>
      <input class="input" name="title" maxlength="255" required style="width:100%" value="<?= htmlspecialchars($product['title'],ENT_QUOTES,'UTF-8') ?>"></div>
      <div style="margin-bottom:14px"><label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">หมวดหมู่</label>
      <select class="input" name="category_id" id="categorySelect" style="width:100%"><option value="">— ไม่ระบุ —</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$product['category_id']===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['icon'].' '.$c['name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div>

      <div id="priceRecoBox" style="display:none;margin-bottom:14px;padding:14px 16px;background:#eef6ff;border:1px solid #cfe4fb;border-radius:12px">
        <div style="font-size:12.5px;font-weight:700;color:#0d2137;margin-bottom:8px">💡 ราคาแนะนำจากข้อมูลตลาด</div>
        <div id="priceRecoContent" style="font-size:12px;color:#5f6b7a"></div>
      </div>
      <div style="margin-bottom:14px"><label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">คำอธิบาย</label>
      <textarea class="input" name="description" rows="4" style="width:100%;font-family:inherit"><?= htmlspecialchars($product['description']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
        <div><label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">ราคา (฿) *</label>
        <input class="input" type="number" name="price" step="0.01" min="0.01" required style="width:100%" value="<?= number_format((float)$product['price'],2,'.','') ?>"></div>
        <div><label style="font-size:13px;font-weight:700;display:block;margin-bottom:5px">สต็อก *</label>
        <input class="input" type="number" name="stock" min="0" required style="width:100%" value="<?= (int)$product['stock'] ?>"></div>
      </div>
      <div style="border:1.5px solid rgba(15,155,94,.25);background:#f3fbf7;border-radius:12px;padding:16px 18px;margin-bottom:20px">
        <label style="display:flex;align-items:center;gap:8px;font-weight:700;cursor:pointer;margin-bottom:4px">
          <input type="checkbox" name="is_lendable" value="1" id="lendToggle" style="width:16px;height:16px" <?= !empty($lendInfo['is_lendable']) ? 'checked' : '' ?>>
          🤝 เปิดให้ยืมสินค้านี้ (นอกเหนือจากขาย)
        </label>
        <div style="font-size:12px;color:#7a8fa6;margin-bottom:12px">
          ผู้ซื้อที่มี Trust Score ตั้งแต่ 40 ขึ้นไปจะยืมสินค้านี้ได้ผ่านหน้า "ยืม-คืนสินค้าชุมชน"
        </div>
        <div id="lendFields" style="display:<?= !empty($lendInfo['is_lendable']) ? 'grid' : 'none' ?>;gap:14px;grid-template-columns:1fr 1fr">
          <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">ยืมได้สูงสุด (วัน)</label>
            <input class="input" type="number" name="lending_days_limit" min="1" max="30" style="width:100%" value="<?= (int)($lendInfo['lending_days_limit'] ?? 7) ?>">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">เงินมัดจำ (บาท)</label>
            <input class="input" type="number" name="deposit_required" min="0" step="0.01" style="width:100%" value="<?= number_format((float)($lendInfo['deposit_required'] ?? 0), 2, '.', '') ?>">
          </div>
          <div style="grid-column:1/-1">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">หมายเหตุการยืม (ถ้ามี)</label>
            <input class="input" name="lending_note" maxlength="300" placeholder="เช่น ต้องนัดรับ-คืนด้วยตัวเอง" style="width:100%" value="<?= htmlspecialchars($lendInfo['lending_note'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px">
        <button class="btn" type="submit" style="flex:1;padding:10px">บันทึก</button>
        <a href="dashboard.php" class="btn" style="background:#777;text-decoration:none;padding:10px 20px">ยกเลิก</a>
      </div>
    </form>
    <form method="POST" action="delete_product.php" onsubmit="return confirm('ลบสินค้านี้?')" style="margin-top:16px;padding-top:16px;border-top:1px solid #eee">
      <?= csrf_tag() ?><input type="hidden" name="product_id" value="<?= $pid ?>">
      <button class="btn" type="submit" style="background:#c8102e;width:100%;padding:8px">ลบสินค้านี้ถาวร</button>
    </form>
  </div>
</div>
<script>
function prev(i){const r=new FileReader();r.onload=e=>document.getElementById('imgPreview').src=e.target.result;r.readAsDataURL(i.files[0]);}
const lendToggle = document.getElementById('lendToggle');
const lendFields = document.getElementById('lendFields');
lendToggle.addEventListener('change', () => { lendFields.style.display = lendToggle.checked ? 'grid' : 'none'; });

const categorySelect = document.getElementById('categorySelect');
const priceInputEl   = document.querySelector('input[name="price"]');
const recoBox        = document.getElementById('priceRecoBox');
const recoContent    = document.getElementById('priceRecoContent');

async function loadPriceReco() {
  const catId = categorySelect.value;
  if (!catId) { recoBox.style.display = 'none'; return; }
  try {
    const res  = await fetch('price_recommend.php?cat_id=' + encodeURIComponent(catId));
    const data = await res.json();
    if (!data.ok) { recoBox.style.display = 'none'; return; }

    let html = `<div style="margin-bottom:10px;">${data.tip}</div><div style="display:flex;gap:8px;flex-wrap:wrap;">`;
    data.strategies.forEach(s => {
      html += `<button type="button" class="price-reco-btn" data-price="${s.price}"
                 style="flex:1;min-width:150px;text-align:left;padding:9px 12px;border-radius:10px;cursor:pointer;
                        border:1.5px solid ${s.recommended ? '#0d2137' : '#dce3ea'};background:#fff;">
                 <div style="font-size:11.5px;font-weight:700;color:#3d5066;">${s.name}</div>
                 <div style="font-size:15px;font-weight:800;color:#0d2137;">฿${Number(s.price).toLocaleString()}</div>
               </button>`;
    });
    html += '</div>';
    recoContent.innerHTML = html;
    recoBox.style.display = 'block';

    recoContent.querySelectorAll('.price-reco-btn').forEach(btn => {
      btn.addEventListener('click', () => { priceInputEl.value = btn.dataset.price; });
    });
  } catch (e) {
    recoBox.style.display = 'none';
  }
}
categorySelect.addEventListener('change', loadPriceReco);
if (categorySelect.value) loadPriceReco();
</script>
</body></html>
