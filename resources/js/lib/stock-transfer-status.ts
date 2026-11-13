import type { StatusTone } from '@/components/panel/status-badge';

/**
 * Badge tone per stock transfer status (P2-E3-T3), shared by the listing and
 * the detail page so one state never reads two ways.
 *
 * Amber marks what waits on somebody or ended short; blue is in motion.
 */
export const TRANSFER_STATUS_TONE: Record<string, StatusTone> = {
    requested: 'warning',
    approved: 'info',
    rejected: 'danger',
    in_transit: 'info',
    received: 'success',
    partially_received: 'warning',
    cancelled: 'neutral',
};
