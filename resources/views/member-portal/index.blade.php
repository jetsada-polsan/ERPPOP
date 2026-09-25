<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>สมาชิกของฉัน | Popstar</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
      :root{font-family:ui-sans-serif,system-ui,sans-serif;color:#172033;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.shell{max-width:520px;margin:auto;min-height:100vh;padding:20px 16px 88px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.brand{font-weight:800;color:#0b7285}.muted{color:#697386;font-size:13px}.hero{background:linear-gradient(135deg,#0b7285,#145da0);color:#fff;border-radius:24px;padding:24px;box-shadow:0 12px 30px #0b72852b}.hero h1{font-size:24px;margin:6px 0}.tier{display:inline-block;background:#ffffff2b;border-radius:99px;padding:5px 10px;font-size:12px}.points{font-size:40px;font-weight:800;margin:20px 0 2px}.bar{height:8px;background:#ffffff40;border-radius:8px;overflow:hidden}.bar i{display:block;height:100%;width:72%;background:#ffd166}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:18px}.card{background:#fff;border:1px solid #e7ebf1;border-radius:18px;padding:16px;min-height:92px;box-shadow:0 4px 14px #1720330b}.card b{display:block;font-size:20px;margin-top:8px}.section{margin-top:22px}.section h2{font-size:17px;margin:0 0 10px}.empty{color:#697386;font-size:13px}.nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);max-width:520px;width:100%;background:#fff;border-top:1px solid #e7ebf1;display:flex;justify-content:space-around;padding:10px 8px calc(10px + env(safe-area-inset-bottom))}.nav button{border:0;background:transparent;color:#697386;font-size:12px}.nav button.active{color:#0b7285;font-weight:700}.loading{padding:40px;text-align:center}.error{background:#fff0f0;color:#a33;padding:14px;border-radius:14px}.logout{border:0;background:transparent;color:#697386}
    </style>
</head>
<body><main class="shell">
  <div class="top"><div><div class="brand">POPSTAR MEMBER</div><div class="muted">สิทธิประโยชน์ของคุณ</div></div><button class="logout" id="logout">ออก</button></div>
  <div id="app" class="loading">กำลังตรวจสอบสมาชิก...</div>
</main><nav class="nav"><button class="active">⭐<br>แต้ม</button><button>🎟️<br>คูปอง</button><button>🎁<br>รางวัล</button><button>🧾<br>ซื้อ</button></nav>
<script>
const LIFF_ID=@json($liffId); const app=document.querySelector('#app');
async function get(path){const r=await fetch('/api/member/me'+path,{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('กรุณาเปิดหน้านี้จาก LINE OA');return r.json()}
async function boot(){try{
  if(LIFF_ID){await liff.init({liffId:LIFF_ID});if(!liff.isLoggedIn())return liff.login();const token=liff.getIDToken();const auth=await fetch('/api/member/auth/liff',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify({id_token:token})});if(!auth.ok)throw new Error((await auth.json()).message||'เข้าสู่ระบบสมาชิกไม่สำเร็จ')}
  const [{member},points,history,purchases]=await Promise.all([get(''),get('/points'),get('/point-history'),get('/purchases')]);
  app.className='';app.innerHTML=`<section class="hero"><span class="tier">${points.tier}</span><h1>${member.name}</h1><div class="muted" style="color:#d9f4f6">รหัสสมาชิก ${member.member_code}</div><div class="points">${points.points.toLocaleString()} <small style="font-size:15px">Points</small></div><div class="bar"><i></i></div></section><div class="grid"><div class="card">⭐ แต้ม<b>${points.points.toLocaleString()}</b><span class="muted">แต้มคงเหลือ</span></div><div class="card">🧾 ซื้อ<b>${purchases.items.length}</b><span class="muted">รายการล่าสุด</span></div></div><section class="section"><h2>กิจกรรมแต้มล่าสุด</h2>${history.items.length?history.items.slice(0,5).map(x=>`<div class="card" style="min-height:auto;margin:8px 0"><b style="font-size:15px">${x.direction==='earn'?'+':'-'}${x.points.toLocaleString()} แต้ม</b><span class="muted">${x.note||'รายการแต้ม'} · ${new Date(x.created_at).toLocaleDateString('th-TH')}</span></div>`).join(''):'<div class="card empty">ยังไม่มีรายการแต้ม</div>'}</section>`;
}catch(e){app.innerHTML=`<div class="error">${e.message}</div>`}}
document.querySelector('#logout').onclick=async()=>{await fetch('/api/member/logout',{method:'POST'});location.reload()};boot();
</script></body></html>
