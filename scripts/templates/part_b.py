"""Sprint 20.4b: spaza & grocery, beauty supplies, tailoring & fashion, agriculture — three designs each."""
from lib import *


def products(id, heading, intro='', category='', limit='up-to-6'):
    return blk(id, 'ProductGrid', heading=heading, intro=intro, category=category, limit=limit)


def banner(text, label, href, tone='brand'):
    return blk('promo', 'PromotionBanner', text=text, linkLabel=label, linkHref=href, tone=tone, starts='', ends='')


# ───────────────────────── Spaza & grocery ─────────────────────────

t = T('spaza-grocery', 'spaza-corner-shop', 'Corner shop', 1,
      'Friendly and local: what’s in stock, opening hours and airtime at the counter.', '#B91C1C', '#FACC15', 'friendly', 'round')
t.button = ('WhatsApp an order', '/order')
shop = t.img('hero', 'The shop counter with shelves behind', 'A friendly shopkeeper at the counter of a local shop', 'spaza shop owner counter', 'store')
shelves = t.img('shelves', 'Shelves stocked with groceries', 'Neat, well-stocked shelves of everyday groceries', 'grocery shelves stocked', 'shopping-basket')
t.page('home', 'Home', [
    banner('Bread and milk delivered fresh every morning', 'Order on WhatsApp', '/order'),
    hero('split', 'Everything you need, around the corner', 'Bread, milk, airtime, electricity and the groceries you run out of — open early, open late.',
         ('WhatsApp an order', '/order'), ('What we stock', '/stock'), [shop], ['Open 06:00–21:00', 'Airtime and electricity']),
    features('services', 'At the counter', [
        ('clock', 'Open early and late', 'Before work and after — every day.'),
        ('star', 'Airtime, data and electricity', 'All networks and prepaid electricity.'),
        ('heart', 'Order on WhatsApp', 'Send your list; collect it packed, or ask about delivery nearby.')]),
    products('products', 'Specials this week', limit='up-to-6'),
    image_text('stock', 'Stocked for the neighbourhood', 'Tell people what you always have: fresh bread, cold drinks, maize meal, paraffin, household basics — and anything special you bring in.', shelves, 'left'),
    hours('Open every day, including public holidays.'),
    directions('Find the shop', 'Tell people what the shop is next to — a school, a church or a taxi stop.'),
], seo_title='Corner shop — groceries, airtime and electricity')
t.page('stock', 'What we stock', [
    services('stock', 'Always in stock', [
        ('Bread & milk', 'Delivered fresh every morning', ''), ('Maize meal, rice & flour', 'All the main sizes', ''),
        ('Cold drinks & snacks', 'From the fridge', ''), ('Household', 'Soap, paraffin, candles, matches', ''),
        ('Airtime & data', 'All networks', ''), ('Prepaid electricity', 'Bring your meter number', '')],
        note='Prices change with our suppliers; ask in the shop.'),
    products('products', 'Specials', limit='up-to-9'),
])
t.page('order', 'Order', [
    quote('Send your order', 'Type your list; we’ll confirm the total and when it’s ready.', ['Collect', 'Deliver nearby'],
          [('Your list', 'long', '', 'yes')], details='Anything else?', submit='Send my order'),
    contact('Contact the shop', 'WhatsApp is quickest.')])
t.write()

t = T('spaza-grocery', 'grocery-fresh-market', 'Fresh produce market', 2,
      'Fresh and colourful: fruit, vegetables and the day’s specials, with order-ahead boxes.', '#15803D', '#F97316', 'editorial', 'soft')
t.button = ('Order a box', '/boxes')
p1 = t.img('produce', 'Crates of fresh vegetables', 'Crates of fresh, colourful fruit and vegetables', 'fresh vegetables market Africa', 'carrot')
p2 = t.img('stall', 'The market stall with customers', 'The stall or shop with customers choosing produce', 'fruit vegetable market stall', 'store')
p3 = t.img('box', 'A box of mixed vegetables', 'A packed box of mixed fruit and vegetables', 'vegetable box', 'package')
t.page('home', 'Home', [
    hero('collage', 'Fresh from the farm this morning', 'Fruit and vegetables bought fresh every day — choose in store, or order a box for the week.',
         ('Order a box', '/boxes'), ('Today’s specials', '/specials'), [p1, p2, p3]),
    steps('how', 'Weekly boxes, simply', [
        ('Choose a box', 'Small, family or the big one.'), ('Order by Thursday', 'On WhatsApp or here.'),
        ('Collect on Saturday', 'Or we deliver nearby.')]),
    packages('boxes', 'Weekly boxes', [
        ('Small box', 'For one or two: seasonal fruit and veg', False, 'Order', '/boxes'),
        ('Family box', 'For four: the week’s fruit, veg and potatoes', True, 'Order', '/boxes'),
        ('Big box', 'Bigger families and spaza owners', False, 'Order', '/boxes')]),
    products('products', 'Today’s specials'),
    features('why', 'Why shop with us', [
        ('heart', 'Fresh every day', 'We buy at the market each morning.'),
        ('users', 'Local farmers', 'We buy from small farmers where we can.'),
        ('check', 'Fair prices', 'Prices on every crate.')]),
    hours('Market hours change in winter; check here first.'),
    directions('Find the market'),
], seo_title='Fresh produce — fruit and vegetables, weekly boxes')
t.page('specials', 'Specials', [text('intro', 'This week’s specials', 'Fresh deals every week — come in early for the best choice.'), products('products', 'Specials', 'Prices for this week; while stocks last.', limit='up-to-12')])
t.page('boxes', 'Order a box', [
    quote('Order a box', 'Choose a box and when to collect.', ['Small box', 'Family box', 'Big box'],
          [('Collect or deliver?', 'choice', 'Collect, Deliver nearby', 'yes'), ('Date', 'date', '', 'yes')], submit='Order my box'),
    contact('Contact us', 'WhatsApp us your order any time.')])
t.write()

t = T('spaza-grocery', 'grocery-wholesale', 'Wholesale & bulk', 3,
      'Practical and price-led: bulk buying for spaza owners, schools, churches and events.', '#1E40AF', '#F59E0B', 'technical', 'square')
t.button = ('Get a price list', '/order')
w1 = t.img('hero', 'Pallets of bulk groceries in a warehouse', 'A warehouse floor with pallets of bulk groceries', 'warehouse groceries pallets', 'warehouse')
w2 = t.img('loading', 'Stock being loaded onto a bakkie', 'Bulk stock being loaded onto a bakkie or truck', 'loading truck goods', 'truck')
t.page('home', 'Home', [
    hero('photo', 'Buy in bulk. Save every month.', 'Maize meal, rice, oil, sugar, cold drinks and cleaning — at wholesale prices for shops, schools and events.',
         ('Get a price list', '/order'), ('Delivery', '/delivery'), [w1], ['Cash, card or EFT', 'Delivery on orders over a minimum']),
    stats('numbers', 'Buying with us', [('6 days', 'a week'), ('Same day', 'collection'), ('48 h', 'delivery in our area')], note='Change these to your own.'),
    services('ranges', 'Ranges', [
        ('Staples', 'Maize meal, rice, flour, sugar, oil', ''), ('Drinks', 'Cold drinks, juice and water by the case', ''),
        ('Cleaning', 'Soap, bleach, washing powder', ''), ('Snacks', 'Chips, sweets and biscuits by the box', '')],
        intro='Prices change weekly with our suppliers — ask for this week’s list.'),
    products('products', 'Bulk specials', limit='up-to-6'),
    logos('brands', 'Brands we stock'),
    cta('cta', 'Stocking a shop or an event?', 'Send your list for a quote, or ask for this week’s price list.', 'Get a price list', '/order'),
], seo_title='Wholesale groceries — bulk buying for shops, schools and events')
t.page('delivery', 'Delivery', [
    image_text('delivery', 'Delivered to your door', 'Explain your delivery days, areas and the minimum order — and that drivers help offload.', w2, 'right'),
    areas('Delivery areas', note='Outside these areas, ask us about a fee.'),
    faq('faq', 'Questions', [
        ('Do you sell to the public?', 'Yes — anyone can buy, but prices are for full cases and bags.'),
        ('Can I open an account?', 'Registered businesses can apply for 30-day accounts.'),
        ('How do I pay?', 'Cash, card or EFT before delivery.')]),
])
t.page('order', 'Order', [
    quote('Get a price list or quote', 'Tell us what you buy each month.', ['Spaza / shop', 'School', 'Church or NGO', 'Event'],
          [('Business or organisation', 'short', '', 'no'), ('What do you need?', 'long', '', 'yes')], submit='Send'),
    contact('Contact the warehouse', 'Call or WhatsApp.'), hours(), directions('Find the warehouse')])
t.write()

# ───────────────────────── Beauty supplies ─────────────────────────

t = T('beauty-supplies', 'beauty-supply-store', 'Beauty supply store', 1,
      'Bright and stocked: hair, braids, wigs and skin care, with what’s new up front.', '#BE185D', '#7C3AED', 'modern', 'round')
t.button = ('Shop now', '/shop')
b1 = t.img('hero', 'Shelves of hair and beauty products', 'Shelves of hair products, braiding hair and wigs in a beauty store', 'beauty supply store hair products', 'shopping-bag')
b2 = t.img('braids', 'Packs of braiding hair in many colours', 'Packs of braiding hair in different colours', 'braiding hair packs', 'sparkles')
b3 = t.img('wigs', 'Wigs on display stands', 'Wigs on display', 'wigs display store', 'sparkles')
t.page('home', 'Home', [
    banner('New stock every Friday — braiding hair, wigs and edge control', 'See what’s new', '/shop'),
    hero('split', 'Hair, wigs and beauty — all in one place', 'Braiding hair, wigs, hair care and skin care at good prices, with advice from people who use them.',
         ('Shop now', '/shop'), ('Visit the store', '/visit'), [b1]),
    products('products', 'New this week', limit='up-to-6'),
    gallery('ranges', 'Shop by range', [b2, b3]),
    features('why', 'Why shop here', [
        ('star', 'Real brands', 'Stock you can trust, straight from distributors.'),
        ('heart', 'Advice that helps', 'Ask us what will work for your hair or skin.'),
        ('users', 'Salon prices', 'Salon owners: ask about bulk prices.')]),
    reviews(),
], seo_title='Beauty supply store — braiding hair, wigs and hair care')
t.page('shop', 'Shop', [text('intro', 'Shop', 'Order online and collect in store, or ask about delivery.'), products('products', 'All products', limit='up-to-12')])
t.page('visit', 'Visit', [hours(), directions('Find the store', 'Tell people which centre or street you’re in.'), contact('Contact us', 'Ask if we have it before you come in.')])
t.write()

t = T('beauty-supplies', 'beauty-skincare-brand', 'Skin care brand', 2,
      'Calm and premium: a small range of natural skin and hair products, sold online.', '#7C2D12', '#65A30D', 'elegant', 'soft')
t.button = ('Shop', '/shop')
s1 = t.img('hero', 'Skin care bottles on a stone surface', 'The brand’s bottles and jars styled simply', 'natural skincare products bottles', 'leaf')
s2 = t.img('ingredients', 'Shea butter and marula oil', 'Natural ingredients: shea butter, marula or baobab', 'shea butter natural ingredients', 'leaf', decorative=True)
s3 = t.img('founder', 'The founder in her studio', 'The founder making or packing products', 'Black woman entrepreneur skincare', 'user')
t.page('home', 'Home', [
    hero('photo', 'Made for our skin, from what grows here', 'Small-batch skin and hair care with marula, baobab and shea — gentle, honest and made locally.',
         ('Shop the range', '/shop'), ('Our story', '/story'), [s1]),
    products('products', 'Best sellers', limit='up-to-3'),
    image_text('ingredients', 'What goes in', 'List your key ingredients, where they come from and what they do. Say what you leave out.', s2, 'left'),
    steps('routine', 'A simple routine', [('Cleanse', 'Gentle, every morning and night.'), ('Treat', 'Serum or oil where you need it.'), ('Protect', 'Moisturise; sunscreen by day.')]),
    reviews('What customers say'),
    faq('faq', 'Questions', [
        ('Are your products tested on animals?', 'Tell customers how you test your products.'),
        ('How long does delivery take?', 'Say how long delivery takes to main centres and elsewhere.'),
        ('Can I return a product?', 'Explain your returns policy for unopened products.')]),
], seo_title='Natural skin and hair care, made locally')
t.page('shop', 'Shop', [text('intro', 'The range', 'Every product, with what’s in it and how to use it.'), products('products', 'Products', limit='up-to-12')])
t.page('story', 'Our story', [
    image_text('story', 'How it started', 'Tell your story: what you couldn’t find, who you made the first batch for, and how you make every product now.', s3, 'right'),
    accreditations('Certified', [('Add a certification', 'For example, organic or cruelty-free, if you have it')]),
])
t.page('contact', 'Contact', [contact('Contact us', 'Questions about a product or an order? Message us.')])
t.write()

t = T('beauty-supplies', 'beauty-salon-wholesale', 'Salon wholesale', 3,
      'Trade-first: products and equipment for salons, barbers and nail techs, with trade accounts.', '#0F172A', '#EC4899', 'clean', 'soft')
t.button = ('Open a trade account', '/trade')
h1 = t.img('hero', 'Salon products in bulk on shelves', 'Bulk salon products and equipment on warehouse shelves', 'salon products wholesale', 'warehouse')
h2 = t.img('equipment', 'A salon chair and hood dryer', 'Salon equipment: chairs, dryers, trolleys', 'salon equipment chair dryer', 'armchair')
t.page('home', 'Home', [
    hero('split', 'Everything your salon runs on', 'Professional hair, nail and barber products and equipment — trade prices, delivered to your salon.',
         ('Open a trade account', '/trade'), ('Ranges', '/ranges'), [h1], ['Trade prices for salons', 'Delivery nationwide']),
    services('ranges', 'Ranges', [
        ('Hair care & colour', 'Professional sizes', ''), ('Braiding hair & extensions', 'By the box', ''),
        ('Nails', 'Gels, acrylics, tools', ''), ('Barber', 'Clippers, blades, aftershave', ''),
        ('Equipment', 'Chairs, dryers, basins', ''), ('Hygiene', 'Capes, towels, disinfectant', '')]),
    products('products', 'Trade specials', limit='up-to-6'),
    image_text('equipment', 'Setting up a salon?', 'We help new salons choose equipment and stock for their first month — ask for a starter quote.', h2, 'left', ('Ask for a quote', '/trade')),
    logos('brands', 'Brands we distribute'),
], seo_title='Salon wholesale — hair, nail and barber supplies')
t.page('ranges', 'Ranges', [text('intro', 'Ranges', 'Trade prices for registered salons, barbers and nail techs.'), products('products', 'All products', limit='up-to-12')])
t.page('trade', 'Trade account', [
    quote('Open a trade account', 'For salons, barbers and nail techs.', ['Hair salon', 'Barbershop', 'Nail bar', 'Training academy'],
          [('Business name', 'short', '', 'yes'), ('Registration or ID number', 'short', '', 'no'), ('What do you buy most?', 'long', '', 'no')],
          submit='Apply'),
    contact('Contact sales', 'Call or WhatsApp our sales team.')])
t.write()

# ───────────────────────── Tailoring & fashion ─────────────────────────

t = T('tailoring-fashion', 'tailor-made-to-measure', 'Made-to-measure tailor', 1,
      'Crafted and personal: traditional wear, suits and alterations, made for you.', '#312E81', '#D97706', 'classic', 'square')
t.button = ('Book a fitting', '/fitting')
f1 = t.img('hero', 'A tailor measuring a client', 'A tailor taking a client’s measurements', 'African tailor measuring client', 'ruler')
f2 = t.img('fabric', 'Rolls of colourful African print fabric', 'Rolls of colourful print fabric (shweshwe, ankara, kente)', 'African print fabric rolls', 'scissors', decorative=True)
f3 = t.img('look-1', 'A made-to-measure traditional outfit', 'A finished traditional outfit on a client', 'African traditional attire', 'shirt')
f4 = t.img('look-2', 'A tailored suit', 'A finished tailored suit', 'tailored suit Black man', 'shirt')
t.page('home', 'Home', [
    hero('split', 'Made for you, to the centimetre', 'Traditional wear, wedding outfits, suits and school uniforms — measured, cut and sewn by hand.',
         ('Book a fitting', '/fitting'), ('Our work', '/work'), [f1]),
    steps('how', 'From fabric to fitting', [
        ('Measure', 'We take your measurements and talk about the look.'), ('Choose fabric', 'Bring your own or choose from ours.'),
        ('First fitting', 'We adjust until it fits perfectly.'), ('Collect', 'Pressed and ready for your day.')]),
    gallery('work', 'Recent work', [f3, f4]),
    image_text('fabric', 'Fabric for every occasion', 'Shweshwe, ankara, kente, lace and suiting — tell people what you keep in stock.', f2, 'left'),
    services('services', 'What we make', [
        ('Traditional wear', 'For weddings, umembeso, lobola and church', '2–3 weeks'), ('Suits', 'Two- or three-piece', '3 weeks'),
        ('Dresses', 'Evening, matric dance and bridesmaids', '2–3 weeks'), ('Alterations', 'Hems, taking in, zips', '2–5 days')]),
    reviews(),
], seo_title='Tailor — traditional wear, suits and alterations')
t.page('work', 'Our work', [gallery('work', 'Our work', [f3, f4], 'Every piece made to measure.'), before_after('Alterations', 'Before and after.')])
t.page('fitting', 'Book a fitting', [
    book('Book a fitting', 'Measurements take about 30 minutes.'),
    quote('Planning a wedding or group order?', 'Tell us the date and how many outfits.', ['Wedding party', 'Church group', 'School uniforms', 'Corporate'],
          [('Date needed', 'date', '', 'yes'), ('Number of outfits', 'short', '', 'yes')], submit='Ask for a quote'),
    contact('Visit the studio', 'Bring a photo of the look you love.'), directions('Find the studio')])
t.write()

t = T('tailoring-fashion', 'fashion-label', 'Fashion label', 2,
      'Editorial and bold: a designer’s collections, lookbook and online shop.', '#18181B', '#E11D48', 'editorial', 'square')
t.button = ('Shop the collection', '/shop')
l1 = t.img('hero', 'A model wearing the new collection', 'A model in the label’s latest collection, editorial shoot', 'African fashion model editorial', 'shirt')
l2 = t.img('look-1', 'Lookbook image, look one', 'Lookbook: a full outfit from the collection', 'African fashion lookbook', 'shirt')
l3 = t.img('look-2', 'Lookbook image, look two', 'Lookbook: a second outfit', 'African designer clothing', 'shirt')
l4 = t.img('look-3', 'Lookbook image, look three', 'Lookbook: a detail — print, beadwork or tailoring', 'fashion detail fabric', 'sparkles')
l5 = t.img('studio', 'The designer at work in the studio', 'The designer at work in the studio', 'fashion designer studio Africa', 'pen-tool')
t.page('home', 'Home', [
    hero('slideshow', 'The new collection', 'Contemporary African design, made in small runs. Tell people what this season is about.',
         ('Shop the collection', '/shop'), ('Lookbook', '/lookbook'), [l1, l2, l3]),
    products('products', 'Shop the collection', limit='up-to-6'),
    image_text('designer', 'The designer', 'Introduce yourself: your training, your influences and how each piece is made.', l5, 'right', ('Our story', '/lookbook')),
    video('Behind the collection', 'A short film from the shoot or the studio.', 'Behind the scenes of the collection shoot'),
    logos('press', 'As seen in'),
], seo_title='Fashion label — contemporary African design')
t.page('lookbook', 'Lookbook', [gallery('lookbook', 'Lookbook', [l1, l2, l3, l4], 'Photographed for this season’s collection.')])
t.page('shop', 'Shop', [text('intro', 'Shop', 'Pieces from the current collection, made in small runs.'), products('products', 'The collection', limit='up-to-12'),
    faq('faq', 'Orders', [('How long does delivery take?', 'Say how long delivery takes.'), ('Can I exchange a size?', 'Explain your exchange policy.')])])
t.page('contact', 'Contact', [contact('Contact the studio', 'Stockists, press and custom orders.')])
t.write()

t = T('tailoring-fashion', 'alterations-repairs', 'Alterations & repairs', 3,
      'Quick and practical: hems, zips, school uniforms and repairs, often same week.', '#0E7490', '#F59E0B', 'friendly', 'round')
t.button = ('Get a price', '/price')
a1 = t.img('hero', 'A sewing machine stitching a hem', 'A sewing machine stitching a hem, close up', 'sewing machine hem', 'scissors')
a2 = t.img('counter', 'Clothes on hangers ready for collection', 'Altered clothes on hangers, tagged for collection', 'clothes on hangers tailor shop', 'shirt')
t.page('home', 'Home', [
    hero('centered', 'Fixed, fitted, done this week', 'Hems, zips, taking in, school uniforms and repairs — bring it in, collect it fitted.',
         ('Get a price', '/price'), ('Prices', '/prices')),
    services('services', 'Popular jobs', [
        ('Hem trousers', 'Normal or invisible hem', '2 days'), ('Replace a zip', 'Trousers, skirts, jackets', '2 days'),
        ('Take in / let out', 'Waist and seams', '3–5 days'), ('School uniforms', 'Hems and name tags', '2 days'),
        ('Curtains', 'Hem and hooks', '1 week')]),
    image_text('ready', 'Ready when we say', 'We tag every item with your name and collection day, and send a message when it’s ready.', a2, 'right'),
    features('why', 'Why bring it to us', [
        ('clock', 'Quick', 'Most jobs in two to five days.'), ('check', 'Price first', 'We tell you the price before we start.'),
        ('heart', 'Careful', 'Your clothes are treated like ours.')]),
    hours(), directions('Find us'),
], seo_title='Clothing alterations and repairs')
t.page('prices', 'Prices', [services('services', 'Prices', [
    ('Hem trousers', '', '2 days'), ('Hem dress or skirt', '', '2–3 days'), ('Replace a zip', '', '2 days'),
    ('Take in / let out', '', '3–5 days'), ('Patch or repair', '', '2 days'), ('Curtains', 'Per pair', '1 week')],
    note='We confirm the price when we see the item.')])
t.page('price', 'Get a price', [
    quote('Get a price', 'Send a photo and tell us what you need done.', ['Hem', 'Zip', 'Take in / let out', 'Repair', 'Uniforms', 'Curtains'],
          [('Photo (optional)', 'file', '', 'no')], submit='Get my price'),
    contact('Contact us', 'Or just bring it in.')])
t.write()

# ───────────────────────── Agriculture ─────────────────────────

t = T('agriculture', 'farm-produce', 'Family farm', 1,
      'Earthy and honest: a farm selling produce, eggs and meat to locals and shops.', '#3F6212', '#B45309', 'editorial', 'soft')
t.button = ('Order from the farm', '/order')
g1 = t.img('hero', 'Rows of crops on the farm at sunrise', 'Farmland at golden hour: rows of crops', 'African farm field sunrise', 'sprout')
g2 = t.img('farmer', 'The farmer in the field', 'The farmer (or family) in the field', 'African farmer field', 'user')
g3 = t.img('produce', 'Fresh eggs and vegetables', 'Fresh eggs, vegetables or a harvest basket', 'fresh eggs vegetables basket', 'egg')
t.page('home', 'Home', [
    hero('photo', 'From our farm to your table', 'Vegetables, free-range eggs and meat — grown by our family and sold fresh to homes, shops and restaurants.',
         ('Order from the farm', '/order'), ('What we grow', '/produce'), [g1]),
    image_text('story', 'Our farm', 'Tell people where the farm is, how long your family has farmed it, and how you grow and raise what you sell.', g2, 'right'),
    services('produce', 'In season', [
        ('Vegetables', 'Spinach, cabbage, tomatoes, onions', ''), ('Free-range eggs', 'Per tray', ''),
        ('Chickens', 'Live or slaughtered', ''), ('Maize', 'Green mealies in season', '')],
        intro='What we have depends on the season — ask what’s ready this week.'),
    gallery('farm', 'On the farm', [g1, g2, g3]),
    features('buyers', 'Who we sell to', [
        ('users', 'Families', 'Collect at the farm gate or order a weekly box.'),
        ('star', 'Shops and spazas', 'Regular supply at trade prices.'),
        ('heart', 'Restaurants', 'Fresh produce delivered twice a week.')]),
    directions('Visit the farm', 'Give directions from the nearest town — farms are hard to find.'),
], seo_title='Family farm — vegetables, eggs and meat')
t.page('produce', 'What we grow', [
    services('produce', 'Produce and prices', [
        ('Spinach', 'Per bunch', ''), ('Cabbage', 'Each', ''), ('Tomatoes', 'Per box', ''), ('Eggs', 'Per tray of 30', ''),
        ('Chicken', 'Per bird', '')], note='Bulk prices for shops and restaurants.'),
    gallery('farm', 'Fresh this week', [g3]),
])
t.page('order', 'Order', [
    quote('Order from the farm', 'Tell us what you need; we confirm availability and price.', ['Home order', 'Shop / spaza', 'Restaurant', 'Event'],
          [('What do you need?', 'long', '', 'yes'), ('Collect or deliver?', 'choice', 'Collect at the farm, Deliver', 'yes')], submit='Send my order'),
    contact('Contact the farm', 'Call or WhatsApp — signal can be weak, so leave a message.')])
t.write()

t = T('agriculture', 'agri-supplies', 'Agri supplies & feed', 2,
      'Dependable and practical: seed, feed, fertiliser and advice for farmers.', '#166534', '#CA8A04', 'technical', 'square')
t.button = ('Ask for a quote', '/quote')
a1 = t.img('hero', 'Bags of feed and fertiliser in a store', 'Bags of animal feed, seed and fertiliser stacked in a store', 'agricultural supply store feed bags', 'warehouse')
a2 = t.img('advice', 'A farmer talking with an adviser', 'A farmer talking with an adviser in a field or at the counter', 'farmer advisor talking', 'users')
t.page('home', 'Home', [
    hero('split', 'What your farm needs, when it needs it', 'Seed, feed, fertiliser, animal health and tools — with advice from people who farm.',
         ('Ask for a quote', '/quote'), ('Ranges', '/ranges'), [a1], ['Delivery to farms', 'Advice at the counter']),
    services('ranges', 'Ranges', [
        ('Seed', 'Maize, vegetables, pasture', ''), ('Animal feed', 'Poultry, pigs, cattle', ''),
        ('Fertiliser', 'Bags and bulk', ''), ('Animal health', 'Dips, vaccines, dosing', ''),
        ('Tools & irrigation', 'Pipes, drip, sprayers', '')]),
    image_text('advice', 'Advice that helps you grow', 'Explain who gives advice, what they know, and the days your adviser visits farms.', a2, 'left'),
    products('products', 'Specials', limit='up-to-6'),
    stats('numbers', 'Farmers we serve', [('6 days', 'a week, open early'), ('Farm', 'delivery'), ('Free', 'advice at the counter')], note='Change these to your own.'),
    logos('brands', 'Brands we stock'),
], seo_title='Agricultural supplies — seed, feed and fertiliser')
t.page('ranges', 'Ranges', [text('intro', 'Ranges', 'Seed, feed, fertiliser, animal health and tools — ask us if you don’t see it.'), products('products', 'Products', limit='up-to-12'),
    faq('faq', 'Questions', [('Do you deliver to farms?', 'Say where you deliver and the minimum order.'),
                             ('Do you offer accounts?', 'Explain trade accounts for farmers and co-ops.')])])
t.page('quote', 'Quote', [
    quote('Ask for a quote', 'Tell us what you need and where the farm is.', ['Seed', 'Feed', 'Fertiliser', 'Animal health', 'Tools & irrigation'],
          [('Farm / area', 'short', '', 'yes'), ('Quantities', 'long', '', 'yes')], submit='Get my quote'),
    hours(), directions('Find the store'), contact('Contact us', 'Call the counter.')])
t.write()

t = T('agriculture', 'agri-cooperative', 'Co-operative & agri project', 3,
      'Community-minded: a co-op or project showing its members, impact and how to buy or join.', '#854D0E', '#16A34A', 'clean', 'round')
t.button = ('Buy from us', '/buy')
c1 = t.img('hero', 'Co-op members harvesting together', 'Co-op members working together in a field or packhouse', 'farming cooperative women Africa', 'users')
c2 = t.img('packhouse', 'Produce being sorted and packed', 'Produce being sorted and packed for buyers', 'packhouse sorting vegetables', 'package')
c3 = t.img('training', 'Members in a training session', 'Members in a training session outdoors', 'farmers training group Africa', 'graduation-cap')
t.page('home', 'Home', [
    hero('split', 'Growing together', 'A farming co-operative of members who grow, pack and sell together — for fair prices and stronger communities.',
         ('Buy from us', '/buy'), ('About the co-op', '/about'), [c1]),
    features('values', 'What we stand for', [
        ('users', 'Owned by members', 'Every member has a say and a share.'),
        ('heart', 'Fair prices', 'Farmers are paid fairly for what they grow.'),
        ('sparkles', 'Skills that last', 'Training in farming, packing and running a business.')]),
    steps('how', 'How it works', [
        ('Members grow', 'Each member farms their plot with shared inputs.'), ('We pack together', 'Quality checked and packed at the packhouse.'),
        ('We sell together', 'To shops, markets and schools — and share the income.')]),
    image_text('packhouse', 'From the field to the buyer', 'Describe your packhouse, quality checks and how buyers can order.', c2, 'left', ('Buy from us', '/buy')),
    logos('partners', 'Our partners and funders'),
], seo_title='Farming co-operative — fresh produce, grown together')
t.page('about', 'About the co-op', [
    text('story', 'Our story', 'How the co-op started, who the members are, and what you want to achieve together.'),
    team('Our board and members'),
    image_text('training', 'Training and support', 'Tell people about training, mentors and the support members receive.', c3, 'right'),
])
t.page('buy', 'Buy from us', [
    quote('Buy from the co-op', 'Shops, schools and markets: tell us what you need.', ['Vegetables', 'Fruit', 'Eggs', 'Other'],
          [('Organisation', 'short', '', 'no'), ('What and how much?', 'long', '', 'yes'), ('How often?', 'choice', 'Once, Weekly, Monthly', 'no')],
          submit='Send'),
    contact('Contact the co-op', 'Buyers, partners and new members welcome.')])
t.write()

write_slots()
print('ok')
