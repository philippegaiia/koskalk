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
            ->subject(__('auth.beta_invitation.email.subject'))
            ->greeting(__('auth.beta_invitation.email.greeting'))
            ->line(__('auth.beta_invitation.email.invitation', ['workspace' => $this->workspaceName]))
            ->action(__('auth.beta_invitation.email.action'), route('beta-invites.show', ['token' => $this->token]))
            ->line(__('auth.beta_invitation.email.expires', ['expiry' => $this->expiresAt->diffForHumans()]))
            ->line(__('auth.beta_invitation.email.ignore'));
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
