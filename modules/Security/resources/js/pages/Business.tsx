import { Head, useForm } from '@inertiajs/react';
import { Card, CardContent, Label, PageHeader, Switch } from '@/design-system';
import AppShell from '@/layouts/AppShell';

export default function Business({ requireMfa }: { requireMfa: boolean }) {
  const form = useForm({ require_mfa: requireMfa });
  return (
    <AppShell title="Team security">
      <Head title="Team security" />
      <div className="grid max-w-3xl gap-8">
        <PageHeader
          title="Team security"
          description="Protect your business by asking everyone on the team to use an authenticator app when they sign in."
        />
        <Card>
          <CardContent className="flex items-start justify-between gap-6 p-6">
            <div>
              <Label htmlFor="require-mfa" className="text-base">
                Require an authenticator app
              </Label>
              <p className="text-fg-muted mt-1 text-sm">
                Team members without one are asked to set it up before they can continue. Set up
                your own first.
              </p>
              {form.errors.require_mfa && (
                <p className="text-danger-11 mt-2 text-sm" role="alert">
                  {form.errors.require_mfa}
                </p>
              )}
            </div>
            <Switch
              id="require-mfa"
              checked={form.data.require_mfa}
              disabled={form.processing}
              onCheckedChange={(on) => {
                form.setData('require_mfa', on);
                form.transform(() => ({ require_mfa: on }));
                form.put(window.location.pathname, {
                  preserveScroll: true,
                  onError: () => form.setData('require_mfa', requireMfa),
                });
              }}
            />
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
