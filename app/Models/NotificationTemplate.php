<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'key',
        'label',
        'body_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Default Indonesian WhatsApp templates are intentionally preserved for end customers;
     * admin UI labels are localized in English.
     *
     * @return array<int, array{key: string, label: string, body_template: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'invoice_created',
                'label' => 'Invoice Issued (H-7)',
                'body_template' => "[{company_name}] Halo {customer_name}, tagihan internet paket {package} ({no_invoice}) sebesar {amount} telah terbit. Jatuh tempo: {due_date}. Bayar praktis via QRIS: {payment_link}",
            ],
            [
                'key' => 'reminder_h7',
                'label' => 'Reminder H-7',
                'body_template' => "[{company_name}] Pengingat tagihan {no_invoice} paket {package} sebesar {amount}. Jatuh tempo: {due_date}. Klik untuk bayar: {payment_link}",
            ],
            [
                'key' => 'reminder_h3',
                'label' => 'Reminder H-3',
                'body_template' => "[{company_name}] Halo {customer_name}, tagihan {no_invoice} ({package}) sebesar {amount} akan jatuh tempo pada {due_date}. Bayar cepat tanpa login: {payment_link}",
            ],
            [
                'key' => 'reminder_h1',
                'label' => 'Reminder H-1 (Due Tomorrow)',
                'body_template' => "[{company_name}] PENTING: Tagihan {no_invoice} sebesar {amount} jatuh tempo besok ({due_date}). Segera selesaikan agar layanan tidak terisolir: {payment_link}",
            ],
            [
                'key' => 'payment_receipt',
                'label' => 'Payment Receipt',
                'body_template' => "[{company_name}] Terima kasih {customer_name}! Pembayaran tagihan {no_invoice} ({package}) sebesar {amount} telah kami terima.",
            ],
            [
                'key' => 'service_isolated',
                'label' => 'Automatic/Manual Isolation Notice',
                'body_template' => "[{company_name}] Layanan internet {pppoe_username} ({package}) sementara terisolir pada {isolir_date} karena tagihan {no_invoice} ({amount}) belum terbayar. Bayar sekarang untuk aktivasi otomatis: {payment_link}",
            ],
            [
                'key' => 'service_restored',
                'label' => 'Service Restoration Notice',
                'body_template' => "[{company_name}] Layanan internet {pppoe_username} ({package}) untuk tagihan {no_invoice} telah aktif kembali. Terima kasih.",
            ],
            [
                'key' => 'ticket_updated',
                'label' => 'Support Ticket Update',
                'body_template' => "[{company_name}] Halo {customer_name}, status tiket Anda {ticket_number} diperbarui menjadi: {ticket_status}.",
            ],
        ];
    }
}
