<?php

namespace App\Livewire\Concerns;

trait InteractsWithAppNotifications
{
    protected function showAppNotification(string $message, string $type = 'success'): void
    {
        $normalizedType = $type === 'error' ? 'error' : 'success';

        $this->statusMessage = $message;

        if (property_exists($this, 'statusType')) {
            $this->statusType = $normalizedType;
        }

        $this->dispatch(
            'app-notification',
            message: $message,
            type: $normalizedType,
        );
    }
}
