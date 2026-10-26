const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs'), path = require('path'), assert = require('assert');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..');
const fixture = JSON.parse(execFileSync('php', ['-r', 'require "includes/permissions.php"; echo json_encode([portal_permission_catalog(),portal_permission_matrix(true),portal_permission_matrix()]);'], { cwd: root, encoding: 'utf8' }));
const html = execFileSync('php', ['-r', 'require "includes/security.php"; start_app_session(); $_SESSION["user_id"]=1; require "app-shell.php";'], { cwd: root, encoding: 'utf8' });
(async()=>{
  const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[],requests=[];
    let permissions=fixture[1],admin=true,userRole=2,roles=[{id:1,name:'Standard user',is_builtin:1,assigned_users:0,permissions:fixture[2]},{id:2,name:'Viewer',is_builtin:0,assigned_users:1,permissions:{'oee-report':{view:true}}}];
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('http://127.0.0.1:4173/**',async route=>{
      const req=route.request(),url=new URL(req.url()),r=url.searchParams.get('resource'),a=url.searchParams.get('action');
      if(url.pathname.endsWith('/app.php'))return route.fulfill({contentType:'text/html',body:html});
      if(url.pathname.endsWith('/api.php')){
        const data=req.method()==='POST'?req.postDataJSON():null; requests.push({r,a,data});
        if(r==='bootstrap')return route.fulfill({json:{csrf_token:'test',user:{id:1,full_name:'Test Admin',username:'admin'},is_admin:admin,access:{permissions,system_admin:admin},records:permissions.records?.view?[{id:9,record_number:'TEST-9',title:'Owned record',owner_name:'Test',status:'Open'}]:[],user_count:null}});
        if(r==='roles'&&!a)return route.fulfill({json:{roles,catalog:fixture[0]}});
        if(r==='roles'&&a==='role-save'){
          assert.equal(req.headers()['x-csrf-token'],'test');
          if(data.id)roles=roles.map(x=>Number(x.id)===data.id?{...x,name:data.name,permissions:data.permissions}:x);
          else roles.push({id:3,name:data.name,permissions:data.permissions,is_builtin:0,assigned_users:0});
          return route.fulfill({json:{ok:true,id:data.id||3}});
        }
        if(r==='roles'&&a==='role-delete'){roles=roles.filter(x=>Number(x.id)!==data.id);return route.fulfill({json:{ok:true}});}
        if(r==='users'&&a==='user-role'){userRole=data.role_id;return route.fulfill({json:{ok:true}});}
        if(r==='users')return route.fulfill({json:{users:[{id:77,username:'worker',full_name:'Worker',email:'worker@example.invalid',role:'user',is_active:1,access_role_id:userRole,access_role_name:'Viewer'}],assignable_roles:roles}});
        if(a==='masters')return route.fulfill({json:{reasons:[{id:1,code:'UNK',label:'Not classified',is_active:1}],cycles:[{id:1,line_code:'APP-06',colour:'BLACK-1',minutes_per_fb:10,effective_from:'2026-01-01'}],thresholds:[{line_code:'APP-06',gap_threshold_minutes:20}]}});
        if(a==='downtime')return route.fulfill({json:{events:[{id:111,starts_at:'2026-10-22 10:00:00',ends_at:'2026-10-22 10:30:00',event_type:'unplanned',reason_code:'UNK',status:'proposed',source:'gap'}]}});
        return route.fulfill({json:{available:false,message:'No test data'}});
      }
      const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
      if(!file.startsWith(root+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
      return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'application/javascript'});
    });
    await page.goto('http://127.0.0.1:4173/app.php#/roles');
    await page.getByRole('button',{name:'Edit role Viewer',exact:true}).waitFor();
    assert(!(await page.locator('#role-save').isDisabled()),'Initial role editor must be usable');
    await page.locator('#role-name').fill('New operator');
    await page.locator('#role-permissions-body input[data-page="downtime"][data-action="update"]').check();
    assert(await page.locator('#role-permissions-body input[data-page="downtime"][data-action="view"]').isChecked());
    await page.locator('#role-save').click();
    await page.getByRole('button',{name:'Edit role New operator',exact:true}).waitFor();
    assert(requests.find(x=>x.a==='role-save').data.permissions.downtime.update);
    await page.getByRole('button',{name:'Edit role New operator',exact:true}).click();
    await page.locator('#role-name').fill('Renamed operator');await page.locator('#role-save').click();
    await page.getByRole('button',{name:'Edit role Renamed operator',exact:true}).waitFor();
    page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Delete role Renamed operator',exact:true}).click();
    await page.waitForFunction(()=>document.getElementById('roles-alert').textContent==='Role deleted.');
    await page.locator('[data-nav="users"]').click();
    await page.locator('[data-user-role="77"]').selectOption('1');await page.locator('[data-save-user-role="77"]').click();
    await page.waitForFunction(()=>document.querySelector('[data-user-role="77"]').value==='1');
    assert(requests.some(x=>x.a==='user-role'&&x.data.role_id===1));
    await page.locator('[data-nav="roles"]').click();
    for(const width of [1440,1024,768,320]){
      await page.setViewportSize({width,height:1100});await page.waitForTimeout(250);
      for(const id of ['role-name','role-save','role-new','roles-refresh']){const b=await page.locator('#'+id).boundingBox();assert(b&&b.x>=0&&b.x+b.width<=width+1,id+' overflows '+width);}
    }
    await page.setViewportSize({width:1440,height:1100});await page.waitForTimeout(250);
    await page.screenshot({path:'/tmp/pns-roles.png',fullPage:true});
    permissions={'oee-report':{view:true}};admin=false;
    const start=requests.length;await page.goto('http://127.0.0.1:4173/app.php?scenario=viewer#/users');
    await page.locator('[data-view="access-denied"].active').waitFor();
    assert(!(await page.locator('[data-nav="users"]').isVisible()));
    assert(!(await page.locator('[data-nav="roles"]').isVisible()));
    assert(!requests.slice(start).some(x=>x.r==='users'||x.a==='dashboard'),'Forbidden background requests must not be sent');
    await page.locator('[data-nav="oee-report"]').click();
    await page.locator('[data-view="oee-report"].active').waitFor();
    permissions={records:{view:true},profile:{view:true}};await page.goto('http://127.0.0.1:4173/app.php?scenario=records#/requests');
    await page.locator('#records-body tr').first().waitFor();
    assert(!(await page.locator('[data-goto="create"]').isVisible()));
    assert.equal(await page.locator('[data-delete-record]').count(),0);
    await page.locator('[data-nav="profile"]').click();assert(await page.locator('#profile-name').isDisabled());
    permissions={'oee-masters':{view:true}};await page.goto('http://127.0.0.1:4173/app.php?scenario=masters#/oee-masters');
    await page.locator('#cycles-body tr').first().waitFor();
    assert(!(await page.locator('[data-edit-cycle]').isVisible()));assert(!(await page.locator('#cycle-form').isVisible()));assert(await page.locator('#threshold-save').isDisabled());
    permissions={downtime:{view:true,update:true}};await page.goto('http://127.0.0.1:4173/app.php?scenario=downtime#/downtime');
    await page.locator('[data-tag-downtime]').waitFor();assert(await page.locator('#downtime-form button[type=submit]').isDisabled());
    await page.locator('[data-tag-downtime]').click();assert(!(await page.locator('#downtime-form button[type=submit]').isDisabled()));
    assert.deepEqual(errors,[]);
    console.log('Permissions browser tests passed: role CRUD, user assignment, matrix dependencies, direct-route denial, hidden CRUD controls, profile read-only, update-only downtime and responsive UI.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
