// สัญญาณ ซื้อ/รอ/ขาย ทองรูปพรรณ — rule-based, โปร่งใส ไม่ใช่การพยากรณ์
// ใช้ได้ทั้งใน browser (window.Signal) และ node (module.exports) เพื่อรัน self-test
(function (root) {
  'use strict';

  const sma = (a, n) => a.length < n ? null : a.slice(-n).reduce((s, v) => s + v, 0) / n;

  function rsi(a, n = 14) {
    if (a.length < n + 1) return null;
    let g = 0, l = 0;
    for (let i = a.length - n; i < a.length; i++) {
      const d = a[i] - a[i - 1];
      if (d > 0) g += d; else l -= d;
    }
    if (g === 0 && l === 0) return 50;
    if (l === 0) return 100;
    return 100 - 100 / (1 + (g / n) / (l / n));
  }

  // ตำแหน่งราคาปัจจุบันในช่วง 52 สัปดาห์ (0 = ต่ำสุด, 1 = สูงสุด)
  function pctRank(a, n = 252) {
    const w = a.slice(-n);
    if (w.length < 60) return null;
    const lo = Math.min(...w), hi = Math.max(...w);
    return hi === lo ? 0.5 : (a[a.length - 1] - lo) / (hi - lo);
  }

  // prices: array ของราคาขายออกทองรูปพรรณรายวัน (เก่า → ใหม่)
  function indicators(prices) {
    const p = prices[prices.length - 1];
    const hi52 = Math.max(...prices.slice(-252));
    return {
      price: p,
      sma20: sma(prices, 20), sma50: sma(prices, 50), sma200: sma(prices, 200),
      rsi14: rsi(prices, 14),
      pct52: pctRank(prices, 252),
      drawdown: (hi52 - p) / hi52,           // ห่างจากจุดสูงสุด 52 สัปดาห์
      trend200: prices.length > 220 ? Math.sign(sma(prices, 200) - sma(prices.slice(0, -20), 200)) : 0,
    };
  }

  // กฎคะแนน: แต่ละข้อ +1 (แสดงบนหน้าเว็บทั้งหมด)
  function score(ind) {
    const buy = [
      ['ราคาต่ำกว่าเส้นเฉลี่ย 50 วัน', ind.sma50 != null && ind.price < ind.sma50 * 0.995],
      ['ราคาต่ำกว่าเส้นเฉลี่ย 20 วัน', ind.sma20 != null && ind.price < ind.sma20 * 0.995],
      ['RSI(14) ต่ำกว่า 40 (แรงขายมาก)', ind.rsi14 != null && ind.rsi14 < 40],
      ['อยู่ในโซนล่างของช่วง 52 สัปดาห์ (<30%)', ind.pct52 != null && ind.pct52 < 0.30],
      ['ย่อลงจากจุดสูงสุด 52 สัปดาห์เกิน 5%', ind.drawdown >= 0.05],
    ];
    const sell = [
      ['ราคาสูงกว่าเส้นเฉลี่ย 50 วัน', ind.sma50 != null && ind.price > ind.sma50 * 1.005],
      ['ราคาสูงกว่าเส้นเฉลี่ย 20 วัน', ind.sma20 != null && ind.price > ind.sma20 * 1.005],
      ['RSI(14) สูงกว่า 65 (แรงซื้อมาก)', ind.rsi14 != null && ind.rsi14 > 65],
      ['อยู่ในโซนบนของช่วง 52 สัปดาห์ (>80%)', ind.pct52 != null && ind.pct52 > 0.80],
      ['ใกล้จุดสูงสุด 52 สัปดาห์ (ภายใน 1.5%) และเพิ่งขึ้นมา', ind.drawdown <= 0.015 && ind.sma20 != null && ind.price > ind.sma20 * 1.005],
    ];
    const b = buy.filter(x => x[1]).length, s = sell.filter(x => x[1]).length;
    let verdict, tone;
    if (b >= 4)      { verdict = 'ควรซื้อ';            tone = 'buy'; }
    else if (b === 3) { verdict = 'ทยอยซื้อ';           tone = 'buy'; }
    else if (s >= 4)  { verdict = 'จังหวะขาย';          tone = 'sell'; }
    else if (s === 3) { verdict = 'พิจารณาขาย';         tone = 'sell'; }
    else              { verdict = 'รอดูสถานการณ์';      tone = 'wait'; }
    return { buy, sell, buyScore: b, sellScore: s, verdict, tone };
  }

  // จุดคุ้มทุนของทองที่ถืออยู่: ซื้อที่ราคาขายออก P บาท/บาททอง, กำเหน็จรวม K บาท, น้ำหนัก w บาททอง, เป้ากำไร t (0.05 = 5%)
  function breakEven(P, K, w, t, ornBuyNow) {
    const cost = P * w + K;
    const need = cost * (1 + (t || 0)) / w;        // ราคารับซื้อต่อบาททองที่ต้องได้
    const valueNow = ornBuyNow * w;
    return { cost, need, valueNow, profit: valueNow - cost, profitPct: (valueNow - cost) / cost, ok: ornBuyNow >= need };
  }

  // backtest ตรงไปตรงมา: ซื้อวันที่มีสัญญาณ (buyScore ≥ 3) ที่ราคาขายออก, ขายคืนที่ราคารับซื้ออีก h วัน — เทียบกับซื้อทุกวัน
  function backtest(rows, h = 90) {
    let sigN = 0, sigWin = 0, sigRet = 0, allN = 0, allWin = 0, allRet = 0;
    const sells = rows.map(r => r.orn_sell);
    for (let i = 60; i + h < rows.length; i++) {
      const ret = rows[i + h].orn_buy / rows[i].orn_sell - 1;
      allN++; allRet += ret; if (ret > 0) allWin++;
      if (score(indicators(sells.slice(0, i + 1))).buyScore >= 3) { sigN++; sigRet += ret; if (ret > 0) sigWin++; }
    }
    return { h, sigN, sigWinRate: sigN ? sigWin / sigN : null, sigAvg: sigN ? sigRet / sigN : null,
             allN, allWinRate: allN ? allWin / allN : null, allAvg: allN ? allRet / allN : null };
  }

  function selftest() {
    const eq = (a, b, m) => { if (Math.abs(a - b) > 1e-9) throw new Error(m + ': ' + a + ' != ' + b); };
    eq(sma([1, 2, 3, 4], 2), 3.5, 'sma');
    if (sma([1], 2) !== null) throw new Error('sma short');
    const up = Array.from({ length: 20 }, (_, i) => 100 + i);
    eq(rsi(up), 100, 'rsi all-up');
    if (!(rsi(up.map(v => 200 - v)) < 1)) throw new Error('rsi all-down');
    const flat = Array(300).fill(1000);
    const s = score(indicators(flat));
    if (s.verdict !== 'รอดูสถานการณ์') throw new Error('flat should wait, got ' + s.verdict);
    const dip = flat.concat(Array.from({ length: 30 }, (_, i) => 1000 - 4 * (i + 1)));   // ร่วง 12%
    if (score(indicators(dip)).tone !== 'buy') throw new Error('dip should be buy');
    const rally = flat.concat(Array.from({ length: 30 }, (_, i) => 1000 + 4 * (i + 1)));
    if (score(indicators(rally)).tone !== 'sell') throw new Error('rally should be sell');
    const be = breakEven(69400, 800, 1, 0, 67037.52);
    eq(be.need, 70200, 'breakeven'); if (be.ok) throw new Error('should not be ok');
    const rows = dip.map(v => ({ orn_sell: v, orn_buy: v - 2000 }));
    const bt = backtest(rows, 10); if (bt.allN <= 0) throw new Error('backtest');
    return 'signal.js selftest OK';
  }

  const api = { sma, rsi, pctRank, indicators, score, breakEven, backtest, selftest };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.Signal = api;
})(typeof window !== 'undefined' ? window : globalThis);
