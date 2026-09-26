// ========================================
// REAL FRONTEND CONTRACT WITH MOCKED HTTP
// Verifies board ordering, JSON proof metadata, and server-owned lifecycle responses.
// ========================================
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const root='C:/Capstone-LifeFlow/lifeflow-frontend';
const ts=require(root+'/node_modules/typescript');
let reply,last;
function load(name,deps={}){const exports={};const source=fs.readFileSync(root+'/src/services/'+name+'.ts','utf8');
 vm.runInNewContext(ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,
 {exports,require:id=>deps[id],process:{env:{}},FormData,AbortController,setTimeout,clearTimeout,fetch:async(url,options)=>{last={url,options};return{ok:reply.status<400,status:reply.status,json:async()=>reply.body};}});return exports;}
const api=load('api');const {donationApi,boardItems,DONATION_STATUS}=load('donations',{'./api':api});
(async()=>{
 const now=Date.parse('2026-09-07T00:00:00Z');
 const post={id:1,status:'published',published_at:'2026-09-01T00:00:00Z',expires_at:'2026-09-08T00:00:00Z'};
 assert.deepEqual(JSON.parse(JSON.stringify(boardItems([],now))),['red-cross']);
 const board=boardItems([post,{...post,id:2,published_at:'2026-09-02T00:00:00Z'}],now);
 assert.equal(board[0].id,2);assert.equal(board[1],'red-cross');assert.equal(board[2].id,1);
 assert.equal(boardItems([post],Date.parse(post.expires_at))[0],'red-cross');
 assert.equal(boardItems([{...post,status:'draft'}],now)[0],'red-cross');
 reply={status:201,body:{participation:{id:8,status:'pending'}}};
 assert.equal((await donationApi.join('token','1')).participation.status,'pending');
 assert.ok(last.url.endsWith('/donation-opportunities/1/join'));assert.equal(last.options.body,undefined);
 assert.equal(last.options.headers.Authorization,'Bearer token');
 for(const status of ['pending','for_verification','completed','rejected','cancelled']){
  assert.ok(DONATION_STATUS[status]);reply={status:200,body:{data:[{id:8,status}],total:1}};
  assert.equal((await donationApi.history('token',2,status)).data[0].status,status);
  assert.ok(last.url.endsWith('?page=2&status='+status));
 }
 const metadata={proof_url:'https://example.com/proof.pdf',proof_storage_path:'proofs/proof.pdf',proof_original_name:'proof.pdf',proof_mime_type:'application/pdf',status:'completed',proof_uploaded_at:'2020-01-01'};
 reply={status:200,body:{participation:{id:8,status:'for_verification'}}};
 assert.equal((await donationApi.proof('token','8',metadata)).participation.status,'for_verification');
 const sent=JSON.parse(last.options.body);assert.equal(sent.proof_url,metadata.proof_url);assert.equal(sent.proof_storage_path,metadata.proof_storage_path);
 assert.equal(sent.status,undefined);assert.equal(sent.proof_uploaded_at,undefined);assert.equal(last.options.headers['Content-Type'],'application/json');
 reply={status:409,body:{}};await assert.rejects(donationApi.cancel('token','8'),/already changed/);
 reply={status:422,body:{errors:{proof_url:['Invalid proof URL.']}}};await assert.rejects(donationApi.proof('token','8',metadata),/Invalid proof/);
 reply={status:401,body:{}};await assert.rejects(donationApi.summary('token'),/session has expired/);
 assert.equal(donationApi.submit,undefined);
 console.log('Donation architecture frontend checks passed: fallback ordering/expiry, five statuses, join, paging, JSON proof metadata, protected fields, safe errors, retired manual API.');
})().catch(e=>{console.error(e);process.exitCode=1;});
