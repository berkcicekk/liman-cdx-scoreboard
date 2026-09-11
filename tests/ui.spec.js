const {test,expect} = require('@playwright/test');
const fixture = () => {
    const at = Date.now();
    const teams = [
        {id:1,name:'Blue Team 01',color:'#36a3ff',sla_score:842.5,inject_score:50,score:892.5},
        {id:2,name:'Blue Team 02',color:'#aa94ff',sla_score:785,inject_score:-25,score:760},
        {id:3,name:'Blue Team 03',color:'#43dfaa',sla_score:681,inject_score:10,score:691}
    ];
    const names = ['Web Portal','DNS','SSH','Directory','WinRM'];
    const protocols = ['HTTPS','DNS','SSH','LDAP','WINRM'];
    return {generated_at:new Date(at).toISOString(),teams,
        services:teams.flatMap((t,i)=>names.map((name,j)=>({id:i*5+j,team_id:t.id,name,protocol:protocols[j],status:(i+j)%6===0?'down':(i+j)%5===0?'stale':'up',host:'192.0.2.'+(10+i),port:443,sla_percent:92-i*6-j,latency_ms:15+j*3,checked_at:new Date(at).toISOString(),unknown_seconds:0,detail:'Protokol kontrolü başarılı'}))),
        trend:teams.flatMap((t,i)=>Array.from({length:61},(_,j)=>({team_id:t.id,at:new Date(at-(60-j)*60000).toISOString(),score:t.score-100+j*1.5+Math.sin(j/7)*12}))),
        injects:[{id:1,team_id:1,team_name:'Blue Team 01',kind:'task',points:50,reason:'Olay raporu zamanında teslim edildi',created_by:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',created_at:new Date(at).toISOString()}]
    };
};
async function mock(page,data=fixture()) {
    const calls=[];
    await page.route('**/engine/',async route=>{
        const body=route.request().postData() || '';calls.push(body);
        await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:200,message:body.includes('ls_dashboard')?data:{id:4}})});
    });
    return calls;
}
test('native Liman request renders grid, chart, forms and polls every 10 seconds',async({page})=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));const calls=await mock(page);
    await page.clock.install();await page.goto('/');
    await expect(page.locator('[data-role="ranking"] tr')).toHaveCount(3);
    await expect(page.locator('[data-role="matrix-body"] .ls-status')).toHaveCount(15);
    expect(calls[0]).toContain('ls_dashboard');expect(calls[0]).toContain('server_id');expect(calls[0]).toContain('extension_id');
    const first=calls.length;await page.clock.fastForward(10000);await expect.poll(()=>calls.length).toBe(first+1);
    await expect(page.locator('[data-role="error"]')).toBeHidden();
    await page.screenshot({path:'tests/.runtime/dashboard-desktop.png',fullPage:true});
    await page.locator('summary').click();
    const form=page.locator('[data-action="addInject"]');await form.locator('[name="points"]').fill('25');await form.locator('[name="reason"]').fill('Test görevi');await form.locator('button').click();
    await expect(page.locator('[data-role="form-result"]')).toHaveText('Kayıt eklendi.');
    expect(calls.some(c=>c.includes('request_id') && c.includes('ls_add_inject'))).toBe(true);
    await page.setViewportSize({width:390,height:844});await page.clock.runFor(500);
    await page.screenshot({path:'tests/.runtime/dashboard-mobile.png',fullPage:true});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
    expect(errors).toEqual([]);
});
test('viewer hides mutations and safely displays untrusted labels',async({page})=>{
    const data=fixture();data.teams[0].name='<img src=x onerror=alert(1)>';data.injects[0].reason='<script>alert(1)</script>';
    await mock(page,data);await page.goto('/?viewer=1');
    await expect(page.locator('form[data-action]')).toHaveCount(0);
    await expect(page.locator('[data-role="ranking"]')).toContainText('<img src=x onerror=alert(1)>');
    await expect(page.locator('[data-role="injects"]')).toContainText('<script>alert(1)</script>');
    await expect(page.locator('[data-role="ranking"] img')).toHaveCount(0);
});
test('empty and error states; cleanup stops polling',async({page})=>{
    const calls=await mock(page,{generated_at:new Date().toISOString(),teams:[],services:[],trend:[],injects:[]});
    await page.clock.install();await page.goto('/');await expect(page.locator('[data-role="empty"]')).toBeVisible();
    await page.unroute('**/engine/');
    await page.route('**/engine/',route=>{calls.push('error');return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({status:503,message:'Test bağlantı hatası'})});});
    await page.clock.fastForward(10000);await expect(page.locator('[data-role="error"]')).toHaveText('Test bağlantı hatası');
    await page.evaluate(()=>window.lsScoreboardCleanup());const count=calls.length;await page.clock.fastForward(30000);expect(calls.length).toBe(count);
});
test('a slow request never overlaps a second poll',async({page})=>{
    let count=0,release;
    const held=new Promise(resolve=>{release=resolve;});
    await page.route('**/engine/',async route=>{
        count++;await held;
        await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:200,message:fixture()})});
    });
    await page.clock.install();await page.goto('/');await expect.poll(()=>count).toBe(1);
    await page.clock.fastForward(30000);expect(count).toBe(1);
    release();await expect(page.locator('[data-role="ranking"] tr')).toHaveCount(3);
    await page.clock.fastForward(10000);await expect.poll(()=>count).toBe(2);
});
