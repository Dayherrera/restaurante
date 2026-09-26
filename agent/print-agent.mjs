import { readFile, writeFile, rename, mkdir } from 'node:fs/promises';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const configPath=process.argv[2] && !process.argv[2].startsWith('--') ? path.resolve(process.argv[2]) : path.join(path.dirname(fileURLToPath(import.meta.url)),'config.json');
const dir=path.dirname(configPath);
const config=JSON.parse((await readFile(configPath,'utf8')).replace(/^\uFEFF/,''));
if(!config.token || !config.server || !Object.keys(config.printers||{}).length) throw Error('Configura server, token y printers en agent/config.json');
const server=new URL(config.server);
if(server.protocol!=='https:' && !['localhost','127.0.0.1','::1'].includes(server.hostname)) throw Error('Usa HTTPS para un servidor remoto.');
const ledgerPath=path.join(dir,'ledger.json');
let ledger={}; try {ledger=JSON.parse(await readFile(ledgerPath,'utf8'));} catch(e){if(e.code!=='ENOENT')throw e;}
async function persist(){await writeFile(ledgerPath+'.tmp',JSON.stringify(ledger,null,2)); await rename(ledgerPath+'.tmp',ledgerPath);}
async function api(endpoint,body){const response=await fetch(new URL(`print-agent/${endpoint}`,config.server.endsWith('/')?config.server:config.server+'/'),{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json',Authorization:`Bearer ${config.token}`},body:JSON.stringify(body),signal:AbortSignal.timeout(15000)});if(!response.ok)throw Error(`Servidor HTTP ${response.status}`);return response.json();}
async function send(printer,bytes,id){
 if(printer.type==='file'){await mkdir(path.resolve(dir,printer.directory||'spool'),{recursive:true});await writeFile(path.resolve(dir,printer.directory||'spool',`${id}.bin`),bytes);return;}
 if(printer.type==='device'){await writeFile(printer.path,bytes);return;}
 if(printer.type!=='tcp')throw Error('Tipo de impresora inválido. Usa tcp, device o file.');
 await new Promise((resolve,reject)=>{const socket=net.createConnection({host:printer.host,port:printer.port||9100});socket.setTimeout(10000);socket.on('timeout',()=>socket.destroy(Error('Tiempo de impresión agotado')));socket.on('error',reject);socket.on('connect',()=>socket.end(bytes));socket.on('close',hadError=>{if(!hadError)resolve();});});
}
async function tick(){
 const {job}=await api('claim',{areas:Object.keys(config.printers).map(Number)});if(!job)return;
 const identity=`${job.id}:${job.lease_token}`;
 // A persisted send intent is never automatically replayed after an uncertain crash.
 if(ledger[identity]){const previous=ledger[identity];await api(`${job.id}/ack`,{lease_token:job.lease_token,success:previous.state==='sent',error:previous.state==='sent'?null:'Envío anterior incierto. Revisa el ticket físico antes de reintentar.'});return;}
 ledger[identity]={state:'sending',at:new Date().toISOString()};await persist();
 try{await send(config.printers[job.area_id],Buffer.from(job.payload_base64,'base64'),job.id);ledger[identity].state='sent';await persist();}
 catch(error){ledger[identity].state='failed';ledger[identity].error=error.message;await persist();}
 await api(`${job.id}/ack`,{lease_token:job.lease_token,success:ledger[identity].state==='sent',error:ledger[identity].error||null});
 console.log(new Date().toISOString(),`Trabajo ${job.id}: ${ledger[identity].state}`);
}
if(process.argv.includes('--test')){
 for(const [id,printer] of Object.entries(config.printers)){
  const label=(printer.label||`Area ${id}`).normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const bytes=Buffer.from('\x1b\x40LOS MAGUEYES\nPRUEBA DE IMPRESION\n'+label.toUpperCase()+'\nEC-PM-5890X\n'+new Date().toLocaleString('es-MX')+'\nSin venta ni cobro registrado.\n\n\n\x1d\x56\x00','ascii');
  await send(printer,bytes,`test-${id}`);console.log(`Prueba enviada: ${label}`);
 }process.exit(0);
}
if(process.argv.includes('--once')){await tick();process.exit(0);}
console.log('Agente Los Magueyes conectado. Ctrl+C para detener.');
while(true){try{await tick();}catch(e){console.error(new Date().toISOString(),e.message);}await new Promise(r=>setTimeout(r,2000));}
