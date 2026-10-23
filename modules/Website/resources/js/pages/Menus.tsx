import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CornerDownRight, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Item {
  label: string;
  type: 'page' | 'link';
  page_id?: string;
  href?: string;
  children?: Item[];
}

interface Props {
  menus: { header: Item[]; footer: Item[] };
  pages: { id: string; title: string; slug: string; is_home: boolean }[];
  limits: { header: number; footer: number; children: number };
  base: string;
}

type Where = 'header' | 'footer';

function ItemRow({
  item,
  pages,
  onChange,
  onRemove,
  onMove,
  first,
  last,
  nested,
}: {
  item: Item;
  pages: Props['pages'];
  onChange: (next: Item) => void;
  onRemove: () => void;
  onMove: (d: number) => void;
  first: boolean;
  last: boolean;
  nested: boolean;
}) {
  return (
    <div
      className={`border-border grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_9rem_1.3fr_auto] sm:items-center ${nested ? 'bg-sunken ml-6' : ''}`}
    >
      <Input
        aria-label="Menu label"
        value={item.label}
        maxLength={40}
        placeholder="Label"
        onChange={(e) => onChange({ ...item, label: e.target.value })}
      />
      <Select
        value={item.type}
        onValueChange={(v) => onChange({ ...item, type: v as Item['type'] })}
      >
        <SelectTrigger aria-label="Links to">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="page">A page</SelectItem>
          <SelectItem value="link">A web address</SelectItem>
        </SelectContent>
      </Select>
      {item.type === 'page' ? (
        <Select value={item.page_id ?? ''} onValueChange={(v) => onChange({ ...item, page_id: v })}>
          <SelectTrigger aria-label="Page">
            <SelectValue placeholder="Choose a page" />
          </SelectTrigger>
          <SelectContent>
            {pages.map((p) => (
              <SelectItem key={p.id} value={p.id}>
                {p.title}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ) : (
        <Input
          aria-label="Web address"
          value={item.href ?? ''}
          placeholder="https://…, #section, mailto: or tel:"
          onChange={(e) => onChange({ ...item, href: e.target.value })}
        />
      )}
      <div className="flex justify-end gap-0.5">
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Move ${item.label || 'item'} up`}
          disabled={first}
          onClick={() => onMove(-1)}
        >
          <ArrowUp />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Move ${item.label || 'item'} down`}
          disabled={last}
          onClick={() => onMove(1)}
        >
          <ArrowDown />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Remove ${item.label || 'item'}`}
          onClick={onRemove}
        >
          <Trash2 />
        </Button>
      </div>
    </div>
  );
}

const move = <T,>(list: T[], i: number, d: number): T[] => {
  const next = [...list];
  const [x] = next.splice(i, 1);
  if (x !== undefined) next.splice(i + d, 0, x);
  return next;
};

export default function Menus({ menus, pages, limits, base }: Props) {
  const [data, setData] = useState(menus);
  const [saving, setSaving] = useState(false);
  const errors = usePage().props.errors as Record<string, string>;
  const fresh = (): Item => ({ label: '', type: 'page', page_id: pages[0]?.id });

  const update = (where: Where, items: Item[]) => setData((d) => ({ ...d, [where]: items }));

  const section = (where: Where, title: string, description: string) => {
    const items = data[where];
    return (
      <Card>
        <CardHeader>
          <CardTitle>{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          {items.length === 0 && <p className="text-fg-muted text-sm">No links yet.</p>}
          {items.map((item, i) => (
            <div key={i} className="grid gap-2">
              <ItemRow
                item={item}
                pages={pages}
                first={i === 0}
                last={i === items.length - 1}
                nested={false}
                onChange={(next) =>
                  update(
                    where,
                    items.map((x, j) => (j === i ? next : x)),
                  )
                }
                onRemove={() =>
                  update(
                    where,
                    items.filter((_, j) => j !== i),
                  )
                }
                onMove={(d) => update(where, move(items, i, d))}
              />
              {item.children?.map((child, k) => (
                <ItemRow
                  key={k}
                  item={child}
                  pages={pages}
                  nested
                  first={k === 0}
                  last={k === (item.children?.length ?? 0) - 1}
                  onChange={(next) =>
                    update(
                      where,
                      items.map((x, j) =>
                        j === i
                          ? { ...x, children: x.children?.map((c, m) => (m === k ? next : c)) }
                          : x,
                      ),
                    )
                  }
                  onRemove={() =>
                    update(
                      where,
                      items.map((x, j) =>
                        j === i ? { ...x, children: x.children?.filter((_, m) => m !== k) } : x,
                      ),
                    )
                  }
                  onMove={(d) =>
                    update(
                      where,
                      items.map((x, j) =>
                        j === i ? { ...x, children: move(x.children ?? [], k, d) } : x,
                      ),
                    )
                  }
                />
              ))}
              {where === 'header' && (item.children?.length ?? 0) < limits.children && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-6 w-fit"
                  onClick={() =>
                    update(
                      where,
                      items.map((x, j) =>
                        j === i ? { ...x, children: [...(x.children ?? []), fresh()] } : x,
                      ),
                    )
                  }
                >
                  <CornerDownRight /> Add a sub-item
                </Button>
              )}
            </div>
          ))}
          {errors[where] && (
            <p role="alert" className="text-danger-11 text-sm">
              {errors[where]}
            </p>
          )}
          {items.length < limits[where] && (
            <Button
              variant="secondary"
              className="w-fit"
              onClick={() => update(where, [...items, fresh()])}
            >
              <Plus /> Add a link
            </Button>
          )}
        </CardContent>
      </Card>
    );
  };

  return (
    <AppShell title="Menus">
      <Head title="Menus" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Menus"
          description="What visitors see at the top and bottom of every page. Links to your pages update themselves if you change a page’s address."
          actions={
            <Button
              loading={saving}
              onClick={() =>
                router.put(base, data as never, {
                  preserveScroll: true,
                  onStart: () => setSaving(true),
                  onFinish: () => setSaving(false),
                })
              }
            >
              Save menus
            </Button>
          }
        />
        {section(
          'header',
          'Header menu',
          `Up to ${limits.header} links. On phones it becomes a tidy menu button. Add sub-items for a dropdown.`,
        )}
        {section(
          'footer',
          'Footer links',
          `Up to ${limits.footer} links — social pages, policies, contact.`,
        )}
      </div>
    </AppShell>
  );
}
