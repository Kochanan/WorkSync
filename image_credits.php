<?php
/**
 * image_credits.php — หน้าเครดิตรูปภาพสินค้าตัวอย่าง
 *
 * รูปสินค้าตัวอย่างในระบบนำมาจาก Wikimedia Commons
 * บางรูปใช้สัญญาอนุญาตแบบ CC BY หรือ CC BY-SA ซึ่งบังคับว่า
 * ต้องระบุชื่อผู้สร้างและชนิดสัญญาอนุญาตกำกับไว้ หน้านี้จึงมีไว้เพื่อการนั้น
 *
 * ถ้าเปลี่ยนรูปสินค้าเป็นรูปถ่ายของตัวเองแล้ว ให้ลบรายการนั้นออกจากตารางด้านล่าง
 */
require_once 'init.php';

$credits = [
    ['file' => 'p2-mangosteen.jpg',  'product' => 'มังคุดคัดพิเศษ',       'title' => 'Mangosteen', 'author' => 'Michael', 'license' => 'Public domain'],
    ['file' => 'p3-rambutan.jpg',    'product' => 'เงาะโรงเรียน',          'title' => 'Rambutan', 'author' => 'ArionStar', 'license' => 'CC0'],
    ['file' => 'p4-durian.jpg',      'product' => 'ทุเรียนหมอนทองแกะพู',  'title' => 'Durian di Alun-Alun Jombang', 'author' => 'Abdul Hanif Arromy', 'license' => 'CC BY 4.0'],
    ['file' => 'p5-honey.jpg',       'product' => 'น้ำผึ้งดอกลำไย',        'title' => 'Off With Your Head', 'author' => 'cogdogblog', 'license' => 'CC BY 2.0'],
    ['file' => 'p6-tshirt.jpg',      'product' => 'เสื้อยืดคอกลม',         'title' => 'Clothes shirts pants in Philippines', 'author' => 'Judgefloro', 'license' => 'CC0'],
    ['file' => 'p14-gardentool.jpg', 'product' => 'ชุดเครื่องมือทำสวน',   'title' => 'Radius weeder and dandelion', 'author' => 'John11b', 'license' => 'CC0'],
    ['file' => 'p17-ointment.jpg',   'product' => 'ยาหม่องไพล',            'title' => 'Betamethasone butyrate propionate', 'author' => '松岡明芳', 'license' => 'CC BY-SA 4.0'],
    ['file' => 'p8-totebag.jpg',     'product' => 'กระเป๋าผ้าแคนวาส',     'title' => 'Tote bag', 'author' => 'Zardozi', 'license' => 'CC BY 4.0'],
    ['file' => 'p9-earbuds.jpg',     'product' => 'หูฟังบลูทูธ',           'title' => 'Wireless earbuds', 'author' => '—', 'license' => 'Public domain'],
    ['file' => 'p10-usbc-cable.jpg', 'product' => 'สายชาร์จ USB-C',        'title' => 'USB-C cable', 'author' => 'Fructibus', 'license' => 'CC0'],
    ['file' => 'p11-powerbank.jpg',  'product' => 'พาวเวอร์แบงก์',         'title' => 'Power bank', 'author' => 'Santeri Viinamäki', 'license' => 'CC BY-SA 4.0'],
    ['file' => 'p12-mouse.png',      'product' => 'เมาส์ไร้สาย',           'title' => 'Computer mouse', 'author' => 'Crispy', 'license' => 'CC0'],
    ['file' => 'p13-flowerpot.jpg',  'product' => 'กระถางต้นไม้ดินเผา',   'title' => 'Stack of three flowerpot', 'author' => '—', 'license' => 'CC BY-SA 3.0'],
    ['file' => 'p15-desklamp.jpg',   'product' => 'โคมไฟตั้งโต๊ะ',         'title' => 'Electric table lamp', 'author' => 'W.carter', 'license' => 'CC0'],
    ['file' => 'p16-soap.jpg',       'product' => 'สบู่สมุนไพร',           'title' => 'Handmade soap', 'author' => 'DimiTalen', 'license' => 'CC0'],
    ['file' => 'p18-football.jpg',   'product' => 'ลูกฟุตบอล',             'title' => 'Football match', 'author' => 'Clément Bucco-Lechat', 'license' => 'CC BY-SA 4.0'],
    ['file' => 'p19-yogamat.png',    'product' => 'เสื่อโยคะ',             'title' => 'Yoga mats', 'author' => 'Sha89sha', 'license' => 'CC0'],
    ['file' => 'p20-dumbbell.jpg',   'product' => 'ดัมเบลปรับน้ำหนัก',    'title' => 'Dumbbell', 'author' => 'Singlespeedfahrer', 'license' => 'CC0'],
    ['file' => 'p22-pens.jpg',       'product' => 'ชุดปากกาเจล',           'title' => 'Pen set', 'author' => 'Betongmandarin', 'license' => 'CC BY-SA 4.0'],
    ['file' => 'p23-notebook.jpg',   'product' => 'สมุดโน้ตปกแข็ง',        'title' => 'Notebook writing', 'author' => '—', 'license' => 'Public domain'],
    ['file' => 'p25-umbrella.jpg',   'product' => 'ร่มพับสองตอน',          'title' => 'Folding umbrella', 'author' => 'Swethasakthivel', 'license' => 'CC0'],
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
  <title>WorkSync — เครดิตรูปภาพ</title>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container" style="max-width:900px; margin-top:24px; padding-bottom:60px;">
  <div class="section-head">
    <div class="section-title"><span></span> เครดิตรูปภาพ</div>
  </div>

  <p style="color:var(--tx2); font-size:14px; line-height:1.8; margin-bottom:20px;">
    รูปสินค้าตัวอย่างในเว็บนี้นำมาจาก Wikimedia Commons
    รูปที่ใช้สัญญาอนุญาตแบบ CC BY หรือ CC BY-SA กำหนดให้ต้องระบุชื่อผู้สร้างและชนิดสัญญาอนุญาต
    จึงรวบรวมไว้ที่หน้านี้ ส่วนรูปทุเรียนหมอนทองเป็นภาพถ่ายของเจ้าของเว็บเอง
    และสินค้าที่ยังไม่มีรูปจริงจะแสดงเป็นภาพประจำหมวดหมู่ที่ทำขึ้นเอง
  </p>

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-md);">
      <thead>
        <tr>
          <th style="width:76px;">รูป</th>
          <th>สินค้าในเว็บ</th>
          <th>ชื่อภาพต้นฉบับ</th>
          <th>ผู้สร้าง</th>
          <th>สัญญาอนุญาต</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($credits as $c): ?>
          <tr>
            <td>
              <img src="assets/uploads/<?= htmlspecialchars($c['file'], ENT_QUOTES, 'UTF-8') ?>"
                   alt="" width="60" height="60"
                   style="width:60px; height:60px; object-fit:cover; border-radius:var(--r-sm); border:1px solid var(--border);">
            </td>
            <td><?= htmlspecialchars($c['product'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:var(--tx2);"><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:var(--tx2);"><?= htmlspecialchars($c['author'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="badge"><?= htmlspecialchars($c['license'], ENT_QUOTES, 'UTF-8') ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p style="color:var(--tx3); font-size:13px; margin-top:18px; line-height:1.7;">
    ค้นหาภาพต้นฉบับและรายละเอียดสัญญาอนุญาตฉบับเต็มได้ที่
    <a href="https://commons.wikimedia.org/" target="_blank" rel="noopener">commons.wikimedia.org</a>
  </p>
</div>
</body>
</html>
