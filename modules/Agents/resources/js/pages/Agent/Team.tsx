import { Badge, EmptyState } from '@/design-system';
import { Users } from 'lucide-react';
import { AgentLayout } from '../../components/AgentLayout';

interface Member {
  level: number;
  name: string;
  code: string;
  status: 'active' | 'suspended';
  businesses: number;
}

/** The agent's team by level — counts only, never others' earnings. */
export default function AgentTeam({ team }: { team: Member[] }) {
  const levels = [...new Set(team.map((m) => m.level))];
  return (
    <AgentLayout title="Your team">
      {team.length === 0 ? (
        <EmptyState
          icon={Users}
          title="No team yet"
          description="Agents WURK places under you appear here."
        />
      ) : (
        levels.map((lvl) => (
          <section key={lvl} aria-label={`Level ${lvl}`} className="grid gap-2">
            <h2 className="font-medium">
              {lvl === 1 ? 'Level 1 — your direct team' : `Level ${lvl}`}
            </h2>
            <ul className="grid gap-1 text-sm">
              {team
                .filter((m) => m.level === lvl)
                .map((m) => (
                  <li key={m.code} className="flex flex-wrap items-center gap-2">
                    {m.name} <span className="font-mono">{m.code}</span> ·{' '}
                    {m.businesses === 1 ? '1 business' : `${m.businesses} businesses`}
                    {m.status === 'suspended' && <Badge tone="warning">Suspended</Badge>}
                  </li>
                ))}
            </ul>
          </section>
        ))
      )}
    </AgentLayout>
  );
}
