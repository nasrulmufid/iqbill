<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookLog;
use Illuminate\View\View;

class WebhookLogController extends Controller
{
    public function index(): View
    {
        $logs = PaymentWebhookLog::query()
            ->select([
                'id',
                'svix_id',
                'external_reference',
                'payment_code',
                'event_type',
                'verification_method',
                'signature_valid',
                'http_status_returned',
                'processing_status',
                'error_message',
                'processed_at',
                'created_at',
            ])
            ->latest('id')
            ->paginate(25);

        return view('admin.webhooks.index', [
            'logs' => $logs,
        ]);
    }
}
