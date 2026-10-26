const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert');const{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..');
const all=JSON.parse(execFileSync('php',['-r','require "includes/permissions.php";echo json_encode(portal_permission_matrix(true));'],{cwd:root,encoding:'utf8'}));
const html=execFileSync('php',['-r','require "includes/security.php";start_app_session();$_SESSION["user_id"]=1;require "app-shell.php";'],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});try{
  const page=await browser.newPage({viewport:{width:1440,height:1100}}),requests=[],errors=[];let permissions=all,fail=false,empty=false;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://127.0.0.1:4173/**',async route=>{
    const url=new URL(route.request().url()),q=url.searchParams;
    if(url.pathname.endsWith('/app.php'))return route.fulfill({contentType:'text/html',body:html});
    if(url.pathname.endsWith('/api.php')){
      requests.push(Object.fromEntries(q));
      if(q.get('resource')==='bootstrap')return route.fulfill({json:{user:{id:1,full_name:'Real Test Admin',username:'admin'},csrf_token:'test',access:{permissions},records:[],user_count:0}});
      if(q.get('resource')==='users')return route.fulfill({json:{users:[],assignable_roles:[]}});
      if(q.get('action')!=='dashboard')return route.fulfill({json:{available:false,message:'No drilldown test data'}});
      if(fail)return route.fulfill({status:500,json:{error:'Test connection unavailable'}});
      const machine=q.get('machine')||'ALL',duration=Number(q.get('duration_minutes')||1440),date=q.get('date')||'2026-10-22';
      return route.fulfill({json:{available:true,date,machine,window:{duration_minutes:duration,duration_hours:duration/60,window_start:date+' 00:00:00',window_end:'2026-10-23 00:00:00'},metrics:{total_fb:empty?0:53,productive_fb:empty?0:47,empty_fb:empty?0:6,pcs:empty?0:2460,total_surface_area:empty?0:2656.04},oee:{oee:0.6,availability:0.8,performance:0.75,quality:1,good_fb:47,productive_fb:47,planned_minutes:4320,planned_stop_minutes:0,run_minutes:3456,unplanned_minutes:864,ideal_output_minutes:2592,utilization_window:0.8},sync:[{source:'UNIT-1-APP-06',last_status:'ok',last_received_at:'2026-10-22 10:00:00'}],imports:[],recent_batches:empty||!permissions['oee-report']?.view?[]:[{line_code:'APP-06',part_code:'Actual part <test>',grade:'6063',used_colour:'BLACK-1',pcs:320,total_surface_area:346.2,is_productive:1,unloading_at:date+' 10:00:00'}],recent_downtime:empty||!permissions.downtime?.view?[]:[{line_code:'APP-06',ends_at:date+' 10:30:00',reason_label:'Hoist / movement',source:'gap',status:'proposed'}],downtime_breakdown:{items:empty?[]:[{reason_code:'HOIST',label:'Hoist / movement',event_type:'unplanned',minutes:864,percentage:100}]}}});
    }
    const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(!file.startsWith(root+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
    return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'application/javascript'});
  });
  await page.goto('http://127.0.0.1:4173/app.php#/dashboard');await page.waitForFunction(()=>document.getElementById('oee-value').textContent==='60.0%');
  await page.waitForFunction(()=>document.getElementById('dashboard-rack-rate').textContent==='88.7%',{},{timeout:3000});
  assert((await page.locator('#dashboard-batches-body').textContent()).includes('Actual part <test>'));
  assert.equal(await page.locator('#dashboard-batches-body test').count(),0,'Batch strings must not become markup');
  assert((await page.locator('#dashboard-stops-body').textContent()).includes('proposed'));
  assert((await page.locator('#dashboard-reasons').textContent()).includes('864.0 min'));
  assert.equal(await page.locator('#dashboard-run-bar').evaluate(el=>el.style.width),'80%');
  assert.equal(await page.locator('#dashboard-stopped-share').textContent(),'20.0%');
  assert(!(await page.locator('[data-view="dashboard"]').textContent()).includes('Hourly production'));
  assert.equal(await page.locator('[data-view="dashboard"] canvas').count(),0);
  await page.locator('#dashboard-machine').selectOption('APP-07');await page.locator('#dashboard-date').fill('2026-10-21');await page.locator('#dashboard-duration').selectOption('720');await page.locator('#dashboard-start-time').fill('12:00');await page.locator('#dashboard-refresh').click();
  await page.waitForFunction(()=>document.getElementById('dashboard-oee-detail').textContent.includes('APP-07'));
  await page.locator('[data-dashboard-link="report"]').first().click();
  assert.equal(await page.locator('#report-machine-filter').inputValue(),'APP-07');assert.equal(await page.locator('#report-date').inputValue(),'2026-10-21');assert.equal(await page.locator('#report-duration').inputValue(),'720');
  await page.locator('[data-nav="dashboard"]').click();
  await page.locator('#dashboard-machine').selectOption('ALL');await page.locator('#dashboard-date').fill('2026-10-22');await page.locator('#dashboard-duration').selectOption('1440');await page.locator('#dashboard-refresh').click();
  await page.waitForFunction(()=>document.getElementById('dashboard-oee-detail').textContent.includes('All machines'));
  for(const width of[1440,1024,768,320]){await page.setViewportSize({width,height:1100});await page.waitForTimeout(250);for(const id of['dashboard-machine','dashboard-date','dashboard-duration','dashboard-start-time','dashboard-refresh']){const b=await page.locator('#'+id).boundingBox();assert(b&&b.x>=0&&b.x+b.width<=width+1,id+' overflows '+width);}}
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Mobile dashboard has page-wide overflow');
  await page.screenshot({path:'/tmp/pns-dashboard-updated-mobile.png'});
  await page.setViewportSize({width:1440,height:1100});await page.waitForTimeout(250);await page.screenshot({path:'/tmp/pns-dashboard-updated.png',fullPage:true});
  empty=true;await page.locator('#dashboard-refresh').click();await page.waitForFunction(()=>document.getElementById('dashboard-batches-body').textContent.includes('No batches'));
  fail=true;await page.locator('#dashboard-refresh').click();await page.waitForFunction(()=>document.getElementById('oee-dashboard-alert').textContent.includes('Test connection unavailable'));
  assert.equal(await page.locator('#oee-value').textContent(),'—','Failure must not leave stale KPIs');assert(!(await page.locator('#dashboard-refresh').isDisabled()));
  fail=false;empty=false;permissions={dashboard:{view:true}};const count=requests.length;await page.goto('http://127.0.0.1:4173/app.php?scope=dashboard-only#/dashboard');await page.waitForFunction(()=>document.getElementById('oee-value').textContent==='60.0%');
  assert(!(await page.locator('.pns-breakdown-section').isVisible()));assert(!(await page.locator('#dashboard-batches-body').isVisible()));assert(!(await page.locator('#dashboard-stops-body').isVisible()));assert(await page.locator('#dashboard-detail-message').isVisible());
  assert(!requests.slice(count).some(r=>['report','downtime','masters'].includes(r.action)),'Dashboard-only must not fetch restricted endpoints');
  assert.deepEqual(errors,[]);console.log('Reference dashboard browser tests passed: live rendering, no hourly chart, safe text, scoped details, filters/drilldown, empty/errors and responsive layout.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
