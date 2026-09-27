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
            ->subject(__('Company invitation'))
            ->line(__('You have been invited to join :company.', ['company' => $this->workspaceName]))
            ->action(__('Accept invitation'), route('workspace-invitations.show', ['token' => $this->token]))
            ->line(__('This invitation expires in seven days.'));
    }
}
