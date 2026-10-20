/**
 * Maps an Inertia page name to its module file.
 *
 * - "Health::Status"       -> modules/Health/resources/js/pages/Status.tsx
 * - "Bookings::Admin/List" -> modules/Bookings/resources/js/pages/Admin/List.tsx
 * - "Dashboard"            -> resources/js/pages/Dashboard.tsx (platform-level pages)
 */
export function pagePath(name: string): string {
  const separator = name.indexOf('::');

  if (separator === -1) {
    return `./pages/${name}.tsx`;
  }

  const module = name.slice(0, separator);
  const page = name.slice(separator + 2);

  if (!/^[A-Z][A-Za-z0-9]*$/.test(module) || page === '') {
    throw new Error(`Invalid Inertia page name "${name}"`);
  }

  return `../../modules/${module}/resources/js/pages/${page}.tsx`;
}

export function resolvePage<T>(name: string, pages: Record<string, () => Promise<T>>): Promise<T> {
  const loader = pages[pagePath(name)];

  if (!loader) {
    throw new Error(`Inertia page "${name}" not found (looked for ${pagePath(name)})`);
  }

  return loader();
}
