import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { mkdtemp,writeFile,readFile,unlink,rmdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
const exec=promisify(execFile);
const agent=fileURLToPath(new URL('./print-agent.mjs',import.meta.url));
for(const mode of ['file','unsupported'])test(`agent sends acknowledgement for ${mode} transport`,async()=>{
 const dir=await mkdtemp(path.join(tmpdir(),'magueyes-agent-')); const acks=[]; const failures=[];
 const bytes=Buffer.from('\x1b\x40COMANDA PRUEBA\n\x1d\x56\x00','binary');
 const server=http.createServer(async(req,res)=>{try{
  let body='';for await(const chunk of req)body+=chunk;
  assert.equal(req.headers.authorization,'Bearer test-only-token');
  res.setHeader('content-type','application/json');
  if(req.url==='/print-agent/claim'){assert.deepEqual(JSON.parse(body).areas,[1]);res.end(JSON.stringify({job:{id:42,area_id:1,lease_token:'12345678-1234-1234-1234-123456789012',payload_base64:bytes.toString('base64')}}));}
  else if(req.url==='/print-agent/42/ack'){acks.push(JSON.parse(body));res.end('{"ok":true}');}
  else {res.statusCode=404;res.end('{}');}
 }catch(e){failures.push(e);res.statusCode=500;res.end('{}');}});
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 try{
  const port=server.address().port;
  await writeFile(path.join(dir,'config.json'),JSON.stringify({server:`http://127.0.0.1:${port}/`,token:'test-only-token',printers:{1:{type:mode,directory:'spool'}}}));
  await exec(process.execPath,[agent,path.join(dir,'config.json'),'--once'],{timeout:15000});
  assert.equal(failures.length,0);assert.equal(acks.length,1);assert.equal(acks[0].success,mode==='file');assert.equal(acks[0].lease_token,'12345678-1234-1234-1234-123456789012');
  if(mode==='file')assert.deepEqual(await readFile(path.join(dir,'spool','42.bin')),bytes);
  else assert.match(acks[0].error,/Tipo de impresora/);
  // Running the exact same lease again reconciles the ledger without re-sending.
  await exec(process.execPath,[agent,path.join(dir,'config.json'),'--once'],{timeout:15000});assert.equal(acks.length,2);
 }finally{
  await new Promise(r=>server.close(r));
  if(mode==='file'){await unlink(path.join(dir,'spool','42.bin')).catch(()=>{});await rmdir(path.join(dir,'spool')).catch(()=>{});}
  for(const filename of ['config.json','ledger.json','ledger.json.tmp'])await unlink(path.join(dir,filename)).catch(()=>{});
  await rmdir(dir);
 }
});
