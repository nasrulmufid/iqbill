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
            ->latest('id')
            ->paginate(25);

        return view('admin.webhooks.index', [
            'logs' => $logs,
        ]);
    }
}
