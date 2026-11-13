/** One stored notification, as the bell and the notification centre read it (P2-E6-T4). */
export type NotificationItem = {
    id: string;
    type: string;
    title: string;
    message: string;
    /** Where it can be acted on; null when there is nothing to open. */
    url: string | null;
    is_read: boolean;
    created_at: string | null;
};

/** The shared prop that feeds the panel's bell; null for non-staff. */
export type NotificationBell = {
    unread: number;
    recent: NotificationItem[];
};
