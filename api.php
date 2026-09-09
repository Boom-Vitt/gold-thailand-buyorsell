<?php
// ราคาทองรูปพรรณ เยาวราช — ตัวดึงข้อมูล + JSON API (ไฟล์เดียว)
//   GET  api.php            → คืน data/prices.json (refresh ราคาล่าสุดให้ถ้าเก่ากว่า STALE_SEC)
//   GET  api.php?cron=1     → บังคับ refresh (ใช้กับ hPanel cron ผ่าน curl) | CLI: php api.php cron
//   GET  api.php?seed=1     → สร้างประวัติย้อนหลัง 5 ปี (ทำงานเฉพาะตอน history ยังว่าง) | CLI: php api.php seed
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');

const DATA_FILE = __DIR__ . '/data/prices.json';
const SEED_FILE = __DIR__ . '/data/seed.json';
const STALE_SEC = 600;                       // refresh ราคาล่าสุดอย่างมากทุก 10 นาที
const G_PER_BAHT_BAR = 15.244;               // 1 บาททองคำแท่ง
const G_PER_OZ = 31.1035;
const PURITY = 0.965;

const SRC_LATEST = 'https://api.chnwt.dev/thai-gold-api/latest';                  // ราคาสมาคมค้าทองคำ (community API, CORS *)
const SRC_XAU    = 'https://api.gold-api.com/price/XAU';                           // spot USD/oz (fallback)
const SRC_FX     = 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=THB';   // USD/THB (ECB, วันทำการ)
const SRC_FX_HIST  = 'https://api.frankfurter.dev/v1/%s..?base=USD&symbols=THB';
const SRC_XAU_HIST = 'https://query1.finance.yahoo.com/v8/finance/chart/GC=F?range=5y&interval=1d';

function http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (thai-gold-site)']);
        $r = curl_exec($c);
        $ok = curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
        return ($ok && is_string($r)) ? $r : null;
    }
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 25, 'user_agent' => 'Mozilla/5.0 (thai-gold-site)']]));
    return $r === false ? null : $r;
}
function jget(string $url): ?array { $r = http_get($url); $j = $r ? json_decode($r, true) : null; return is_array($j) ? $j : null; }
function num(string $s): float { return (float) str_replace(',', '', $s); }

// prices.json = ไฟล์ที่ server เขียน (ไม่อยู่ใน git); seed.json = ข้อมูลตั้งต้นที่มากับ repo
function load(): array {
    $f = is_file(DATA_FILE) ? DATA_FILE : SEED_FILE;
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($j) ? $j : ['updated_at' => null, 'latest' => null, 'spot' => null, 'history' => []];
}
function save(array $d): void {
    if (!is_dir(dirname(DATA_FILE))) mkdir(dirname(DATA_FILE), 0755, true);
    $tmp = DATA_FILE . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, DATA_FILE);
}

// ราคาล่าสุดจากสมาคมค้าทองคำ (ผ่าน api.chnwt.dev). gold = ทองรูปพรรณ, gold_bar = ทองคำแท่ง, buy = รับซื้อ, sell = ขายออก
function fetch_latest(): ?array {
    $j = jget(SRC_LATEST);
    $p = $j['response']['price'] ?? null;
    if (!$p) return null;
    preg_match('/(\d{2}):(\d{2})/', $j['response']['update_time'] ?? '', $t);
    preg_match('/ครั้งที่\s*(\d+)/u', $j['response']['update_time'] ?? '', $n);
    preg_match('#(\d{2})/(\d{2})/(\d{4})#', $j['response']['update_date'] ?? '', $d);   // dd/mm/พ.ศ.
    return [
        'date'     => $d ? sprintf('%04d-%s-%s', (int) $d[3] - 543, $d[2], $d[1]) : date('Y-m-d'),
        'time'     => $t ? "$t[1]:$t[2]" : date('H:i'),
        'round'    => $n ? (int) $n[1] : null,
        'orn_buy'  => num($p['gold']['buy']),  'orn_sell' => num($p['gold']['sell']),
        'bar_buy'  => num($p['gold_bar']['buy']), 'bar_sell' => num($p['gold_bar']['sell']),
        'source'   => 'สมาคมค้าทองคำ (api.chnwt.dev)', 'est' => false,
    ];
}
function fetch_spot(): ?array {
    $x = jget(SRC_XAU); $f = jget(SRC_FX);
    if (!isset($x['price'], $f['rates']['THB'])) return null;
    return ['xau_usd' => (float) $x['price'], 'usd_thb' => (float) $f['rates']['THB'], 'at' => date('c')];
}
function formula_bar(float $xau, float $thb): float { return $xau * $thb * G_PER_BAHT_BAR / G_PER_OZ * PURITY; }

// ถ้าสมาคมล่ม: ประมาณจาก spot × calibration ของวันล่าสุดที่มีราคาจริง
function estimate_latest(array $spot, ?array $ref): ?array {
    if (!$ref) return null;
    $bar = formula_bar($spot['xau_usd'], $spot['usd_thb']) * $ref['calib'];
    return ['date' => date('Y-m-d'), 'time' => date('H:i'), 'round' => null,
        'orn_buy' => round($bar * $ref['orn_buy_ratio'], 2), 'orn_sell' => round($bar * $ref['orn_sell_ratio'], -1),
        'bar_buy' => round($bar - 200, -1), 'bar_sell' => round($bar, -1),
        'source' => 'ประมาณจากราคา spot (สมาคมยังไม่อัปเดต)', 'est' => true];
}
// สัดส่วนที่ใช้แปลงราคาสูตร → ราคาสมาคม และ ทองแท่ง → ทองรูปพรรณ
function calibration(array $d): ?array {
    $l = $d['latest']; $s = $d['spot'];
    if (!$l || $l['est'] || !$s) return null;
    return ['calib' => $l['bar_sell'] / formula_bar($s['xau_usd'], $s['usd_thb']),
            'orn_buy_ratio' => $l['orn_buy'] / $l['bar_sell'], 'orn_sell_ratio' => $l['orn_sell'] / $l['bar_sell']];
}

function upsert_day(array &$hist, array $row): void {
    foreach ($hist as $i => $h) {
        if ($h['d'] === $row['d']) { if (!($row['est'] && !$h['est'])) $hist[$i] = $row; return; }   // ราคาจริงไม่ถูกทับด้วยค่าประมาณ
    }
    $hist[] = $row;
    usort($hist, fn($a, $b) => strcmp($a['d'], $b['d']));
}

function refresh(array $d): array {
    $spot = fetch_spot(); if ($spot) $d['spot'] = $spot;
    $latest = fetch_latest();
    if (!$latest && $spot) $latest = estimate_latest($spot, calibration($d));
    if ($latest) {
        $d['latest'] = $latest;
        upsert_day($d['history'], ['d' => $latest['date'], 'orn_buy' => $latest['orn_buy'], 'orn_sell' => $latest['orn_sell'],
            'bar_buy' => $latest['bar_buy'], 'bar_sell' => $latest['bar_sell'], 'est' => $latest['est']]);
    }
    $d['updated_at'] = date('c');
    return $d;
}

// ประวัติ 5 ปี "โดยประมาณ" จาก gold futures (Yahoo GC=F) × USD/THB (frankfurter) × calibration วันนี้ — ติดธง est=true
function seed(array $d): array {
    if (count($d['history']) > 30) return $d;                        // มีข้อมูลแล้ว ไม่ทำซ้ำ
    $d = refresh($d);
    $ref = calibration($d);
    if (!$ref) throw new RuntimeException('seed ต้องการราคาจริง + spot ก่อน');
    $y = jget(SRC_XAU_HIST);
    $ts = $y['chart']['result'][0]['timestamp'] ?? null;
    $cl = $y['chart']['result'][0]['indicators']['quote'][0]['close'] ?? null;
    if (!$ts) throw new RuntimeException('ดึงประวัติทองจาก Yahoo ไม่ได้');
    $fx = jget(sprintf(SRC_FX_HIST, date('Y-m-d', $ts[0] - 7 * 86400)))['rates'] ?? null;
    if (!$fx) throw new RuntimeException('ดึงประวัติ USD/THB ไม่ได้');
    ksort($fx);
    $fxDates = array_keys($fx); $fxi = 0; $thb = null;
    foreach ($ts as $i => $t) {
        if ($cl[$i] === null) continue;
        $day = date('Y-m-d', $t);
        while ($fxi < count($fxDates) && $fxDates[$fxi] <= $day) $thb = $fx[$fxDates[$fxi++]]['THB'];   // ใช้เรทล่าสุดที่ไม่เกินวันนั้น
        if ($thb === null) continue;
        $bar = formula_bar((float) $cl[$i], (float) $thb) * $ref['calib'];
        upsert_day($d['history'], ['d' => $day, 'orn_buy' => round($bar * $ref['orn_buy_ratio'], 2),
            'orn_sell' => round($bar * $ref['orn_sell_ratio'], -1), 'bar_buy' => round($bar - 200, -1), 'bar_sell' => round($bar, -1), 'est' => true]);
    }
    return $d;
}

// ---- main ----
$cli = PHP_SAPI === 'cli';
$cmd = $cli ? ($argv[1] ?? '') : (isset($_GET['seed']) ? 'seed' : (isset($_GET['cron']) ? 'cron' : ''));
$d = load();
$stale = !$d['updated_at'] || time() - strtotime($d['updated_at']) > STALE_SEC;
$lock = fopen(DATA_FILE . '.lock', 'c');
try {
    if ($cmd === 'seed') { if (flock($lock, LOCK_EX)) { $d = seed($d); save($d); } }
    elseif ($cmd === 'cron' || $stale) { if (flock($lock, LOCK_EX | LOCK_NB)) { $d = refresh($d); save($d); } }
} catch (Throwable $e) {
    if ($cli) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    $d['error'] = $e->getMessage();
}
if ($cli) { echo json_encode(['updated_at' => $d['updated_at'], 'latest' => $d['latest'], 'history_days' => count($d['history'])], JSON_UNESCAPED_UNICODE), "\n"; exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
