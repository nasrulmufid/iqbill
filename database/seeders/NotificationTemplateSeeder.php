<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (NotificationTemplate::defaults() as $item) {
            NotificationTemplate::query()->firstOrCreate(
                ['key' => $item['key']],
                [
                    'label' => $item['label'],
                    'body_template' => $item['body_template'],
                    'is_active' => true,
                ]
            );
        }
    }
}
