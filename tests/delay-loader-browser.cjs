// PLAYWRIGHT_PATH and CHROMIUM_PATH may point to an existing local installation.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs');
const assert = require('assert');
const path = require('path');
const loader = fs.readFileSync(path.join(__dirname, '../assets/js/delay-loader.js'), 'utf8');
const placeholder = (src, attrs='') => `<template data-twperf-delay="1" data-twperf-tag="${Buffer.from(`<script src="${src}" ${attrs}></script>`).toString('base64')}"></template>`;
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || undefined});
 try {
  for(const trigger of ['interaction','timeout']){
   const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
   const html=`<script>window.sequence=[];</script><script>window.twperfDelayConfig={events:["click","scroll"]};</script><script src="/loader.js"></script>${placeholder('/a.js','nonce="fixture" data-label="é" crossorigin="anonymous"')}${placeholder('/b.js')}${placeholder('/module.js','type="module"')}${placeholder('/skip.js','nomodule')}${placeholder('/missing.js')}${placeholder('/last.js')}`;
   await page.route('**/*',async route=>{
    const u=new URL(route.request().url());
    if(u.hostname!=='twperf.test')throw new Error('Unexpected external request');
    if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
    if(u.pathname==='/loader.js')return route.fulfill({contentType:'text/javascript',body:loader});
    if(u.pathname==='/a.js'){await new Promise(r=>setTimeout(r,150));return route.fulfill({contentType:'text/javascript',body:'window.sequence.push("a");window.attributes={nonce:document.currentScript.nonce,label:document.currentScript.dataset.label,cross:document.currentScript.crossOrigin};'});}
    if(u.pathname==='/b.js')return route.fulfill({contentType:'text/javascript',body:'if(window.sequence[0]!=="a")throw Error("dependency not ready");window.sequence.push("b");'});
    if(u.pathname==='/module.js')return route.fulfill({contentType:'text/javascript',body:'export const test=true;window.sequence.push("module");'});
    if(u.pathname==='/skip.js')throw Error('nomodule script fetched');
    if(u.pathname==='/missing.js')return route.fulfill({status:404,body:''});
    if(u.pathname==='/last.js')return route.fulfill({contentType:'text/javascript',body:'window.sequence.push("last");'});
    throw Error('Unexpected fixture URL');
   });
   await page.goto('https://twperf.test/');
   assert.deepStrictEqual(await page.evaluate(()=>window.sequence),[]);
   if(trigger==='interaction')await page.evaluate(()=>{window.dispatchEvent(new Event('click'));window.dispatchEvent(new Event('scroll'));});
   await page.waitForFunction(()=>window.sequence.includes('last'),{},{timeout:8000});
   assert.deepStrictEqual(await page.evaluate(()=>window.sequence),['a','b','module','last']);
   assert.deepStrictEqual(await page.evaluate(()=>window.attributes),{nonce:'fixture',label:'é',cross:'anonymous'});
   assert.deepStrictEqual(errors,[]);
   await page.close();
  }
  console.log('PASS: browser execution order, module/nonce/UTF-8 attributes, footer placeholders, failure continuation, nomodule, duplicate interactions and timeout fallback');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
