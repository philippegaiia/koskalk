<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class BetaWorkspaceInvitation extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $workspaceName,
        public readonly Carbon $expiresAt,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Soapkraft beta invitation')
            ->greeting('Welcome to Soapkraft')
            ->line("You have been invited to create the {$this->workspaceName} workspace.")
            ->action('Create your workspace', route('beta-invites.show', ['token' => $this->token]))
            ->line("This invitation expires {$this->expiresAt->diffForHumans()}.")
            ->line('If you were not expecting this invitation, you can safely ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_name' => $this->workspaceName,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
