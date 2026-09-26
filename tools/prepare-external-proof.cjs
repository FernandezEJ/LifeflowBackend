// ========================================
// PROOF CONTRACT REFACTOR
// Reuses the existing lifecycle and replaces only local file handling.
// ========================================
const fs=require('fs');
function read(p){return fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n');}
let p='app/Http/Controllers/Api/DonationParticipationController.php',s=read(p);
for(const line of ['use Illuminate\\Database\\Eloquent\\ModelNotFoundException;','use Illuminate\\Support\\Facades\\Storage;','use Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface;','use Throwable;']) s=s.replace(line+'\n','');
const start=s.indexOf('    // PRIVATE PROOF SUBMISSION');const end=s.indexOf('    // ========================================\n    // STRICT DONOR INPUT',start);
s=s.slice(0,start)+`    // EXTERNAL PROOF METADATA
    // Saves references from a future external upload, never file bytes locally.
    // Metadata is donor-submitted evidence, not a verified donation or verified object.
    // ========================================
    public function proof(Request $request, int $id)
    {
        $this->only($request, ['proof_url', 'proof_storage_path', 'proof_original_name', 'proof_mime_type']);
        $data = $request->validate([
            'proof_url' => ['required', 'string', 'url:https', 'max:2048'],
            'proof_storage_path' => ['required', 'string', 'max:1024', 'regex:~^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9_./-]+$~'],
            'proof_original_name' => ['required', 'string', 'max:255', 'not_regex:~[/\\\\\\\\\\x00-\\x1F]~'],
            'proof_mime_type' => ['required', Rule::in(['image/jpeg', 'image/png', 'application/pdf'])],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $item = $request->user()->donationParticipations()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'pending', 409, 'Only pending participation accepts proof.');
            $item->proof_url = $data['proof_url'];
            $item->proof_storage_path = $data['proof_storage_path'];
            $item->proof_original_name = $data['proof_original_name'];
            $item->proof_mime_type = $data['proof_mime_type'];
            $item->proof_uploaded_at = now();
            $item->status = 'for_verification';
            $item->save();

            return response()->json(['participation' => $item->load('opportunity')]);
        });
    }

`+s.slice(end);fs.writeFileSync(p,s);
p='app/Models/DonationParticipation.php';s=read(p).replace("['proof_path']","['proof_storage_path']");fs.writeFileSync(p,s);
p='tests/Feature/DonationParticipationTest.php';s=read(p);
s=s.replace("['proof' => UploadedFile::fake()->image('proof.jpg')]",'$this->proofMetadata()').replace("['proof' => UploadedFile::fake()->image('again.jpg')]",'$this->proofMetadata()').replaceAll("['proof' => UploadedFile::fake()->image('proof.png')]",'$this->proofMetadata()');
s=s.replace("assertJsonMissingPath('participation.proof_path')","assertJsonMissingPath('participation.proof_storage_path')->assertJsonPath('participation.proof_url', $this->proofMetadata()['proof_url'])");
s=s.replace("Storage::disk('local')->assertExists($row->proof_path);","$this->assertSame($this->proofMetadata()['proof_storage_path'], $row->proof_storage_path);\n        $this->assertCount(0, Storage::disk('local')->allFiles());");
s=s.replace('PRIVATE PROOF QUEUE','EXTERNAL PROOF METADATA QUEUE').replace('Upload never completes a donation, grants points, or exposes a filesystem path.','Metadata never completes a donation, grants points, or writes a local file.');
const anchor='    private function signIn(): User';
s=s.replace(anchor,`    // ========================================
    // EXTERNAL STORAGE FIXTURE
    // No Firebase project or network request is needed to test the metadata contract.
    // ========================================
    private function proofMetadata(): array
    {
        return ['proof_url' => 'https://example.com/proofs/test.pdf', 'proof_storage_path' => 'proofs/test.pdf',
            'proof_original_name' => 'test.pdf', 'proof_mime_type' => 'application/pdf'];
    }

    public function test_invalid_metadata_and_server_owned_timestamp(): void
    {
        $this->signIn(); $opportunity = $this->opportunity();
        $id = $this->postJson('/api/donation-opportunities/'.$opportunity->id.'/join')->json('participation.id');
        foreach (['proof_url' => 'http://example.com/file', 'proof_storage_path' => '../file',
            'proof_original_name' => '../file.pdf', 'proof_mime_type' => 'application/x-php',
            'proof_uploaded_at' => '2026-01-01', 'status' => 'completed', 'user_id' => 999] as $key => $value) {
            $this->postJson('/api/donation-participations/'.$id.'/proof', array_replace($this->proofMetadata(), [$key => $value]))->assertUnprocessable();
        }
        $this->assertSame('pending', DonationParticipation::findOrFail($id)->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

`+anchor);fs.writeFileSync(p,s);
