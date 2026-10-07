<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_upload_stores_private_file_and_links_it_to_its_ticket(): void
    {
        Storage::fake('local');
        $operator = User::factory()->create(['role' => 'operator']);
        $plantId = DB::table('plants')->insertGetId(['code' => 'RX03', 'name' => 'Plant Test', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $machineId = DB::table('machines')->insertGetId(['code' => 'M01', 'name' => 'Machine Test', 'plant_id' => $plantId, 'section' => 'Test', 'is_active' => true, 'created_by' => $operator->id, 'updated_by' => $operator->id, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($operator, 'sanctum')->post('/api/incidents', [
            'plant_id' => $plantId,
            'machine_id' => $machineId,
            'problem_type' => 'Machine stopped',
            'status' => 'OPEN',
            'files' => [UploadedFile::fake()->image('damage-original.jpg', 2400, 1200)],
        ]);

        $response->assertCreated()->assertJsonPath('attachments.0.file_name', 'damage-original.jpg');
        $attachment = Attachment::query()->firstOrFail();
        $this->assertNotNull($attachment->incident_id);
        $this->assertNotNull($attachment->maintenance_ticket_id);
        $this->assertSame($operator->id, $attachment->uploaded_by);
        $this->assertStringNotContainsString('damage-original', $attachment->file_path);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->actingAs($operator, 'sanctum')->get('/api/attachments/'.$attachment->id)->assertOk();
        $this->actingAs($operator, 'sanctum')->get('/api/tickets/'.$response->json('ticket_number'))
            ->assertOk()
            ->assertJsonPath('attachments.0.id', $attachment->id)
            ->assertJsonPath('attachments.0.url', '/attachments/'.$attachment->id);
    }

    public function test_upload_rejects_unsupported_mime_and_enforces_incident_ownership(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $other = User::factory()->create(['role' => 'operator']);
        $incident = Incident::query()->create(['machine_id' => 1, 'problem_type' => 'Machine stopped', 'description' => 'Test incident', 'status' => 'OPEN', 'reported_by' => $operator->id]);

        $this->actingAs($operator, 'sanctum')->withHeader('Accept', 'application/json')->post("/api/incidents/{$incident->id}/attachments", [
            'files' => [UploadedFile::fake()->create('script.php', 10, 'application/x-php')],
        ])->assertUnprocessable();

        $this->actingAs($operator, 'sanctum')->withHeader('Accept', 'application/json')->post("/api/incidents/{$incident->id}/attachments", [
            'files' => [UploadedFile::fake()->create('oversized.jpg', 18433, 'image/jpeg')],
        ])->assertUnprocessable();

        $this->actingAs($other, 'sanctum')->withHeader('Accept', 'application/json')->post("/api/incidents/{$incident->id}/attachments", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ])->assertForbidden();
    }
}
