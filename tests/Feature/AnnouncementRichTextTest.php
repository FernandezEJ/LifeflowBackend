<?php

namespace Tests\Feature;

use App\Models\DonationOpportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnnouncementRichTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
        $this->travelTo('2026-10-18 04:00:00');
        Queue::fake();
    }

    private function document(): array
    {
        return ['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'content' => [['text' => 'Please ', 'bold' => true], ['text' => 'join', 'italic' => true]]],
            ['type' => 'bullet_list', 'items' => [[['text' => 'Bring ID', 'bold' => true, 'italic' => true]], [['text' => 'Rest well']]]],
            ['type' => 'ordered_list', 'items' => [[['text' => 'Register']], [['text' => 'Donate']]]],
        ]];
    }

    private function payload(array $extra = []): array
    {
        return [...['title' => 'Formatted drive', 'description' => 'Client preview is not authoritative',
            'location' => 'Dagupan', 'donation_date' => '2026-11-01', 'expires_at' => '2026-11-01'], ...$extra];
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->withToken($user->createToken('test')->plainTextToken);
        app('auth')->forgetGuards();

        return $user;
    }

    public static function roles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('roles')]
    public function test_rich_draft_edit_publish_and_donor_read_preserve_format_and_plain_previews(string $role): void
    {
        $this->signIn($role);
        $donor = User::factory()->create(['role' => 'donor']);
        $doc = $this->document();
        $text = "Please join\n\n• Bring ID\n• Rest well\n\n1. Register\n2. Donate";
        $id = $this->postJson('/api/admin/announcements', $this->payload(['description_document' => $doc]))->assertCreated()
            ->assertJsonPath('announcement.description_document', $doc)->assertJsonPath('announcement.description', $text)->json('announcement.id');
        $this->getJson('/api/admin/announcements/'.$id)->assertOk()->assertJsonPath('announcement.description_document', $doc);
        $doc['blocks'][0]['content'][0]['text'] = 'Kindly ';
        $this->post('/api/admin/announcements/'.$id, $this->payload(['_method' => 'PUT', 'description_document' => json_encode($doc)]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('announcement.description_document', $doc);
        $this->postJson('/api/admin/announcements/'.$id.'/publish')->assertOk();
        $this->getAsDonor($donor, '/api/announcements')->assertOk()->assertJsonPath('data.0.description_document', $doc);
        $this->getAsDonor($donor, '/api/announcements/'.$id)->assertOk()->assertJsonPath('opportunity.description_document', $doc)
            ->assertJsonPath('opportunity.description', str_replace('Please', 'Kindly', $text));
        $stored = json_decode(DB::table('donation_opportunities')->find($id)->description_document, true);
        $this->assertSame($doc, $stored);
        $this->assertDatabaseCount('notifications', 1);
        foreach (DB::table('notifications')->get() as $notification) {
            $this->assertStringNotContainsString('blocks', $notification->message);
            $this->assertStringNotContainsString('<', $notification->message);
        }
        $this->postJson('/api/admin/announcements/'.$id.'/draft')->assertOk()->assertJsonPath('announcement.description_document', $doc);
        $this->deleteJson('/api/admin/announcements/'.$id)->assertOk();
        $this->postJson('/api/admin/announcements/'.$id.'/restore')->assertOk()->assertJsonPath('announcement.description_document', $doc);
    }

    public function test_legacy_plain_text_is_not_interpreted_or_rewritten_and_remains_editable(): void
    {
        $this->signIn('admin');
        $text = "Existing <b>literal text</b>\nSecond line & symbols **intact**";
        $id = $this->postJson('/api/admin/announcements', $this->payload(['description' => $text]))->assertCreated()
            ->assertJsonPath('announcement.description', $text)->assertJsonPath('announcement.description_document', null)->json('announcement.id');
        $this->getJson('/api/admin/announcements/'.$id)->assertOk()->assertJsonPath('announcement.description', $text);
        $this->assertSame($text, DonationOpportunity::findOrFail($id)->description);
        $this->putJson('/api/admin/announcements/'.$id, $this->payload(['description' => 'Plain edit']))->assertOk()
            ->assertJsonPath('announcement.description', 'Plain edit')->assertJsonPath('announcement.description_document', null);
    }

    public static function invalidDocuments(): array
    {
        $paragraph = ['type' => 'paragraph', 'content' => [['text' => 'Safe']]];

        return [
            'html string' => ['<script>alert(1)</script>'],
            'unknown version' => [['version' => 2, 'blocks' => [$paragraph]]],
            'script node' => [['version' => 1, 'blocks' => [['type' => 'script', 'content' => [['text' => 'alert(1)']]]]]],
            'event attribute' => [['version' => 1, 'blocks' => [[...$paragraph, 'onclick' => 'alert(1)']]]],
            'unsafe link' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Open', 'href' => 'javascript:alert(1)']]]]]],
            'nested markup' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => ['html' => '<img onerror=alert(1)>']]]]]]],
            'invalid mark' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => 'Safe', 'bold' => 'true']]]]]],
            'empty' => [['version' => 1, 'blocks' => []]],
            'formatting only' => [['version' => 1, 'blocks' => [['type' => 'bullet_list', 'items' => [[['text' => " \n\u{200B}", 'bold' => true]]]]]]],
            'too long' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => str_repeat('x', 10001)]]]]]],
            'too many blocks' => [['version' => 1, 'blocks' => array_fill(0, 101, $paragraph)]],
            'map not list' => [['version' => 1, 'blocks' => ['named' => $paragraph]]],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_untrusted_or_empty_documents_are_rejected_without_creating_records(mixed $doc): void
    {
        $this->signIn('admin');
        $this->postJson('/api/admin/announcements', $this->payload(['description_document' => $doc]))->assertUnprocessable()->assertJsonValidationErrors('description_document');
        $this->assertDatabaseCount('donation_opportunities', 0);
    }

    public function test_markup_in_text_is_literal_and_numeric_content_is_valid(): void
    {
        $this->signIn('admin');
        foreach (['123', '<img src=x onerror="alert(1)"> & **literal**'] as $text) {
            $doc = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => $text]]]]];
            $this->postJson('/api/admin/announcements', $this->payload(['description_document' => $doc]))->assertCreated()
                ->assertJsonPath('announcement.description', $text)->assertJsonPath('announcement.description_document', $doc);
        }
    }

    public function test_year_and_month_continue_using_donation_date_across_every_status(): void
    {
        $this->signIn('super_admin');
        foreach (['2023-11-01', '2027-11-01', '2027-12-01'] as $date) {
            $this->postJson('/api/admin/announcements', $this->payload(['donation_date' => $date, 'expires_at' => $date]))->assertCreated();
        }
        $this->getJson('/api/admin/announcements')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/announcements?year=2027&month=11&status=draft&search=Dagupan')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.donation_date', '2027-11-01');
    }
}
