import { useCallback, useEffect, useState } from 'react';
import { applyTheme, readThemePreference, type ResolvedTheme, type ThemePreference } from './theme';

/** Current theme preference, the resolved light/dark value, and a setter. */
export function useTheme() {
  const [preference, setPreferenceState] = useState<ThemePreference>(() => readThemePreference());
  const [resolved, setResolved] = useState<ResolvedTheme>(() =>
    document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light',
  );

  const setPreference = useCallback((next: ThemePreference) => {
    setPreferenceState(next);
    setResolved(applyTheme(next));
  }, []);

  // Follow the operating system while the preference is "system".
  useEffect(() => {
    if (preference !== 'system') return;
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => setResolved(applyTheme('system'));
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, [preference]);

  return { preference, resolved, setPreference };
}
