<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code, public string $purpose)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $heading = $this->purpose === 'password_reset'
            ? 'Reset your password'
            : 'Verify your account';

        return (new MailMessage)
            ->subject($heading)
            ->greeting($heading)
            ->line('Your verification code is:')
            ->line("**{$this->code}**")
            ->line('This code expires in 10 minutes.')
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
