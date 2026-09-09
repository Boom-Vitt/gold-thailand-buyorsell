# ราคาทองรูปพรรณ เยาวราช — เว็บสัญญาณ ซื้อ/รอ/ขาย

เว็บหน้าเดียวภาษาไทย (mobile-first) แสดงราคาทองรูปพรรณ 96.5% จากสมาคมค้าทองคำ
พร้อมสัญญาณ **ซื้อ / ทยอยซื้อ / รอ / พิจารณาขาย / ขาย** แบบ rule-based ที่โปร่งใส (ไม่ใช่ ML, ไม่ใช่การพยากรณ์)
กราฟย้อนหลัง 5 ปี, เครื่องคิดจุดคุ้มทุนของทองที่ถืออยู่, และผล backtest ของกฎเอง
โฮสต์บน **Hostinger shared hosting** (PHP + cron) — ไม่มี Node บน server, ไม่มี build step, ไม่มี framework, ไม่มี database

## โครงสร้าง (ไฟล์เว็บอยู่ที่ root ของ repo)

```
index.html            UI ทั้งหมด + JS render (Chart.js จาก cdnjs, ฟอนต์ Sarabun จาก Google Fonts)
signal.js             indicators / กฎคะแนน / breakEven / backtest — ใช้ทั้งใน browser และ node (self-test)
api.php               ดึงราคา + เก็บลง data/prices.json + คืน JSON ให้หน้าเว็บ (ไฟล์ backend ไฟล์เดียว)
data/seed.json        ข้อมูลตั้งต้นที่อยู่ใน git (history 5 ปี) — api.php อ่านไฟล์นี้เมื่อยังไม่มี prices.json
data/prices.json      ไฟล์ที่ server เขียนจริง — อยู่ใน .gitignore เพื่อไม่ให้ deploy ทับข้อมูลที่สะสมไว้
CLAUDE.md / .claude/  เอกสารและ subagent (ติดไปกับ repo แต่ web เข้าถึงไม่ได้)
```

**ห้ามย้ายไฟล์เว็บกลับเข้าโฟลเดอร์ `public_html/`** — Hostinger clone repo ทั้งก้อนลงใน public_html อยู่แล้ว
ถ้ามีโฟลเดอร์ซ้อน หน้าแรกจะ 403 และเว็บจะไปโผล่ที่ `/public_html/index.html` (เคยพลาดมาแล้ว)

หลักการ: อย่าเพิ่มไฟล์/ไลบรารี/ขั้นตอน build ถ้าไม่จำเป็นจริง ๆ — ถ้าจะเพิ่ม ให้ถามก่อน

## แหล่งข้อมูล (ตรวจสอบแล้ว 2026-09-08)

| ข้อมูล | Endpoint | หมายเหตุ |
|---|---|---|
| ราคาสมาคมค้าทองคำ (primary) | `https://api.chnwt.dev/thai-gold-api/latest` | community API ครอบ goldtraders.or.th, CORS `*`. shape: `response.price.gold` = **ทองรูปพรรณ**, `gold_bar` = ทองคำแท่ง; `buy` = **รับซื้อ**, `sell` = **ขายออก**; `update_date` เป็น dd/mm/**พ.ศ.**; `update_time` มี "ครั้งที่ N" |
| ราคาทองโลก spot (fallback) | `https://api.gold-api.com/price/XAU` | ไม่ต้องใช้ key, ไม่มี history |
| USD/THB | `https://api.frankfurter.dev/v1/latest?base=USD&symbols=THB` และ `/v1/YYYY-MM-DD..?base=USD&symbols=THB` | โดเมนเก่า `api.frankfurter.app` ตอนนี้ 301 — ใช้ `.dev` เท่านั้น. เรทวันทำการ ECB |
| ประวัติทองโลก 5 ปี (ใช้ตอน seed) | `https://query1.finance.yahoo.com/v8/finance/chart/GC=F?range=5y&interval=1d` | ต้องส่ง User-Agent; `XAUUSD=X` ใช้ไม่ได้ (404) |

สิ่งที่ **ใช้ไม่ได้** (อย่าเสียเวลาลองซ้ำ): goldtraders.or.th เปลี่ยนเป็น Next.js แล้ว หน้า `UpdatePriceList.aspx` / `DailyPrices.aspx` เป็น "Post Not Found",
ราคาโหลดฝั่ง client จาก API ที่ไม่เปิดเผย; `stooq.com` มี JS challenge บล็อก curl; `api.chnwt.dev` ไม่มี endpoint history

## ความรู้เฉพาะโดเมน (อย่าเดา — ตัวเลขเหล่านี้ใช้ในโค้ด)

- ทองไทยคิดราคาต่อ **1 บาททอง** ความบริสุทธิ์ **96.5%**; ทองคำแท่ง 1 บาท = **15.244 g**, ทองรูปพรรณ 1 บาท = **15.16 g**; 1 บาท = 4 สลึง
- สูตรราคาทองแท่งจากทองโลก: `XAUUSD × USDTHB × 15.244 / 31.1035 × 0.965` แล้วคูณ **calibration** (= ราคาสมาคมจริง ÷ ราคาสูตร ของวันที่มีราคาจริงล่าสุด, ประมาณ 1.00–1.02)
- ทองรูปพรรณ: **ขายออก** = ราคาที่ลูกค้าซื้อ (สูงกว่าทองแท่งราว 800), **รับซื้อ** = ราคาที่ร้านรับคืน (ต่ำกว่าทองแท่งราว 1,500) — สเปรดนี้คือต้นทุนหลักของผู้ซื้อ
- ร้านเยาวราชบวก **ค่ากำเหน็จ** ต่อชิ้น (300–1,500+ บาท แล้วแต่ลาย) และ **ไม่คืน** ตอนขายกลับ → สัญญาณ "ขาย" ต้องเทียบกับ `รับซื้อ ≥ ต้นทุน + กำเหน็จ + กำไรเป้า` (ดู `Signal.breakEven`)
- สมาคมอัปเดตราคาหลายครั้งต่อวัน (วันผันผวนมี 20+ ครั้ง) เริ่มราว 09:30; เว็บเก็บ **ค่าสุดท้ายของแต่ละวัน** ใน history
- สีตามธรรมเนียมตลาดไทย: **เขียว = ขึ้น, แดง = ลง**; วันที่แสดงเป็น พ.ศ. ผ่าน `toLocaleDateString('th-TH')`
- ทองไม่ใช่หลักทรัพย์ภายใต้ ก.ล.ต. แต่หน้าเว็บต้องมี disclaimer "ไม่ใช่คำแนะนำการลงทุน" เสมอ — ห้ามลบ

## กฎสัญญาณ (signal.js) — ถ้าจะแก้ ต้องแก้ทั้ง `score()` และรายการกฎที่แสดงบนหน้าเว็บพร้อมกัน

คำนวณจากซีรีส์ราคา **ขายออกทองรูปพรรณ** รายวัน. ฝั่งซื้อ 5 ข้อ / ฝั่งขาย 5 ข้อ ข้อละ 1 คะแนน:
ราคา vs SMA50 (±0.5%), vs SMA20 (±0.5%), RSI14 (<40 / >65), ตำแหน่งในช่วง 52 สัปดาห์ (<30% / >80%), ห่างจาก high 52 สัปดาห์ (≥5% ย่อ / ≤1.5% และกำลังขึ้น)
ผล: ซื้อ ≥4, ทยอยซื้อ =3, ขาย ≥4, พิจารณาขาย =3, อื่น ๆ = รอ. ใช้ margin ±0.5% เพราะราคานิ่ง ๆ ต้องไม่ให้คะแนนทั้งสองฝั่ง (bug ที่เคยเจอ)
`backtest()` เทียบ "ซื้อตามสัญญาณ" กับ "ซื้อทุกวัน" หลังหักสเปรด — แสดงบนหน้าเว็บเสมอเพื่อความซื่อสัตย์ อย่าซ่อน

## โครงสร้างข้อมูล (data/prices.json และ data/seed.json ใช้ shape เดียวกัน)

```
{ updated_at, spot:{xau_usd, usd_thb, at},
  latest:{date(ค.ศ.), time, round, orn_buy, orn_sell, bar_buy, bar_sell, source, est},
  history:[{d:"YYYY-MM-DD", orn_buy, orn_sell, bar_buy, bar_sell, est}] }   // เรียงวัน, 1 แถว/วัน
```
`est:true` = ค่าประมาณจากสูตร (seed 5 ปี หรือวันที่สมาคมล่ม) — กราฟวาดเป็นเส้นประ; แถวจริงจะไม่ถูกทับด้วยค่าประมาณ (`upsert_day`)

## รันในเครื่อง

```bash
/opt/homebrew/bin/php -S 127.0.0.1:8765 -t .        # เปิด http://127.0.0.1:8765
node -e "console.log(require('./signal.js').selftest())"    # self-test ของกฎ ต้องผ่านก่อน commit
/opt/homebrew/bin/php api.php cron                  # ดึงราคาล่าสุด
/opt/homebrew/bin/php api.php seed                  # สร้าง history ใหม่ (ทำงานเฉพาะเมื่อ history < 30 แถว)
```
PHP ในเครื่องเป็น 8.5 (brew) — Hostinger เป็น 8.x; อย่าใช้ฟีเจอร์ใหม่กว่า 8.1

## Deploy (GitHub → Hostinger)

- repo: `https://github.com/Boom-Vitt/gold-thailand-buyorsell` branch `main`
- เว็บ: `https://darkslategrey-mantis-832685.hostingersite.com`
- hPanel → Website → Deploy from GitHub ผูก repo นี้ไว้แล้ว มันจะ clone/pull **root ของ repo** ลงใน `public_html`
- ปล่อยงานใหม่: commit + อัปขึ้น GitHub แล้วกดปุ่ม Deploy ใน hPanel (หรือเปิด auto-deploy webhook)
- cron ที่ hPanel → Advanced → Cron Jobs ทุก 30 นาที เพื่อเก็บราคาปิดของทุกวันแม้ไม่มีคนเข้าเว็บ:
  `curl -s "https://darkslategrey-mantis-832685.hostingersite.com/api.php?cron=1" > /dev/null`

ตรวจหลัง deploy: หน้าแรกต้อง 200 และ `/api.php` ต้องคืน JSON ที่ `latest.date` เป็นวันนี้

ยืนยันแล้วบน host นี้ (2026-09-09): PHP รันได้, curl ออกเน็ตได้, เขียน `data/` ได้, `/.git/` ถูกบล็อค 403 อัตโนมัติ
`data/prices.json` ไม่อยู่ใน git → deploy ใหม่ไม่ทับข้อมูลที่สะสม และ git ไม่ conflict; ถ้าไฟล์หาย api.php กลับไปอ่าน `data/seed.json` เอง

## กติกาการแก้โค้ด

- แก้ signal → รัน selftest; แก้ api.php → `php -l api.php` แล้วรัน `php api.php cron` จริง; แก้ UI → เปิด preview ดูบนจอมือถือ
- ห้ามใส่ API key ในโค้ด (ตอนนี้ไม่มี key เลย — รักษาไว้แบบนี้)
- อย่าดึงข้อมูลถี่กว่า `STALE_SEC` (10 นาที) เพื่อไม่รบกวน community API
- ข้อความ UI เป็นภาษาไทยทั้งหมด; คำสัญญาณใช้ชุดเดิม: ควรซื้อ / ทยอยซื้อ / รอดูสถานการณ์ / พิจารณาขาย / จังหวะขาย
