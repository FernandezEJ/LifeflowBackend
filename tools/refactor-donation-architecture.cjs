// ========================================
// SCOPED ARCHITECTURE PATCH
// Reuses the current screens, auth token helper, and visual styles.
// ========================================
const fs = require('fs');
const root='C:/Capstone-LifeFlow/lifeflow-frontend/';
const read=n=>fs.readFileSync(root+n,'utf8').replace(/\r\n/g,'\n');
const changes=new Map();
function replace(s,a,b){if(!s.includes(a))throw Error('Missing source '+a.slice(0,60));return s.replace(a,b);}
changes.set('src/services/donations.ts', `import { apiRequest } from './api';
// ========================================
// OPPORTUNITY AND PARTICIPATION CONTRACTS
// Only backend verification creates trusted outcomes; possible points are informational.
// ========================================
export type DonationStatus = 'pending' | 'for_verification' | 'completed' | 'rejected' | 'cancelled';
export type DonationOpportunity = { id:number; title:string; description:string; location:string; event_date:string; start_time:string|null; end_time:string|null; points_reward:number; published_at:string; expires_at:string; status:string };
export type DonationParticipation = { id:number; status:DonationStatus; joined_at:string; cancelled_at:string|null; proof_original_name:string|null; proof_uploaded_at:string|null; verified_at:string|null; rejection_reason:string|null; opportunity:DonationOpportunity };
export type DonationPage = { data:DonationParticipation[]; current_page:number; last_page:number; total:number };
export type DonationSummary = { total_donations:number; achievement:{key:string;label:string} };
export const DONATION_STATUS = {pending:'Pending',for_verification:'For Verification',completed:'Completed',rejected:'Rejected',cancelled:'Cancelled'};
// ========================================
// ACTIVE BOARD ORDER
// The permanent informational fallback always follows the newest active admin post.
// ========================================
export function boardItems(opportunities:DonationOpportunity[], now=Date.now()): (DonationOpportunity | 'red-cross')[] {
 const active=opportunities.filter(o=>o.status==='published' && Date.parse(o.published_at)<=now && Date.parse(o.expires_at)>now)
  .sort((a,b)=>Date.parse(b.published_at)-Date.parse(a.published_at)||b.id-a.id);
 return active.length ? [active[0], 'red-cross', ...active.slice(1)] : ['red-cross'];
}
// ========================================
// SERVER-OWNED DONATION LIFECYCLE
// No manual record creation, approval, or reward award API is offered.
// ========================================
export const donationApi={
 history:(token:string,page=1,status?:DonationStatus)=>apiRequest<DonationPage>('/donation-participations?page='+page+(status?'&status='+status:''),{token}),
 summary:(token:string)=>apiRequest<DonationSummary>('/donation-summary',{token}),
 detail:(token:string,id:string)=>apiRequest<{participation:DonationParticipation}>('/donation-participations/'+encodeURIComponent(id),{token}),
 opportunities:(token:string)=>apiRequest<{data:DonationOpportunity[]}>('/donation-opportunities',{token}),
 opportunity:(token:string,id:string)=>apiRequest<{opportunity:DonationOpportunity}>('/donation-opportunities/'+encodeURIComponent(id),{token}),
 join:(token:string,id:string)=>apiRequest<{participation:DonationParticipation}>('/donation-opportunities/'+encodeURIComponent(id)+'/join',{token,method:'POST'}),
 cancel:(token:string,id:string)=>apiRequest<{participation:DonationParticipation}>('/donation-participations/'+encodeURIComponent(id)+'/cancel',{token,method:'POST'}),
 proof:(token:string,id:string,form:FormData)=>apiRequest<{participation:DonationParticipation}>('/donation-participations/'+encodeURIComponent(id)+'/proof',{token,method:'POST',body:form}),
};
`);
let api=read('src/services/api.ts');
api=replace(api,"  const controller = new AbortController();",`  // ========================================
  // MULTIPART PROOF SUPPORT
  // Fetch supplies multipart boundaries; JSON behavior remains unchanged.
  // ========================================
  const multipart = typeof FormData !== 'undefined' && options.body instanceof FormData;
  const controller = new AbortController();`);
api=replace(api,"...(options.body !== undefined ?", "...(options.body !== undefined && !multipart ?");
api=replace(api,"body: options.body === undefined ? undefined : JSON.stringify(options.body)","body: multipart ? options.body as FormData : options.body === undefined ? undefined : JSON.stringify(options.body)");
api=replace(api,"        : response.status === 429", "        : response.status === 409 ? 'This activity has already changed or you have already joined. Refresh and try again.'\n        : response.status === 413 ? 'Proof is too large. Select a file no larger than 5 MB.'\n        : response.status === 429");
changes.set('src/services/api.ts',api);
let auth=read('src/contexts/auth-context.tsx');
auth=auth.replaceAll('type DonationInput, type DonationRecord','type DonationOpportunity, type DonationParticipation');
auth=auth.replaceAll('donation_record: DonationRecord','participation: DonationParticipation');
auth=replace(auth,'  submitDonation: (data: DonationInput) => Promise<{ participation: DonationParticipation }>;',`  opportunities: () => Promise<{data:DonationOpportunity[]}>;
  opportunity: (id:string) => Promise<{opportunity:DonationOpportunity}>;
  joinOpportunity: (id:string) => Promise<{participation:DonationParticipation}>;
  cancelParticipation: (id:string) => Promise<{participation:DonationParticipation}>;
  uploadProof: (id:string,form:FormData) => Promise<{participation:DonationParticipation}>;`);
auth=replace(auth,"  const submitDonation = useCallback((data: DonationInput) => donationRequest(saved => donationApi.submit(saved, data)), [donationRequest]);",`  // ========================================
  // OPPORTUNITY PARTICIPATION ACTIONS
  // All actions reuse the existing private token and expired-session handling.
  // ========================================
  const opportunities=useCallback(()=>donationRequest(donationApi.opportunities),[donationRequest]);
  const opportunity=useCallback((id:string)=>donationRequest(saved=>donationApi.opportunity(saved,id)),[donationRequest]);
  const joinOpportunity=useCallback((id:string)=>donationRequest(saved=>donationApi.join(saved,id)),[donationRequest]);
  const cancelParticipation=useCallback((id:string)=>donationRequest(saved=>donationApi.cancel(saved,id)),[donationRequest]);
  const uploadProof=useCallback((id:string,form:FormData)=>donationRequest(saved=>donationApi.proof(saved,id,form)),[donationRequest]);`);
auth=replace(auth,'donationDetail, submitDonation,','donationDetail, opportunities, opportunity, joinOpportunity, cancelParticipation, uploadProof,');
changes.set('src/contexts/auth-context.tsx',auth);
let activity=read('src/app/(tabs)/activity.tsx');
activity=activity.replace('Your donation records','Your donation activities').replace('Donation history','Joined activities').replace('No donation records yet.','No joined activities yet.');
activity=activity.replace(/        <Pressable accessibilityRole="button" style=\{styles.viewButton\} onPress=\{\(\) => router.push\(\{ pathname: '\/activity\/\[id\]', params: \{ id: 'new' \} \}\)\}><Text style=\{styles.viewButtonText\}>Submit Donation Record<\/Text><\/Pressable>\n/,'');
activity=replace(activity,"[undefined, 'pending', 'completed', 'rejected']","[undefined, 'pending', 'for_verification', 'completed', 'rejected', 'cancelled']");
activity=activity.replaceAll('item.location','item.opportunity.title').replaceAll('item.donation_date','item.opportunity.event_date').replaceAll("item.notes || 'No notes'",'item.opportunity.location');
activity=activity.replace("'Not counted toward verified total'","'Awaiting donation verification'");
changes.set('src/app/(tabs)/activity.tsx',activity);
const old=read('src/app/activity/[id].tsx');
const colors=old.slice(old.indexOf('const COLORS'),old.indexOf('// ========================================'));
const styles=old.slice(old.indexOf('const styles ='));
const imports=`import MaterialIcons from '@expo/vector-icons/MaterialIcons';
import {Stack,useFocusEffect,useLocalSearchParams,useRouter} from 'expo-router';
import {useCallback,useRef,useState} from 'react';
import {Alert,Platform,Pressable,ScrollView,StyleSheet,Text,View} from 'react-native';
import {SafeAreaView} from 'react-native-safe-area-context';
import {useAuth} from '@/contexts/auth-context';
import {errorMessage} from '@/services/api';
`;
changes.set('src/app/activity/[id].tsx', imports+`import * as DocumentPicker from 'expo-document-picker';
import {DONATION_STATUS,type DonationParticipation} from '@/services/donations';
${colors}
// ========================================
// OWNED PARTICIPATION AND PROOF
// Keeps activity visible after expiry and permits donor actions only while pending.
// ========================================
export default function ActivityDetailScreen(){
 const router=useRouter(); const {id}=useLocalSearchParams<{id:string}>();
 const {donationDetail,cancelParticipation,uploadProof}=useAuth();
 const [item,setItem]=useState<DonationParticipation|null>(null); const [error,setError]=useState(''); const [busy,setBusy]=useState(false);
 const lock=useRef(false); const [retry,setRetry]=useState(0);
 useFocusEffect(useCallback(()=>{void retry;let active=true;setItem(null);setError('');donationDetail(id).then(r=>{if(active)setItem(r.participation);}).catch(e=>{if(active)setError(errorMessage(e));});return()=>{active=false;};},[id,donationDetail,retry]));
 const run=async(action:()=>Promise<void>)=>{if(lock.current)return;lock.current=true;setBusy(true);setError('');try{await action();}catch(e){setError(errorMessage(e));}finally{lock.current=false;setBusy(false);}};
 const cancel=()=>Alert.alert('Cancel participation?','Only pending activities can be cancelled.',[{text:'Keep activity',style:'cancel'},{text:'Cancel participation',style:'destructive',onPress:()=>void run(async()=>setItem((await cancelParticipation(id)).participation))}]);
 // ========================================
 // SELECT AND UPLOAD PRIVATE PROOF
 // Native file descriptors and web File objects use the same multipart endpoint.
 // ========================================
 const proof=()=>run(async()=>{
   const picked=await DocumentPicker.getDocumentAsync({type:['image/jpeg','image/png','application/pdf'],copyToCacheDirectory:true,multiple:false});
   if(picked.canceled)return;
   const file=picked.assets[0];if(file.size && file.size>5*1024*1024)throw new Error('Select a file no larger than 5 MB.');
   const form=new FormData();
   if(Platform.OS==='web' && file.file)form.append('proof',file.file,file.name);
   else form.append('proof',{uri:file.uri,name:file.name,type:file.mimeType || 'application/octet-stream'} as unknown as Blob);
   setItem((await uploadProof(id,form)).participation);
 });
 return <><Stack.Screen options={{headerShown:false}}/><SafeAreaView edges={['top','bottom']} style={styles.safeArea}>
 <View style={styles.header}><Pressable accessibilityLabel="Go back" onPress={()=>router.back()} style={styles.backButton}><MaterialIcons name="arrow-back" size={23}/></Pressable><Text style={styles.headerTitle}>Activity Details</Text></View>
 <ScrollView contentContainerStyle={styles.scrollContent}><View style={styles.content}>
 {error?<Pressable onPress={()=>setRetry(n=>n+1)}><Text accessibilityRole="alert" style={styles.description}>{error} Tap to refresh.</Text></Pressable>:null}
 {item?<><Text style={styles.statusText}>{DONATION_STATUS[item.status]}</Text><Text style={styles.title}>{item.opportunity.title}</Text>
 <View style={styles.detailsCard}><Text style={styles.detailValue}>{item.opportunity.event_date} {item.opportunity.start_time || ''}</Text><Text style={styles.detailValue}>{item.opportunity.location}</Text><Text style={styles.description}>{item.opportunity.description}</Text></View>
 <View style={styles.rewardCard}><Text style={styles.description}>{item.opportunity.points_reward} possible points after successful admin verification. Points awards will be enabled in a later feature.</Text></View>
 {item.status==='pending'?<><Pressable disabled={busy} onPress={proof} style={styles.uploadButton}><Text style={styles.uploadButtonText}>{busy?'Please wait…':'Upload Proof (JPEG, PNG, PDF)'}</Text></Pressable><Pressable disabled={busy} onPress={cancel} style={styles.cancelButton}><Text style={styles.cancelButtonText}>Cancel Participation</Text></Pressable></>:<View style={styles.proofCard}><Text style={styles.description}>{item.status==='for_verification'?'Proof submitted. Waiting for admin verification.':item.status==='completed'?'Donation verified.':item.status==='rejected'?'Rejected: '+(item.rejection_reason || 'Contact the organizer for details.'):'Participation cancelled.'}</Text>{item.proof_original_name?<Text style={styles.description}>{item.proof_original_name}</Text>:null}</View>}
 </>:!error?<Text style={styles.description}>Loading activity…</Text>:null}
 </View></ScrollView></SafeAreaView></>;
}
${styles}`);
changes.set('src/app/announcement/[id].tsx',imports.replace('Alert,Platform,','Alert,')+`import {type DonationOpportunity} from '@/services/donations';
${colors}
// ========================================
// ANNOUNCEMENT DETAILS AND JOIN CONFIRMATION
// Evaluation reuses the deterministic eligibility screen; joining only creates pending activity.
// ========================================
export default function AnnouncementScreen(){
 const router=useRouter();const {id}=useLocalSearchParams<{id:string}>();const {opportunity,joinOpportunity}=useAuth();
 const [item,setItem]=useState<DonationOpportunity|null>(null);const [error,setError]=useState('');const [busy,setBusy]=useState(false);const lock=useRef(false);
 useFocusEffect(useCallback(()=>{let active=true;opportunity(id).then(r=>{if(active)setItem(r.opportunity);}).catch(e=>{if(active)setError(errorMessage(e));});return()=>{active=false;};},[id,opportunity]));
 const join=async()=>{if(lock.current)return;lock.current=true;setBusy(true);setError('');try{const r=await joinOpportunity(id);router.push({pathname:'/activity/[id]',params:{id:String(r.participation.id)}});}catch(e){setError(errorMessage(e));}finally{lock.current=false;setBusy(false);}};
 const confirm=()=>{if(item)Alert.alert('Go Donate?',item.title+'\\n'+item.event_date+'\\n'+item.location+'\\n'+item.points_reward+' possible points, earned only after successful admin verification. No points are awarded now.',[{text:'Back',style:'cancel'},{text:'Confirm',onPress:()=>void join()}]);};
 return <><Stack.Screen options={{headerShown:false}}/><SafeAreaView edges={['top','bottom']} style={styles.safeArea}><View style={styles.header}><Pressable accessibilityLabel="Go back" onPress={()=>router.back()} style={styles.backButton}><MaterialIcons name="arrow-back" size={23}/></Pressable><Text style={styles.headerTitle}>Announcement</Text></View><ScrollView contentContainerStyle={styles.scrollContent}><View style={styles.content}>
 {error?<Text accessibilityRole="alert" style={styles.description}>{error}</Text>:null}
 {item?<><Text style={styles.title}>{item.title}</Text><View style={styles.detailsCard}><Text style={styles.detailValue}>{item.event_date} {item.start_time || ''}</Text><Text style={styles.detailValue}>{item.location}</Text><Text style={styles.description}>{item.description}</Text></View><Text style={styles.description}>{item.points_reward} potential points after successful verification; points awards are a future feature.</Text><View style={{flexDirection:'row',gap:12}}><Pressable onPress={()=>router.push('/evaluation')} style={styles.uploadButton}><Text style={styles.uploadButtonText}>Evaluate</Text></Pressable><Pressable disabled={busy} onPress={confirm} style={styles.uploadButton}><Text style={styles.uploadButtonText}>{busy?'Joining…':'Go Donate'}</Text></Pressable></View></>:!error?<Text>Loading announcement…</Text>:null}
 </View></ScrollView></SafeAreaView></>;
}
${styles}`);
let layout=read('src/app/_layout.tsx');layout=replace(layout,'          <Stack.Screen name="activity/[id]" />','          <Stack.Screen name="activity/[id]" />\n          <Stack.Screen name="announcement/[id]" />');changes.set('src/app/_layout.tsx',layout);
let home=read('src/app/(tabs)/index.tsx');
home=replace(home,"import { useRouter }", "import { useFocusEffect, useRouter }").replace('import { useEffect, useState }','import { useCallback, useEffect, useState }');
home="import {useAuth} from '@/contexts/auth-context';\nimport {boardItems,type DonationOpportunity} from '@/services/donations';\nimport {errorMessage} from '@/services/api';\n"+home;
let a=home.indexOf('const ADMIN_ANNOUNCEMENTS');let b=home.indexOf('const GUIDE_STEPS');home=home.slice(0,a)+home.slice(b);
a=home.indexOf('function isAnnouncementActive');b=home.indexOf('export default function HomeScreen');home=home.slice(0,a)+home.slice(b);
a=home.indexOf('  const activeAnnouncements =');b=home.indexOf('  useEffect',a);
home=home.slice(0,a)+`  // ========================================
  // LIVE ANNOUNCEMENT BOARD
  // Reloads on focus and periodically; expiration removes only the board item.
  // The Red Cross system card stays present even when loading fails.
  // ========================================
  const {opportunities}=useAuth();const [posts,setPosts]=useState<DonationOpportunity[]>([]);const [boardError,setBoardError]=useState('');const [clock,setClock]=useState(Date.now());
  useFocusEffect(useCallback(()=>{let active=true;const load=()=>opportunities().then(r=>{if(active){setPosts(r.data);setBoardError('');}}).catch(e=>{if(active)setBoardError(errorMessage(e));});void load();const refresh=setInterval(()=>void load(),30000);const tick=setInterval(()=>setClock(Date.now()),1000);return()=>{active=false;clearInterval(refresh);clearInterval(tick);};},[opportunities]));
  const board=boardItems(posts,clock);

`+home.slice(b);
a=home.indexOf('            {/* TODO: Admin posts');b=home.indexOf('          </View>',home.indexOf('            </View>',home.indexOf('            <View style={styles.redCrossCard}',a))+27);
// Extract the existing card JSX rather than redesigning either card.
const mapStart=home.indexOf('            {activeAnnouncements.map');
const mapEnd=home.indexOf('            ))}',mapStart)+17;
let admin=home.slice(home.indexOf('              <View key=',mapStart),home.indexOf('            ))}',mapStart)).trim();
admin=admin.replace('key={announcement.id}','key={announcement.id}').replace('source={announcement.image}',"source={require('../../../assets/images/GoodMascot.png')}").replace('{announcement.date}','{announcement.event_date}').replace('ACTIVE BLOODLETTING',"{index===0?'PINNED':'ACTIVE BLOODLETTING'}").replace('onPress={() => Alert.alert(announcement.title, announcement.description)}',"onPress={() => router.push({pathname:'/announcement/[id]',params:{id:String(announcement.id)}})}");
const redStart=home.indexOf('            <View style={styles.redCrossCard}',mapEnd);
const redEnd=home.indexOf('          </View>',redStart); // first 10-space closing is section, not nested card
let red=home.slice(redStart,redEnd).trim();
// Closing indent lookup is anchored to a newline to avoid matching nested indentation.
const properEnd=home.indexOf('\n          </View>',redStart);
red=home.slice(redStart,properEnd).trim().replace('<View style={styles.redCrossCard}>','<View key="red-cross" style={styles.redCrossCard}>').replace('<Text style={styles.cardTitle}>Donate Through',"{index===0?<Text style={styles.cardMeta}>PINNED</Text>:null}<Text style={styles.cardTitle}>Donate Through");
home=home.slice(0,a)+`            {boardError?<Text style={styles.cardDescription}>{boardError}</Text>:null}
            {board.map((announcement,index)=>announcement==='red-cross'?(${red}):(${admin}))}
`+home.slice(properEnd);
changes.set('src/app/(tabs)/index.tsx',home);
for(const [name,content] of changes){fs.mkdirSync(require('path').dirname(root+name),{recursive:true});fs.writeFileSync(root+name,content);}
console.log('Updated '+[...changes.keys()].join(', '));
