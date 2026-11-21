<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Ticket;
use App\Models\WhatsappNotificationLog;

class WhatsAppService
{
    public function __construct(
        private readonly WhatsAppGatewayInterface $gateway
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function availablePlaceholders(): array
    {
        return [
            '{customer_name}' => 'Customer full name',
            '{customer_code}' => 'Customer ID / code',
            '{package}' => 'Service package name',
            '{no_invoice}' => 'Invoice number',
            '{amount}' => 'Formatted invoice amount (Rp ...)',
            '{due_date}' => 'Invoice due date',
            '{payment_link}' => 'Smart Payment Link (/bayar/...)',
            '{company_name}' => 'Company / ISP name',
            '{pppoe_username}' => 'PPPoE username',
            '{isolir_date}' => 'Isolation date',
            '{ticket_number}' => 'Support ticket number',
            '{ticket_status}' => 'Ticket status',
        ];
    }

    public function sendTemplate(
        Customer $customer,
        string $templateType,
        ?Invoice $invoice = null,
        ?Ticket $ticket = null
    ): WhatsappNotificationLog {
        $message = $this->buildMessage($customer, $templateType, $invoice, $ticket);

        $log = WhatsappNotificationLog::query()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'template_type' => $templateType,
            'recipient_phone' => $customer->phone_whatsapp,
            'message_body' => $message,
            'delivery_status' => 'queued',
            'created_at' => now(),
        ]);

        return $this->deliverExistingLog($log, $templateType);
    }

    public function deliverExistingLog(WhatsappNotificationLog $log, ?string $contextLabel = null): WhatsappNotificationLog
    {
        $result = $this->gateway->send($log->recipient_phone, $log->message_body);

        $log->forceFill([
            'delivery_status' => $result['success'] ? 'sent' : 'failed',
            'gateway_response' => $result['response'],
        ])->save();

        if (!$result['success']) {
            $label = $contextLabel ?: $log->template_type;
            app(TelegramNotificationService::class)->notifyError(
                'WhatsApp Gateway (GOWA)',
                "Failed sending to {$log->recipient_phone} ({$label}): {$result['response']}"
            );
        }

        return $log;
    }

    public function renderCustomerMessage(
        Customer $customer,
        string $templateBody,
        ?Invoice $invoice = null,
        ?Ticket $ticket = null
    ): string {
        return $this->renderTemplateBody(
            $templateBody,
            $this->resolveVariables($customer, $invoice, $ticket)
        );
    }

    public function renderTemplateBody(string $templateBody, array $variables): string
    {
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $templateBody);
        $lowerMap = [];
        foreach ($variables as $key => $val) {
            $lowerMap[strtolower((string) $key)] = (string) $val;
        }

        return (string) preg_replace_callback('/\{\s*([a-zA-Z0-9_]+)\s*\}/', static function (array $m) use ($variables, $lowerMap): string {
            $exactToken = '{' . $m[1] . '}';
            if (array_key_exists($exactToken, $variables)) {
                return (string) $variables[$exactToken];
            }

            $lowerToken = '{' . strtolower($m[1]) . '}';

            return array_key_exists($lowerToken, $lowerMap) ? $lowerMap[$lowerToken] : $m[0];
        }, $normalizedBody);
    }

    /**
     * Convert WhatsApp text formatting (*bold*, _italic_, ~strike~, `monospace`, blockquote) into safe preview HTML.
     */
    public static function formatPreviewHtml(string $message): string
    {
        if (trim($message) === '') {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $message);
        $escaped = htmlspecialchars($normalized, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $tokens = [];
        $stash = static function (string $html) use (&$tokens): string {
            $key = "\x1ATOKEN" . count($tokens) . "\x1A";
            $tokens[$key] = $html;

            return $key;
        };

        // 1. Protect code blocks ```...``` and inline code `...` first
        $escaped = (string) preg_replace_callback('/```([\s\S]+?)```/u', static function (array $m) use ($stash): string {
            return $stash('<code class="rounded bg-black/5 px-1.5 py-0.5 font-mono text-[12px]">' . $m[1] . '</code>');
        }, $escaped);

        $escaped = (string) preg_replace_callback('/`([^`\n]+)`/u', static function (array $m) use ($stash): string {
            return $stash('<code class="rounded bg-black/5 px-1 py-0.5 font-mono text-[12px]">' . $m[1] . '</code>');
        }, $escaped);

        // 2. Protect URLs (e.g. /bayar/token_abc_123) so underscores in URLs are not parsed as italics
        $escaped = (string) preg_replace_callback('#https?://[^\s<]+#iu', static function (array $m) use ($stash): string {
            $rawUrl = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $safeHref = htmlspecialchars($rawUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return $stash('<a href="' . $safeHref . '" target="_blank" rel="noopener" class="underline text-sky-700 break-all">' . $m[0] . '</a>');
        }, $escaped);

        // 3. Apply WhatsApp regex formatting with safe word boundaries (*bold*, _italic_, ~strike~)
        $escaped = (string) preg_replace(
            '/(?<=^|[\s("\'\[~_>])\*([^\s*\n](?:[^*\n]*[^\s*\n])?)\*(?=$|[\s.,!?:;)"\'\]~_<])/um',
            '<strong class="font-bold">$1</strong>',
            $escaped
        );

        $escaped = (string) preg_replace(
            '/(?<=^|[\s("\'\[*~>])_([^\s_\n](?:[^_\n]*[^\s_\n])?)_(?=$|[\s.,!?:;)"\'\]*~<])/um',
            '<em class="italic">$1</em>',
            $escaped
        );

        $escaped = (string) preg_replace(
            '/(?<=^|[\s("\'\[*_>])~([^\s~\n](?:[^~\n]*[^\s~\n])?)~(?=$|[\s.,!?:;)"\'\]*_<])/um',
            '<del class="line-through text-slate-500">$1</del>',
            $escaped
        );

        // 4. WhatsApp blockquote (> text at start of line, escaped as &gt;)
        $escaped = (string) preg_replace(
            '/^&gt;\s?(.+)$/um',
            '<span class="block border-l-2 border-emerald-600/50 pl-2.5 text-slate-600">$1</span>',
            $escaped
        );

        // Restore protected tokens and preserve line breaks
        if ($tokens !== []) {
            $escaped = strtr($escaped, $tokens);
        }

        return nl2br($escaped, false);
    }

    /**
     * @return array<string, string>
     */
    public static function sampleVariables(): array
    {
        $company = CompanySetting::current()->company_name;

        return [
            '{customer_name}' => 'Budi Santoso',
            '{customer_code}' => 'CUST-20260001',
            '{package}' => 'Home Fiber 30 Mbps',
            '{no_invoice}' => 'INV-202611-000123',
            '{amount}' => 'Rp 225.000',
            '{due_date}' => '20 Nov 2026',
            '{payment_link}' => url('/bayar/contohTokenSmartLink123'),
            '{company_name}' => $company,
            '{pppoe_username}' => 'budi.fiber',
            '{isolir_date}' => '20 Nov 2026',
            '{ticket_number}' => 'TKT-202611-0001',
            '{ticket_status}' => 'RESOLVED',
        ];
    }

    public static function previewSample(string $templateBody): string
    {
        return (new self(app(WhatsAppGatewayInterface::class)))
            ->renderTemplateBody($templateBody, self::sampleVariables());
    }

    public static function previewSampleHtml(string $templateBody): string
    {
        return self::formatPreviewHtml(self::previewSample($templateBody));
    }

    /**
     * @return array<string, string>
     */
    private function resolveVariables(
        Customer $customer,
        ?Invoice $invoice = null,
        ?Ticket $ticket = null
    ): array {
        $invoice?->loadMissing(['subscription.package']);
        $company = CompanySetting::current()->company_name;
        $subscription = $invoice?->subscription ?? $customer->subscriptions()->with('package')->first();

        return [
            '{customer_name}' => $customer->full_name,
            '{customer_code}' => $customer->customer_code,
            '{package}' => $subscription?->package?->name ?? '-',
            '{no_invoice}' => $invoice?->invoice_number ?? '-',
            '{amount}' => $invoice ? 'Rp ' . number_format((float) $invoice->total_amount, 0, ',', '.') : '-',
            '{due_date}' => $invoice?->due_date?->translatedFormat('d M Y') ?? '-',
            '{payment_link}' => $invoice ? route('public.pay', $invoice->public_token) : '',
            '{company_name}' => $company,
            '{pppoe_username}' => $subscription?->service_username ?? '-',
            '{isolir_date}' => now('Asia/Jakarta')->translatedFormat('d M Y'),
            '{ticket_number}' => $ticket?->ticket_number ?? '-',
            '{ticket_status}' => $ticket ? strtoupper($ticket->status) : '-',
        ];
    }

    private function buildMessage(
        Customer $customer,
        string $templateType,
        ?Invoice $invoice,
        ?Ticket $ticket = null
    ): string {
        $variables = $this->resolveVariables($customer, $invoice, $ticket);
        $company = $variables['{company_name}'];

        $template = NotificationTemplate::query()
            ->where('key', $templateType)
            ->where('is_active', true)
            ->first();

        if ($template !== null) {
            return $this->renderTemplateBody($template->body_template, $variables);
        }

        $default = collect(NotificationTemplate::defaults())->firstWhere('key', $templateType);
        if ($default !== null) {
            return $this->renderTemplateBody($default['body_template'], $variables);
        }

        return "[{$company}] Informasi layanan untuk {$customer->full_name}.";
    }
}
