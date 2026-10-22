<?php

namespace Tests\Feature;

use App\Models\Ticket;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TicketTestDatabase;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TicketTestDatabase::verify($this->app);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'title' => 'Printer cannot connect',
            'description' => 'The office printer cannot connect to the network.',
            'category' => 'network',
            'priority' => 'high',
        ];
    }

    public function test_creating_a_ticket_returns_201_and_persists_defaults(): void
    {
        $response = $this->postJson('/api/tickets', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Printer cannot connect')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonStructure(['data' => ['id', 'title', 'description', 'category', 'priority', 'status', 'created_at', 'updated_at']]);

        $this->assertSame(url('/api/tickets/'.$response->json('data.id')), $response->headers->get('Location'));
        $this->assertDatabaseHas('tickets', [...$this->payload(), 'status' => 'open']);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'blank title' => ['title', '   '],
            'long title' => ['title', str_repeat('a', 256)],
            'missing description' => ['description', null],
            'invalid category' => ['category', 'printer'],
            'invalid priority' => ['priority', 'urgent'],
            'invalid status' => ['status', 'closed'],
            'null status' => ['status', null],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_creation_is_rejected_without_persisting(string $field, mixed $value): void
    {
        $this->postJson('/api/tickets', [...$this->payload(), $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_title_is_required_even_without_accept_json_header(): void
    {
        $payload = $this->payload();
        unset($payload['title']);

        $this->post('/api/tickets', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_an_existing_ticket_can_be_retrieved(): void
    {
        $ticket = Ticket::factory()->create($this->payload());

        $this->get('/api/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.category', 'network');
    }

    public function test_combined_filters_group_the_search_alternatives(): void
    {
        $titleMatch = Ticket::factory()->create($this->payload());
        $descriptionMatch = Ticket::factory()->create([
            ...$this->payload(), 'title' => 'Cannot print',
        ]);
        Ticket::factory()->create([...$this->payload(), 'status' => 'resolved']);
        Ticket::factory()->create([...$this->payload(), 'category' => 'hardware']);
        Ticket::factory()->create([...$this->payload(), 'title' => 'WiFi issue', 'description' => 'No signal']);

        $this->getJson('/api/tickets?status=open&category=network&search=PRINTER')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $descriptionMatch->id)
            ->assertJsonPath('data.1.id', $titleMatch->id);
    }

    public function test_each_filter_works_individually(): void
    {
        Ticket::factory()->create($this->payload());
        Ticket::factory()->create([
            'title' => 'Broken screen', 'description' => 'Display is cracked',
            'category' => 'hardware', 'status' => 'resolved',
        ]);

        foreach (['status=open', 'category=network', 'search=printer'] as $filter) {
            $this->getJson('/api/tickets?'.$filter)->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_empty_list_and_pagination(): void
    {
        $this->getJson('/api/tickets')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        Ticket::factory()->count(3)->create();

        $this->getJson('/api/tickets?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_invalid_filters_return_validation_errors(): void
    {
        $this->getJson('/api/tickets?status=closed&category=bad&per_page=101&page=0&search[]=printer')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'category', 'per_page', 'page', 'search']);
    }

    public function test_patch_can_update_only_status_and_preserves_other_fields(): void
    {
        $ticket = Ticket::factory()->create($this->payload());

        $this->patchJson('/api/tickets/'.$ticket->id, ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, ...$this->payload(), 'status' => 'in_progress']);
    }

    public function test_all_editable_fields_can_be_updated(): void
    {
        $ticket = Ticket::factory()->create();
        $changes = [
            'title' => 'Updated ticket', 'description' => 'Updated description',
            'category' => 'software', 'priority' => 'critical', 'status' => 'resolved',
        ];

        $this->patchJson('/api/tickets/'.$ticket->id, $changes)->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, ...$changes]);
    }

    public function test_invalid_update_does_not_change_the_ticket(): void
    {
        $ticket = Ticket::factory()->create($this->payload());

        $this->patchJson('/api/tickets/'.$ticket->id, ['title' => null, 'status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'status']);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, ...$this->payload(), 'status' => 'open']);
    }

    public function test_deletion_persists_and_returns_json(): void
    {
        $ticket = Ticket::factory()->create();

        $this->deleteJson('/api/tickets/'.$ticket->id)->assertOk()
            ->assertExactJson(['message' => 'Ticket deleted successfully.']);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
        $this->getJson('/api/tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_unknown_ticket_returns_json_404_for_every_item_endpoint(): void
    {
        $this->get('/api/tickets/999999')->assertNotFound()->assertJsonStructure(['message']);
        $this->patchJson('/api/tickets/999999', ['status' => 'resolved'])->assertNotFound();
        $this->deleteJson('/api/tickets/999999')->assertNotFound();
    }

    public function test_client_cannot_override_server_managed_fields(): void
    {
        $response = $this->postJson('/api/tickets', [
            ...$this->payload(), 'id' => 999999, 'created_at' => '2000-01-01',
        ])->assertCreated();

        $this->assertNotSame(999999, $response->json('data.id'));
        $this->assertStringStartsNotWith('2000-01-01', $response->json('data.created_at'));
    }
}
