<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('Customer creates ticket in portal, is isolated from other customers, and technician claims and resolves it', function () {
    Queue::fake();

    $userA = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $custA = Customer::query()->create([
        'user_id' => $userA->id, 'customer_code' => 'CA', 'full_name' => 'Pelanggan A',
        'phone_whatsapp' => '628111', 'installation_address' => 'Jl A',
    ]);

    $userB = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Customer::query()->create([
        'user_id' => $userB->id, 'customer_code' => 'CB', 'full_name' => 'Pelanggan B',
        'phone_whatsapp' => '628222', 'installation_address' => 'Jl B',
    ]);

    $noc = User::factory()->create(['role' => UserRole::Noc, 'is_active' => true]);

    $this->actingAs($userA)->post(route('portal.tickets.store'), [
        'category' => 'gangguan',
        'priority' => 'high',
        'subject' => 'Modem LOS Merah',
        'description' => 'Indikator LOS berkedip merah sejak pagi.',
    ])->assertRedirect();

    $ticket = Ticket::query()->first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->status)->toBe('open')
        ->and($ticket->customer_id)->toBe($custA->id);

    // Pelanggan B tidak boleh mengakses tiket Pelanggan A
    $this->actingAs($userB)->get(route('portal.tickets.show', $ticket))->assertForbidden();

    // Teknisi (NOC) mengambil tiket
    $this->actingAs($noc)->post(route('admin.tickets.claim', $ticket))->assertRedirect();
    expect($ticket->fresh()->assigned_to_user_id)->toBe($noc->id)
        ->and($ticket->fresh()->status)->toBe('in_progress');

    // Catatan internal tidak tampil di portal pelanggan
    $this->actingAs($noc)->post(route('admin.tickets.reply', $ticket), [
        'message' => 'Catatan teknis internal ODP',
        'is_internal_note' => '1',
    ])->assertRedirect();

    $this->actingAs($userA)->get(route('portal.tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('Catatan teknis internal ODP');

    // Teknisi update status menjadi resolved
    $this->actingAs($noc)->patch(route('admin.tickets.status', $ticket), [
        'status' => 'resolved',
        'resolution_notes' => 'Kabel patchcord diganti.',
    ])->assertRedirect();

    expect($ticket->fresh()->status)->toBe('resolved')
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($j) => $j->templateType === 'ticket_updated');
});
