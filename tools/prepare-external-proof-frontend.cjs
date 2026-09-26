// ========================================
// FIREBASE-READY CLIENT CONTRACT
// Removes the active local-upload flow without installing or configuring Firebase.
// ========================================
const fs=require('fs'),root='C:/Capstone-LifeFlow/lifeflow-frontend/';
const read=p=>fs.readFileSync(root+p,'utf8').replace(/\r\n/g,'\n');
let p='src/services/donations.ts',s=read(p);
s=s.replace('export type DonationParticipation =','// Metadata is submitted only after a future external upload succeeds.\nexport type ProofMetadata = {proof_url:string;proof_storage_path:string;proof_original_name:string;proof_mime_type:\'image/jpeg\'|\'image/png\'|\'application/pdf\'};\nexport type DonationParticipation =');
s=s.replace('proof_original_name:string|null;', 'proof_url:string|null; proof_original_name:string|null;');
s=s.replace('proof:(token:string,id:string,form:FormData)', 'proof:(token:string,id:string,metadata:ProofMetadata)').replace('body:form','body:{proof_url:metadata.proof_url,proof_storage_path:metadata.proof_storage_path,proof_original_name:metadata.proof_original_name,proof_mime_type:metadata.proof_mime_type}');
fs.writeFileSync(root+p,s);
p='src/contexts/auth-context.tsx';s=read(p).replace('type DonationOpportunity,','type ProofMetadata, type DonationOpportunity,');
s=s.replaceAll('uploadProof','submitProofMetadata').replaceAll('form:FormData','metadata:ProofMetadata').replace('donationApi.proof(saved,id,form)','donationApi.proof(saved,id,metadata)');fs.writeFileSync(root+p,s);
p='src/app/activity/[id].tsx';s=read(p).replace("import * as DocumentPicker from 'expo-document-picker';\n",'').replace('Alert,Platform,','Alert,').replace('{ApiError,errorMessage}','{errorMessage}').replace('donationDetail,cancelParticipation,uploadProof','donationDetail,cancelParticipation');
const a=s.indexOf(' // ========================================\n // SELECT AND UPLOAD PRIVATE PROOF');const b=s.indexOf(' return <>',a);
if(a<0||b<0)throw Error('Expected existing proof handler not found');
s=s.slice(0,a)+` // ========================================
 // EXTERNAL UPLOAD NOT CONFIGURED
 // Keep cancellation available; do not fabricate a URL or queue missing evidence.
 // The metadata API is ready for a future Firebase upload integration.
 // ========================================
`+s.slice(b);
s=s.replace('disabled={busy} onPress={proof}', 'disabled accessibilityState={{disabled:true}}').replace("{busy?'Please wait…':'Upload Proof (JPEG, PNG, PDF)'}",'Proof upload not configured');
s=s.replace('</Text></Pressable><Pressable disabled={busy} onPress={cancel}', '</Text></Pressable><Text style={styles.description}>Proof upload will be available after external storage is connected.</Text><Pressable disabled={busy} onPress={cancel}');
fs.writeFileSync(root+p,s);
