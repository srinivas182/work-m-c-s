import { Head } from '@inertiajs/react';

interface ModuleSummary {
  name: string;
  version: string;
  description: string;
}

interface Props {
  modules: ModuleSummary[];
}

/**
 * Temporary landing page until the wurk.co.za marketing site (Sprint 3).
 * Lists installed modules outside production so the team can verify the build.
 */
export default function Welcome({ modules }: Props) {
  return (
    <>
      <Head title="Welcome" />
      <div className="relative min-h-dvh overflow-hidden bg-neutral-950 text-white">
        <div
          aria-hidden
          className="pointer-events-none absolute -top-40 left-1/2 h-[38rem] w-[38rem] -translate-x-1/2 rounded-full bg-violet-600/30 blur-3xl"
        />
        <main className="relative mx-auto flex max-w-4xl flex-col px-6 pt-28 pb-20 sm:pt-40">
          <span className="w-fit rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium tracking-wide text-white/70">
            Platform foundations
          </span>
          <h1 className="mt-6 text-5xl font-semibold tracking-tight text-balance sm:text-7xl">
            WURK
          </h1>
          <p className="mt-5 max-w-xl text-lg text-pretty text-white/65">
            Websites, bookings, shop, CRM and customer referrals for growing businesses across
            Africa.
          </p>

          {modules.length > 0 && (
            <section className="mt-16">
              <h2 className="text-sm font-medium tracking-wide text-white/50 uppercase">
                Installed modules
              </h2>
              <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                {modules.map((module) => (
                  <li
                    key={module.name}
                    className="rounded-2xl border border-white/10 bg-white/[0.04] p-5 backdrop-blur"
                  >
                    <div className="flex items-baseline justify-between gap-4">
                      <span className="font-medium">{module.name}</span>
                      <span className="text-xs text-white/40 tabular-nums">v{module.version}</span>
                    </div>
                    {module.description && (
                      <p className="mt-2 text-sm text-white/55">{module.description}</p>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}
        </main>
      </div>
    </>
  );
}
