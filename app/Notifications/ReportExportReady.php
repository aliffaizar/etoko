<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A queued report export has finished (P2-E7-T9).
 *
 * Database only: the file is downloaded from the panel, so the bell is where
 * the person who asked for it will look. The payload names the file, which
 * `NotificationPresenter` turns into the download link.
 */
class ReportExportReady extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $format,
        private readonly string $file,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'report_export',
            'file' => $this->file,
            'format' => $this->format,
            'message' => sprintf(
                '%s (%s) siap diunduh.',
                $this->title,
                $this->format === 'pdf' ? 'PDF' : 'Excel',
            ),
        ];
    }
}
