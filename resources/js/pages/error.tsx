import { Head } from '@inertiajs/react';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import HostLink from '@/components/host-link';

type ErrorPageProps = {
    status: number;
    title: string;
    description: string;
    /** The message the server attached deliberately, if any. */
    message: string | null;
    homeUrl: string;
    homeLabel: string;
};

/**
 * The page every handled HTTP failure lands on (BUG-04).
 *
 * Rendered without a panel shell: a 404 or an expired session can happen on a
 * route whose layout the user may not even be allowed to see. The way back is
 * the landing page of the user's own role (`RoleHome`), so a branch admin who
 * followed an `/admin` link is not left on a dead end.
 */
export default function ErrorPage({
    status,
    title,
    description,
    message,
    homeUrl,
    homeLabel,
}: ErrorPageProps) {
    return (
        <>
            <Head title={title} />

            <main className="flex min-h-screen items-center justify-center bg-background p-6">
                <div className="w-full max-w-md space-y-6 text-center">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <ShieldAlert className="size-6" aria-hidden />
                    </div>

                    <div className="space-y-2">
                        <p className="text-sm font-medium text-muted-foreground">
                            Kode {status}
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>

                    {message && (
                        <p className="rounded-lg border bg-muted/40 p-4 text-left text-sm">
                            {message}
                        </p>
                    )}

                    <Button asChild>
                        <HostLink href={homeUrl}>
                            <ArrowLeft aria-hidden />
                            {homeLabel}
                        </HostLink>
                    </Button>
                </div>
            </main>
        </>
    );
}
