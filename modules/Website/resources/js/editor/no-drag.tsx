import { ActionBar, usePuck, type Overrides } from '@puckeditor/core';
import { ArrowDown, ArrowUp, Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/design-system/components/button';
import { Dialog, DialogContent } from '@/design-system/components/dialog';
import { __ } from '@/i18n';

/**
 * WCAG 2.2 "Dragging movements" (2.5.7), Sprint 18.5b: everything the page editor does by dragging can also be done
 * with a single tap or the keyboard.
 *
 * - **Add**: "Add section" in the header lists every section; the one chosen goes in after the selected section (or
 *   at the end of the page) and is selected, so its fields open straight away.
 * - **Move**: "Move up" and "Move down" above a selected section's fields, and in its toolbar on the canvas.
 */

/** Puck's top-level area ("root:default-zone"): where a section goes when nothing is selected. */
export const ROOT_ZONE = 'root:default-zone';

function MoveButtons() {
  const { appState, dispatch, getItemBySelector } = usePuck();
  const selector = appState.ui.itemSelector;
  if (!selector) return null;
  const zone = selector.zone ?? ROOT_ZONE;
  const move = (to: number) => {
    dispatch({
      type: 'reorder',
      sourceIndex: selector.index,
      destinationIndex: to,
      destinationZone: zone,
    });
    dispatch({ type: 'setUi', ui: { itemSelector: { index: to, zone } } });
  };
  const first = selector.index === 0;
  const last = getItemBySelector({ index: selector.index + 1, zone }) === undefined;
  return (
    <>
      <ActionBar.Action
        label={__('Move up')}
        disabled={first}
        onClick={() => move(selector.index - 1)}
      >
        <ArrowUp size={16} aria-hidden />
      </ActionBar.Action>
      <ActionBar.Action
        label={__('Move down')}
        disabled={last}
        onClick={() => move(selector.index + 1)}
      >
        <ArrowDown size={16} aria-hidden />
      </ActionBar.Action>
    </>
  );
}

/**
 * After adding, focus goes to the new section's tools beside its fields — what's edited next — rather than back to
 * "Add section". The editor re-renders its header and fields for the new selection, so this keeps trying for a moment
 * until the tools are there and focus stays on them.
 */
function focusSectionTools() {
  let tries = 0;
  const attempt = () => {
    // The editor draws the fields panel twice (beside the page, and in a phone drawer): use the one on screen.
    const tool = Array.from(
      document.querySelectorAll<HTMLButtonElement>(
        '[role="group"][aria-label="Move this section"] button:not([disabled])',
      ),
    ).find((b) => b.checkVisibility());
    if (tool && document.activeElement === tool && tries > 4) return;
    tool?.focus();
    if (tries++ < 30) window.setTimeout(attempt, 50);
  };
  window.setTimeout(attempt, 50);
}

/** "Add section": every section, as buttons; no dragging. Lives in the editor's header. */
export function AddSection() {
  const { appState, config, dispatch } = usePuck();
  const [open, setOpen] = useState(false);
  const add = (name: string) => {
    const selected = appState.ui.itemSelector;
    const zone = selected?.zone ?? ROOT_ZONE;
    const index = selected ? selected.index + 1 : appState.data.content.length;
    dispatch({
      type: 'insert',
      componentType: name,
      destinationIndex: index,
      destinationZone: zone,
    });
    dispatch({ type: 'setUi', ui: { itemSelector: { index, zone } } });
    setOpen(false);
    focusSectionTools();
  };
  return (
    <>
      <Button size="sm" variant="secondary" onClick={() => setOpen(true)}>
        <Plus aria-hidden /> {__('Add section')}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent
          title={__('Add a section')}
          description={
            appState.ui.itemSelector
              ? 'It goes in after the selected section.'
              : 'It goes in at the end of the page.'
          }
        >
          <ul aria-label={__('Sections')} className="grid max-h-[60dvh] gap-1 overflow-y-auto">
            {Object.entries(config.components).map(([name, c]) => (
              <li key={name}>
                <Button variant="ghost" className="w-full justify-start" onClick={() => add(name)}>
                  {c.label ?? name}
                </Button>
              </li>
            ))}
          </ul>
        </DialogContent>
      </Dialog>
    </>
  );
}

/** Above a selected section's fields: move it without dragging. */
function SectionTools() {
  const { appState, dispatch, getItemBySelector } = usePuck();
  const selector = appState.ui.itemSelector;
  if (!selector) return null;
  const zone = selector.zone ?? ROOT_ZONE;
  const move = (to: number) => {
    dispatch({
      type: 'reorder',
      sourceIndex: selector.index,
      destinationIndex: to,
      destinationZone: zone,
    });
    dispatch({ type: 'setUi', ui: { itemSelector: { index: to, zone } } });
  };
  const last = getItemBySelector({ index: selector.index + 1, zone }) === undefined;
  return (
    <div
      role="group"
      aria-label={__('Move this section')}
      className="flex gap-2 border-b border-[#e6e3f0] px-4 py-3"
    >
      <Button
        size="sm"
        variant="secondary"
        disabled={selector.index === 0}
        onClick={() => move(selector.index - 1)}
      >
        <ArrowUp aria-hidden /> {__('Move up')}
      </Button>
      <Button
        size="sm"
        variant="secondary"
        disabled={last}
        onClick={() => move(selector.index + 1)}
      >
        <ArrowDown aria-hidden /> {__('Move down')}
      </Button>
    </div>
  );
}

export const noDragOverrides: Partial<Overrides> = {
  actionBar: ({
    label,
    children,
    parentAction,
  }: {
    label?: string;
    children: ReactNode;
    parentAction: ReactNode;
  }) => (
    <ActionBar label={label}>
      <ActionBar.Group>
        {parentAction}
        <MoveButtons />
      </ActionBar.Group>
      <ActionBar.Group>{children}</ActionBar.Group>
    </ActionBar>
  ),
  fields: ({ children }: { children: ReactNode }) => (
    <>
      <SectionTools />
      {children}
    </>
  ),
};
