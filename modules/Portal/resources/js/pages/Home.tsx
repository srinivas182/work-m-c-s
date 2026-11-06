import { PortalLayout } from '../components/PortalLayout';

export default function Home({ business, name }: { business: string; name: string }) {
  return (
    <PortalLayout
      business={business}
      title={name ? `Hi ${name.split(' ')[0]}` : 'Your account'}
      signedIn
    >
      <p className="text-sm">You’re signed in to your account with {business}.</p>
    </PortalLayout>
  );
}
