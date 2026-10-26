const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert');const{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..');
const all=JSON.parse(execFileSync('php',['-r','require "includes/permissions.php";echo json_encode(portal_permission_matrix(true));'],{cwd:root,encoding:'utf8'}));
const html=execFileSync('php',['-r','require "includes/security.php";start_app_session();$_SESSION["user_id"]=1;require "app-shell.php";'],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});try{
  const page=await browser.newPage({viewport:{width:1440,height:1100}}),requests=[],errors=[],saves=[];let permissions=all,fail=false,empty=false,tagFailure=false,reasonFailure=false,tagged=false;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://127.0.0.1:4173/**',async route=>{
    const url=new URL(route.request().url()),q=url.searchParams;
    if(url.pathname.endsWith('/app.php'))return route.fulfill({contentType:'text/html',body:html});
    if(url.pathname.endsWith('/api.php')){
      requests.push(Object.fromEntries(q));
      if(q.get('resource')==='bootstrap')return route.fulfill({json:{user:{id:1,full_name:'Real Test Admin',username:'admin'},csrf_token:'test',access:{permissions},records:[],user_count:0}});
      if(q.get('resource')==='users')return route.fulfill({json:{users:[],assignable_roles:[]}});
      if(q.get('action')==='masters')return route.fulfill(reasonFailure?{status:500,json:{error:'Reasons unavailable'}}:{json:{available:true,reasons:[{code:'HOIST',label:'Hoist / movement',is_active:1},{code:'CUSTOM_STOP',label:'Custom stop <reason>',is_active:1},{code:'INACTIVE',label:'Inactive',is_active:0}],cycles:[],thresholds:[]}});
      if(q.get('action')==='downtime-tag'){
        assert.equal(route.request().method(),'POST');assert.equal(route.request().headers()['x-csrf-token'],'test');saves.push(route.request().postDataJSON());
        if(tagFailure)return route.fulfill({status:422,json:{error:'Choose an active registered downtime reason.'}});
        tagged=true;return route.fulfill({json:{ok:true,event_id:41,updated:true}});
      }
      if(q.get('action')!=='dashboard')return route.fulfill({json:{available:false,message:'No drilldown test data'}});
      if(fail)return route.fulfill({status:500,json:{error:'Test connection unavailable'}});
      const machine=q.get('machine')||'ALL',duration=Number(q.get('duration_minutes')||1440),date=q.get('date')||'2026-10-22';
      const stop={id:41,line_code:'APP-07',starts_at:'2026-10-21 23:55:17',ends_at:date+' 00:15:43',reason_code:tagged?'CUSTOM_STOP':'HOIST',reason_label:tagged?'Custom stop <reason>':'Hoist / movement',event_type:tagged?'planned':'unplanned',notes:tagged?'Reviewed <notes>':'Original notes',source:'gap',status:tagged?'confirmed':'proposed'};
      return route.fulfill({json:{available:true,date,machine,window:{duration_minutes:duration,duration_hours:duration/60,window_start:date+' 00:00:00',window_end:'2026-10-23 00:00:00'},metrics:{total_fb:empty?0:53,productive_fb:empty?0:47,empty_fb:empty?0:6,pcs:empty?0:2460,total_surface_area:empty?0:2656.04},oee:{oee:0.6,availability:0.8,performance:0.75,quality:1,good_fb:47,productive_fb:47,planned_minutes:4320,planned_stop_minutes:0,run_minutes:3456,unplanned_minutes:864,ideal_output_minutes:2592,utilization_window:0.8},sync:[{source:'UNIT-1-APP-06',last_status:'ok',last_received_at:'2026-10-22 10:00:00'}],imports:[],recent_batches:empty||!permissions['oee-report']?.view?[]:[{line_code:'APP-06',part_code:'Actual part <test>',grade:'6063',used_colour:'BLACK-1',pcs:320,total_surface_area:346.2,is_productive:1,unloading_at:date+' 10:00:00'}],recent_downtime:empty||!permissions.downtime?.view?[]:[stop],downtime_breakdown:{items:empty?[]:[{reason_code:stop.reason_code,label:stop.reason_label,event_type:stop.event_type,minutes:864,percentage:100}]}}});
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
  assert(await page.locator('#dashboard-reasons').evaluate(el=>el.closest('.pns-operations')?.firstElementChild.contains(el)===true),'Downtime by reason must replace Recent batches as the first Live operations card');
  assert(await page.locator('#dashboard-batches-body').evaluate(el=>el.closest('.pns-batches-section')!==null),'Recent batches must move into the lower production section');
  assert.equal(await page.locator('#dashboard-run-bar').evaluate(el=>el.style.width),'80%');
  assert.equal(await page.locator('#dashboard-stopped-share').textContent(),'20.0%');
  assert(!(await page.locator('[data-view="dashboard"]').textContent()).includes('Hourly production'));
  assert.equal(await page.locator('[data-view="dashboard"] canvas').count(),0);
  assert.equal(await page.locator('[data-dashboard-tag]').count(),1,'Editors need a dashboard Tag reason action');
  await page.locator('[data-dashboard-tag]').click();await page.locator('#dashboard-tag-reason').selectOption('CUSTOM_STOP');
  assert((await page.locator('#dashboard-tag-context').textContent()).includes('APP-07'));
  assert((await page.locator('#dashboard-tag-context').textContent()).includes('23:55:17'),'Keep exact overnight interval visible');
  assert.equal(await page.locator('#dashboard-tag-notes').inputValue(),'Original notes');
  assert.equal(await page.locator('#dashboard-tag-reason option[value="INACTIVE"]').count(),0);
  await page.locator('#dashboard-tag-cancel').click();assert.equal(saves.length,0,'Cancel must never write');assert(await page.locator('[data-dashboard-tag]').evaluate(el=>el===document.activeElement),'Cancel should restore focus to the tag action');
  reasonFailure=true;await page.locator('[data-dashboard-tag]').click();await page.waitForFunction(()=>document.getElementById('dashboard-tag-error').textContent.includes('Reasons unavailable'));assert(await page.locator('#dashboard-tag-save').isDisabled());await page.keyboard.press('Escape');reasonFailure=false;
  await page.locator('[data-dashboard-tag]').click();await page.locator('#dashboard-tag-reason').selectOption('CUSTOM_STOP');await page.locator('#dashboard-tag-type').selectOption('planned');await page.locator('#dashboard-tag-notes').fill('Reviewed <notes>');
  await page.screenshot({path:'/tmp/pns-dashboard-tag-desktop.png'});
  await page.setViewportSize({width:320,height:800});const dialogBox=await page.locator('#dashboard-tag-dialog').boundingBox();assert(dialogBox&&dialogBox.x>=0&&dialogBox.x+dialogBox.width<=321,'Tag dialog overflows mobile');await page.screenshot({path:'/tmp/pns-dashboard-tag-mobile.png'});await page.setViewportSize({width:1440,height:1100});
  tagFailure=true;await page.locator('#dashboard-tag-save').click();await page.waitForFunction(()=>document.getElementById('dashboard-tag-error').textContent.includes('active registered'));assert(await page.locator('#dashboard-tag-dialog').isVisible());assert.equal(await page.locator('#dashboard-tag-notes').inputValue(),'Reviewed <notes>');
  tagFailure=false;const priorRequests=requests.filter(r=>r.action==='dashboard').length;await page.locator('#dashboard-tag-save').click();await page.locator('#dashboard-tag-dialog').waitFor({state:'hidden'});await page.waitForFunction(()=>document.getElementById('dashboard-stops-body').textContent.includes('Custom stop <reason>'));
  assert.deepEqual(saves.at(-1),{event_id:41,machine:'APP-07',event_type:'planned',reason_code:'CUSTOM_STOP',notes:'Reviewed <notes>'},'Save actual event machine, never ALL or rewritten timestamps');
  assert(requests.filter(r=>r.action==='dashboard').length>priorRequests,'Successful tagging must refresh dashboard');assert((await page.locator('#dashboard-reasons').textContent()).includes('Custom stop <reason>'));assert.equal(await page.locator('#dashboard-reasons reason').count(),0);
  await page.locator('#dashboard-machine').selectOption('APP-07');await page.locator('#dashboard-date').fill('2026-10-21');await page.locator('#dashboard-duration').selectOption('720');await page.locator('#dashboard-start-time').fill('12:00');await page.locator('#dashboard-refresh').click();
  await page.waitForFunction(()=>document.getElementById('dashboard-oee-detail').textContent.includes('APP-07'));
  await page.locator('[data-dashboard-link="report"]').first().click();
  assert.equal(await page.locator('#report-machine-filter').inputValue(),'APP-07');assert.equal(await page.locator('#report-date').inputValue(),'2026-10-21');assert.equal(await page.locator('#report-duration').inputValue(),'720');
  await page.locator('[data-nav="dashboard"]').click();
  await page.locator('#dashboard-machine').selectOption('ALL');await page.locator('#dashboard-date').fill('2026-10-22');await page.locator('#dashboard-duration').selectOption('1440');await page.locator('#dashboard-refresh').click();
  await page.waitForFunction(()=>document.getElementById('dashboard-oee-detail').textContent.includes('All machines'));
  for(const width of[1440,1024,768,320]){await page.setViewportSize({width,height:1100});await page.waitForTimeout(250);for(const id of['dashboard-machine','dashboard-date','dashboard-duration','dashboard-start-time','dashboard-refresh']){const b=await page.locator('#'+id).boundingBox();assert(b&&b.x>=0&&b.x+b.width<=width+1,id+' overflows '+width+' '+JSON.stringify(b));}const operations=await page.locator('.pns-operations').boundingBox(),batches=await page.locator('.pns-batches-section').boundingBox();assert(operations&&batches&&batches.y>=operations.y+operations.height,'Recent batches must appear below Live operations at '+width);}
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Mobile dashboard has page-wide overflow');
  await page.screenshot({path:'/tmp/pns-dashboard-updated-mobile.png'});
  await page.setViewportSize({width:1440,height:1100});await page.waitForTimeout(250);await page.screenshot({path:'/tmp/pns-dashboard-updated.png',fullPage:true});
  empty=true;await page.locator('#dashboard-refresh').click();await page.waitForFunction(()=>document.getElementById('dashboard-batches-body').textContent.includes('No batches'));
  fail=true;await page.locator('#dashboard-refresh').click();await page.waitForFunction(()=>document.getElementById('oee-dashboard-alert').textContent.includes('Test connection unavailable'));
  assert.equal(await page.locator('#oee-value').textContent(),'—','Failure must not leave stale KPIs');assert(!(await page.locator('#dashboard-refresh').isDisabled()));
  fail=false;empty=false;permissions={dashboard:{view:true}};const count=requests.length;await page.goto('http://127.0.0.1:4173/app.php?scope=dashboard-only#/dashboard');await page.waitForFunction(()=>document.getElementById('oee-value').textContent==='60.0%');
  assert(!(await page.locator('.pns-reason-card').isVisible()));assert(!(await page.locator('.pns-batches-section').isVisible()));assert(!(await page.locator('#dashboard-batches-body').isVisible()));assert(!(await page.locator('#dashboard-stops-body').isVisible()));assert(await page.locator('#dashboard-detail-message').isVisible());
  assert(!requests.slice(count).some(r=>['report','downtime','masters'].includes(r.action)),'Dashboard-only must not fetch restricted endpoints');
  permissions={dashboard:{view:true},downtime:{view:true,update:false}};await page.goto('http://127.0.0.1:4173/app.php?scope=view-only#/dashboard');await page.waitForFunction(()=>document.getElementById('oee-value').textContent==='60.0%');assert.equal(await page.locator('[data-dashboard-tag]').count(),0,'View-only roles must not tag');
  assert(await page.locator('.pns-reason-card').isVisible());assert(!(await page.locator('.pns-batches-section').isVisible()),'Downtime View must not expose the relocated batch section');
  assert.deepEqual(errors,[]);console.log('Dashboard browser tests passed: inline downtime tagging, CSRF payload, cancel/errors, exact event/line, refresh, read-only hiding, safe text, filters and responsive layouts.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
