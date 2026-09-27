<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceMemberInvitation extends Notification
{
    public function __construct(public readonly string $token, public readonly string $workspaceName) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('workspaces.email.subject'))
            ->line(__('workspaces.acceptance.invited_description', ['company' => $this->workspaceName]))
            ->action(__('workspaces.acceptance.accept'), route('workspace-invitations.show', ['token' => $this->token]))
            ->line(__('workspaces.email.expires'));
    }
}
