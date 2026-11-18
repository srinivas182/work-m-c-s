#!/usr/bin/env python3
"""Sprint 24.3: marks plain texts in Blade views for translation: >Some text< becomes >{{ __('Some text') }}<.
Texts that mix in {{ … }} or @directives are left for a person. Usage: scripts/i18n-blade.py <views…>"""
import re, sys

def wrap(m):
    lead, text, trail = m.group(1), m.group(2), m.group(3)
    t = re.sub(r'\s+', ' ', text).strip()
    if not re.search(r'[A-Za-z]', t) or '{{' in text or '@' in text or '{!!' in text or '&' in text or t.startswith('<'):
        return m.group(0)
    q = t.replace('\\', '\\\\').replace("'", "\\'")
    return f"{lead}{{{{ __('{q}') }}}}{trail}"

for path in sys.argv[1:]:
    src = open(path).read()
    head, sep, body = src.partition('<body')
    target = body if sep else src
    # Text between a closing '>' and the next '<', outside <style> and <script>.
    parts = re.split(r'(<style.*?</style>|<script.*?</script>)', target, flags=re.S)
    # Blade's {{ … }} and @directives hide PHP (e.g. $order->number): take them out first, then put them back.
    def mark(p):
        keep = []
        def hold(m):
            keep.append(m.group(0))
            return f'\x00{len(keep) - 1}\x00'
        p = re.sub(r'\{\{.*?\}\}|\{!!.*?!!\}|@\w+\s*\((?:[^()]|\([^()]*\))*\)', hold, p, flags=re.S)
        p = re.sub(r'(>\s*)([^<>\x00]+?)(\s*<)', wrap, p)
        return re.sub(r'\x00(\d+)\x00', lambda m: keep[int(m.group(1))], p)
    out = ''.join(p if p.startswith(('<style', '<script')) else mark(p) for p in parts)
    new = head + sep + out if sep else out
    if new != src:
        open(path, 'w').write(new)
        print('marked', path)
