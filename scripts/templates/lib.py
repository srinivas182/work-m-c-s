"""Writes WURK template designs (Sprint 20.4): modules/Website/templates/{key}.json, their photo places in
templates/photos/slots.json, and crisp stand-in images. Run a part with: python3 scripts/templates/part_a.py
(needs Pillow and rsvg-convert). Install afterwards with php artisan website:install-templates."""
import colorsys
import json
import os
import re
import subprocess
import tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SLOTS = {}  # key -> slot -> {shows, search, decorative?, icon}


class T:
    def __init__(self, category, key, name, position, description, primary, secondary, fonts, corners):
        self.category, self.key, self.name, self.position, self.description = category, key, name, position, description
        self.branding = {'primary': primary, 'secondary': secondary, 'font_pairing': fonts, 'corners': corners}
        self.pages = []
        self.header = []
        self.button = ('Contact us', '/contact')
        SLOTS[key] = {}

    def img(self, slot, alt, shows, search, icon, decorative=False):
        """A photo place: the stand-in's address, and what HQ's photo should show."""
        SLOTS[self.key][slot] = {'shows': shows, 'search': search, 'icon': icon, **({'decorative': True} if decorative else {})}
        return {'src': f'/images/templates/{self.key}/{slot}-1600.webp', 'alt': alt}

    def page(self, key, title, blocks, seo_title=None, seo_description=None, menu=True):
        header = blk('header', 'Header', buttonLabel=self.button[0], buttonHref=self.button[1], sticky=True)
        footer = blk('footer', 'Footer')
        self.pages.append({'key': key, 'title': title, 'slug': key, 'is_home': key == 'home',
                           'seo_title': seo_title, 'seo_description': seo_description,
                           'content': {'schema': 1, 'blocks': [header, *blocks, footer]}})
        if menu and key != 'home':
            self.header.append({'label': title, 'type': 'page', 'page': key})

    def write(self):
        legal = [{'label': l, 'type': 'page', 'page': p} for l, p in (('Privacy policy', 'privacy'), ('Terms of use', 'terms'), ('Cookies', 'cookies'))]
        doc = {'category': self.category, 'key': self.key, 'name': self.name, 'position': self.position, 'description': self.description,
               'definition': {'branding': self.branding, 'menus': {'header': self.header, 'footer': legal}, 'pages': self.pages}}
        with open(f'{REPO}/modules/Website/templates/{self.key}.json', 'w') as f:
            json.dump(doc, f, indent=2, ensure_ascii=False)
            f.write('\n')


VERSIONS = {'Hero': 2}


def blk(id, type, **props):
    return {'id': id, 'type': type, 'version': VERSIONS.get(type, 1), 'props': props}


def hero(layout, heading, text, primary, secondary=('', ''), images=(), facts=()):
    return blk('hero', 'Hero', layout=layout, heading=heading, text=text, primaryLabel=primary[0], primaryHref=primary[1],
               secondaryLabel=secondary[0], secondaryHref=secondary[1], facts=[{'text': f} for f in facts],
               images=list(images), autoplay=False)


def services(id, heading, items, intro='', note=''):
    return blk(id, 'ServicesPrices', heading=heading, intro=intro, note=note,
               items=[{'name': n, 'description': d, 'duration': t, 'price': 0, 'from': False} for n, d, t in items])


def features(id, heading, items, intro=''):
    return blk(id, 'FeatureGrid', heading=heading, intro=intro, items=[{'icon': i, 'title': t, 'text': x} for i, t, x in items])


def steps(id, heading, items, intro=''):
    return blk(id, 'Steps', heading=heading, intro=intro, items=[{'title': t, 'text': x} for t, x in items])


def stats(id, heading, items, note=''):
    return blk(id, 'Stats', heading=heading, note=note, items=[{'value': v, 'label': l} for v, l in items])


def packages(id, heading, items, intro=''):
    return blk(id, 'Packages', heading=heading, intro=intro, items=[
        {'name': n, 'price': 0, 'from': False, 'inclusions': inc, 'validUntil': '', 'highlight': hi, 'buttonLabel': b, 'buttonHref': h}
        for n, inc, hi, b, h in items])


def faq(id, heading, items):
    return blk(id, 'Faq', heading=heading, items=[{'question': q, 'answer': a} for q, a in items])


def gallery(id, heading, images, intro=''):
    return blk(id, 'Gallery', heading=heading, intro=intro, images=list(images))


def image_text(id, heading, text, image, side='right', button=('', '')):
    return blk(id, 'ImageText', heading=heading, text=text, image=image['src'], imageAlt=image['alt'], imageSide=side,
               buttonLabel=button[0], buttonHref=button[1])


def cta(id, heading, text, label, href):
    return blk(id, 'CallToAction', heading=heading, text=text, buttonLabel=label, buttonHref=href)


def reviews(heading='What customers say'):
    # Never invented reviews: empty until the owner adds real ones (hidden on the live site while empty).
    return blk('reviews', 'Testimonials', heading=heading, items=[])


def team(heading, intro=''):
    return blk('team', 'Team', heading=heading, intro=intro, people=[])


def contact(heading, intro):
    return blk('contact', 'Contact', heading=heading, intro=intro, address='', phone='', whatsapp='', email='')


def hours(note='Please confirm public holiday hours with us.'):
    return blk('hours', 'OpeningHours', heading='Opening hours', note=note)


def areas(heading, intro='', note=''):
    return blk('areas', 'ServiceAreas', heading=heading, intro=intro, areas=[], note=note, mapQuery='')


def directions(heading='Find us', landmark=''):
    return blk('directions', 'Directions', heading=heading, address='', landmark=landmark, showMap=True)


def quote(heading, intro, service_names, questions=(), details='Tell us more', submit='Send my request'):
    return blk('quote', 'QuoteForm', heading=heading, intro=intro, services=[{'name': n} for n in service_names],
               detailsLabel=details, submitLabel=submit, leads='yes', askNews='yes',
               questions=[{'label': l, 'type': t, 'choices': c, 'required': r} for l, t, c, r in questions])


def book(heading='Book online', intro='Choose a service and a time that suits you.', mode='appointments'):
    return blk('book-online', 'BookOnline', mode=mode, heading=heading, intro=intro, fallbackText='Call or message us to book.',
               fallbackLabel='Contact us', fallbackHref='/contact')


def menu(id, heading, sections, intro='', note=''):
    return blk(id, 'FoodMenu', heading=heading, intro=intro, note=note, sections=[
        {'name': n, 'dishes': [{'name': d, 'description': x, 'price': 0, 'tags': []} for d, x in dishes]} for n, dishes in sections])


def before_after(heading, intro='', layout='slider'):
    return blk('ba', 'BeforeAfter', heading=heading, intro=intro, layout=layout, items=[])


def text(id, heading, body):
    return blk(id, 'Text', heading=heading, text=body)


def logos(id, heading):
    return blk(id, 'Logos', heading=heading, items=[])


def video(heading, body, description):
    return blk('video', 'Video', heading=heading, text=body, video={'src': '', 'poster': ''}, description=description)


def accreditations(heading, items, intro=''):
    return blk('accreditations', 'Accreditations', heading=heading, intro=intro, items=[{'name': n, 'logo': '', 'detail': d} for n, d in items])


def write_slots():
    path = f'{REPO}/modules/Website/templates/photos/slots.json'
    data = json.load(open(path))
    for key, slots in SLOTS.items():
        data[key] = {s: {k: v for k, v in d.items() if k != 'icon'} for s, d in slots.items()}
    with open(path, 'w') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
        f.write('\n')
    standins({k: {s: d['icon'] for s, d in v.items()} for k, v in SLOTS.items()})


# ── Stand-ins: crisp, tonal placeholders with a line icon (never blurred), until HQ adds real photos ──
ICONS=os.path.join(REPO, 'node_modules/lucide-react/dist/esm/icons/')
def hexrgb(h): h=h.lstrip('#'); return tuple(int(h[i:i+2],16)/255 for i in (0,2,4))
def rgbhex(c): return '#%02x%02x%02x'%tuple(max(0,min(255,round(x*255))) for x in c)
def shade(h,l,s=None):
    r,g,b=hexrgb(h); hh,ll,ss=colorsys.rgb_to_hls(r,g,b)
    return rgbhex(colorsys.hls_to_rgb(hh,l,ss if s is None else min(ss,s)))
def icon(name):
    src=open(ICONS+name+'.mjs').read()
    out=[]
    for tag,attrs in re.findall(r'\["(\w+)", \{([^}]*)\}\]',src):
        a=dict(re.findall(r'(\w+): "([^"]*)"',attrs)); a.pop('key',None)
        out.append('<%s %s/>'%(tag,' '.join(f'{k}="{v}"' for k,v in a.items())))
    return ''.join(out)
def art(primary, name, w=1600, h=1000, variant=0):
    bg1=shade(primary,0.93,0.30); bg2=shade(primary,0.86,0.35); ink=shade(primary,0.30,0.55); line=shade(primary,0.70,0.4)
    s=h*0.42
    s=h*0.30; x=w/2-s/2; y=h/2-s/2
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{bg1}"/><stop offset="1" stop-color="{bg2}"/></linearGradient>
<pattern id="p" width="22" height="22" patternUnits="userSpaceOnUse" patternTransform="rotate(35)"><line x1="0" y1="0" x2="0" y2="22" stroke="{line}" stroke-opacity="0.22" stroke-width="1.5"/></pattern></defs>
<rect width="{w}" height="{h}" fill="url(#g)"/>
<rect x="{w*0.06}" y="{h*0.1}" width="{w*0.88}" height="{h*0.8}" fill="url(#p)"/>
<circle cx="{x+s/2}" cy="{y+s/2}" r="{s*0.82}" fill="{bg1}"/>
<circle cx="{x+s/2}" cy="{y+s/2}" r="{s*0.82}" fill="none" stroke="{ink}" stroke-opacity="0.25" stroke-width="2"/>
<g transform="translate({x},{y}) scale({s/24})" fill="none" stroke="{ink}" stroke-width="0.9" stroke-linecap="round" stroke-linejoin="round">{icon(name)}</g>
</svg>'''


def standins(icons):
    from PIL import Image
    for key, slots in icons.items():
        primary = json.load(open(f'{REPO}/modules/Website/templates/{key}.json'))['definition']['branding']['primary']
        out = f'{REPO}/public/images/templates/{key}'
        os.makedirs(out, exist_ok=True)
        for slot, icon_name in sorted(slots.items()):
            with tempfile.TemporaryDirectory() as tmp:
                with open(f'{tmp}/a.svg', 'w') as f:
                    f.write(art(primary, icon_name))
                subprocess.run(['rsvg-convert', f'{tmp}/a.svg', '-o', f'{tmp}/a.png'], check=True)
                img = Image.open(f'{tmp}/a.png').convert('RGB')
                img.save(f'{out}/{slot}-1600.webp', 'WEBP', quality=80, method=6)
                img.resize((640, 400), Image.LANCZOS).save(f'{out}/{slot}-640.webp', 'WEBP', quality=80, method=6)
