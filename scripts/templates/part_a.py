"""Sprint 20.4a: barbershop, cleaning, car wash, catering — three designs each, each with its own structure."""
from lib import *

# ───────────────────────── Barbershop ─────────────────────────

t = T('barbershop', 'barbershop-classic', 'Classic barbershop', 1,
      'Timeless and warm: a proper chair, a hot towel and a sharp line-up.', '#7C2D12', '#CA8A04', 'classic', 'square')
t.button = ('Book a cut', '/book')
hero_img = t.img('hero', 'A barber giving a client a clean fade', 'A barber finishing a sharp fade on a client in the chair', 'Black barber fade haircut', 'scissors')
chair = t.img('chair', 'The barber’s chair and mirror', 'The shop: chairs, mirrors and warm light, ready for clients', 'barbershop interior chairs', 'armchair')
cut1 = t.img('cut-1', 'A skin fade with a crisp line-up', 'A finished skin fade with a crisp line-up', 'skin fade haircut', 'scissors')
cut2 = t.img('cut-2', 'A beard trimmed and shaped', 'A beard trim and shape, close up', 'beard trim barber', 'scissors')
cut3 = t.img('cut-3', 'Twists on short natural hair', 'Short twists or a sponge style on natural hair', 'twists short natural hair man', 'scissors')
t.page('home', 'Home', [
    hero('split', 'Sharp cuts. Proper shaves. No rush.', 'Fades, line-ups, beard work and hot-towel shaves — done properly, by barbers who know your style.',
         ('Book a cut', '/book'), ('Prices', '/prices'), [hero_img], ['Walk-ins welcome', 'Open Saturdays']),
    services('services', 'The menu', [
        ('Fade', 'Skin, low, mid or high — finished with a line-up', '45 min'),
        ('Chiskop', 'Clean shave with a razor finish', '30 min'),
        ('Beard trim & shape', 'Shaped, lined and oiled', '20 min'),
        ('Hot-towel shave', 'Lather, straight razor and a cold towel to finish', '40 min'),
        ('Kids’ cut', 'Under 12s, patient barbers', '30 min')],
        intro='Prices on the board in the shop. Ask about student days.'),
    image_text('story', 'A barbershop, the way it should be', 'Tell people when you opened, who taught you, and why your regulars keep coming back. Mention the music, the conversation and the coffee.', chair, 'left'),
    gallery('cuts', 'Fresh from the chair', [cut1, cut2, cut3]),
    reviews('What the regulars say'),
    cta('cta', 'Your chair is waiting', 'Book a time, or walk in — we’ll fit you in.', 'Book a cut', '/book'),
], seo_title='Classic barbershop — fades, shaves and beard work')
t.page('prices', 'Prices', [
    services('services', 'Cuts and shaves', [
        ('Fade', 'Any fade, with a line-up', '45 min'), ('Taper', 'Natural finish at the neck', '40 min'),
        ('Chiskop', 'Razor finish', '30 min'), ('Line-up only', 'Edges and beard line', '15 min'),
        ('Beard trim & shape', 'Shaped, lined and oiled', '20 min'), ('Hot-towel shave', 'Straight razor', '40 min'),
        ('Dye', 'Black or colour, cut not included', '30 min'), ('Kids’ cut', 'Under 12s', '30 min')],
        note='Pensioners and students: ask about weekday specials.'),
    packages('packages', 'Regulars', [
        ('Fresh every fortnight', 'Two cuts a month, booked ahead, beard trim included', True, 'Ask us', '/book'),
        ('Groom & groomsmen', 'Cuts and shaves for the wedding party, at the shop or the venue', False, 'Ask us', '/book')]),
])
t.page('team', 'The barbers', [
    team('The barbers', 'Each barber, their speciality and the days they work.'),
    hours('Late nights on Fridays. Closed on public holidays unless we say so.'),
])
t.page('book', 'Book', [
    book(), contact('Visit the shop', 'Walk in, or book a chair so you don’t wait.'), directions('Find the shop', 'Tell people what the shop is next to.')], menu=True)
t.write()

t = T('barbershop', 'barbershop-studio', 'Street-style studio', 2,
      'Loud, fresh and late-open: designs, colour and a culture people come back for.', '#111827', '#22D3EE', 'bold', 'round')
t.button = ('Book now', '/book')
hero_img = t.img('hero', 'A barber cutting a hair design', 'A barber shaping a hair design or part, in the studio’s lights', 'barber hair design', 'scissors')
work1 = t.img('work-1', 'A freestyle hair design', 'A freestyle hair design or part, close up', 'hair design pattern barber', 'sparkles')
work2 = t.img('work-2', 'Platinum-blonde short hair', 'A colour job: platinum, blonde or bright colour on short hair', 'bleached short hair man', 'sparkles')
work3 = t.img('work-3', 'A high-top fade', 'A high-top, flat-top or sponge twists', 'high top fade', 'scissors')
work4 = t.img('work-4', 'Clients waiting in the studio', 'The studio busy with clients and barbers', 'busy barbershop', 'users')
t.page('home', 'Home', [
    hero('photo', 'Your cut. Your story.', 'Designs, colour, fades and line-ups — open late, music loud, chairs ready.',
         ('Book now', '/book'), ('See the work', '/work'), [hero_img], ['Open until 20:00 on Fridays', 'Designs from the first cut']),
    stats('numbers', 'The studio in numbers', [('5', 'chairs'), ('7 days', 'a week'), ('20:00', 'late on Fridays')], note='Change these to your own.'),
    gallery('work', 'The work', [work1, work2, work3, work4]),
    services('services', 'What we do', [
        ('Fade + design', 'Any fade with a freestyle design', '1 hr'), ('Colour', 'Platinum, blonde or bright colour', '1–2 hrs'),
        ('Twists & sponge', 'Two-strand or sponge twists', '45 min'), ('Line-up', 'Edges and beard line', '15 min')]),
    video('Inside the studio', 'Thirty seconds of the studio on a Friday night.', 'The studio on a busy Friday night'),
    logos('brands', 'Products we use'),
    cta('cta', 'Lock in your chair', 'Book ahead on weekends — we fill up fast.', 'Book now', '/book'),
], seo_title='Barber studio — fades, designs and colour')
t.page('work', 'The work', [
    gallery('work', 'Latest cuts', [work1, work2, work3, work4], intro='New work every week on our socials.'),
    before_after('Before & after', 'From overgrown to sharp.'),
])
t.page('prices', 'Prices', [
    services('services', 'Prices', [
        ('Fade', 'Any fade', '45 min'), ('Fade + design', 'Freestyle design', '1 hr'), ('Colour', 'Bleach and tone', '1–2 hrs'),
        ('Twists & sponge', 'Two-strand or sponge', '45 min'), ('Beard', 'Shape and line', '20 min'), ('Kids', 'Under 12s', '30 min')]),
    packages('memberships', 'Studio pass', [
        ('Fresh Friday pass', 'A cut every Friday this month, guaranteed slot', True, 'Join', '/book'),
        ('Crew booking', 'Four or more friends — before a party or a match', False, 'Ask us', '/book')]),
])
t.page('book', 'Book', [book(), contact('Hit us up', 'WhatsApp is quickest.'), hours('Late on Fridays and Saturdays.'), directions()])
t.write()

t = T('barbershop', 'barbershop-mobile', 'Mobile barber', 3,
      'We come to you: homes, offices, events and weddings.', '#065F46', '#F59E0B', 'modern', 'soft')
t.button = ('Book a visit', '/book')
hero_img = t.img('hero', 'A barber cutting a client’s hair at home', 'A barber cutting hair in a client’s home or office', 'barber cutting hair at home', 'scissors')
kit = t.img('kit', 'A barber’s kit laid out neatly', 'A clean, neatly laid-out barber kit (clippers, cape, brushes)', 'barber tools clippers', 'briefcase', decorative=True)
event = t.img('event', 'Groomsmen getting cuts before a wedding', 'Groomsmen or a team getting cuts before an event', 'groomsmen getting ready', 'users')
t.page('home', 'Home', [
    hero('centered', 'A fresh cut, without leaving home', 'Your barber comes to your home, office or venue — with everything needed, and leaves it clean.',
         ('Book a visit', '/book'), ('Where we go', '/areas')),
    steps('how', 'How it works', [
        ('Book a time', 'Choose a day and time, and tell us where.'), ('We arrive set up', 'Clippers, cape, mirror and a clean-up kit.'),
        ('Look sharp', 'Cut, line-up and beard — and we sweep up before we go.')]),
    image_text('kit', 'Everything comes with us', 'Clean, sterilised tools for every client, a cape and a mirror. All we need is a chair and a plug.', kit, 'right'),
    packages('packages', 'Ways to book', [
        ('Home visit', 'One or more cuts at your home', False, 'Book', '/book'),
        ('Office day', 'We set up at your office for the team', True, 'Ask us', '/book'),
        ('Weddings & events', 'The groom, groomsmen and guests, on the day', False, 'Ask us', '/book')]),
    image_text('events', 'For the big day', 'Calm, on time and on site: cuts for the groom, groomsmen and family before the photos.', event, 'left'),
    reviews(),
    faq('faq', 'Good to know', [
        ('Is there a call-out fee?', 'It depends on the distance — we tell you the total when you book.'),
        ('How many people can you do in one visit?', 'As many as you need; we plan the time so nobody rushes.'),
        ('Do you bring everything?', 'Yes. We only need a chair, light and a plug point.')]),
], seo_title='Mobile barber — haircuts at home, at work and at events')
t.page('areas', 'Where we go', [
    areas('Areas we cover', 'We travel to these areas. Further away? Ask us.', note='A travel fee may apply outside our main areas.'),
    services('services', 'Prices', [
        ('Cut at home', 'Any cut and line-up', '45 min'), ('Beard', 'Shape and line', '20 min'), ('Kids', 'Under 12s, at home', '30 min')]),
])
t.page('book', 'Book', [
    book('Book a visit', 'Choose a time; tell us the address when you book.'),
    quote('Groups, offices and events', 'Tell us the date, place and number of people.', ['Office day', 'Wedding', 'Event', 'Family at home'],
          [('Date', 'date', '', 'yes'), ('How many people?', 'short', '', 'yes'), ('Where?', 'short', '', 'yes')], submit='Ask for a quote'),
    contact('Contact us', 'WhatsApp is quickest.')])
t.write()

# ───────────────────────── Cleaning ─────────────────────────

t = T('cleaning', 'cleaning-home', 'Home cleaning', 1,
      'Bright and trustworthy, for regular, deep and move-in or move-out cleans.', '#0369A1', '#10B981', 'friendly', 'round')
t.button = ('Get a quote', '/quote')
hero_img = t.img('hero', 'A cleaner wiping a kitchen counter', 'A friendly cleaner at work in a bright, tidy kitchen', 'African cleaner kitchen cleaning', 'sparkles')
team_img = t.img('team', 'The cleaning team in uniform', 'The cleaning team in uniform, smiling, with their kit', 'cleaning team uniform', 'users')
t.page('home', 'Home', [
    hero('split', 'Come home to a clean house', 'Vetted, trained cleaners for regular cleans, deep cleans and moving day — booked in minutes.',
         ('Get a quote', '/quote'), ('Our cleans', '/services'), [hero_img], ['Vetted and trained', 'We bring the products']),
    services('services', 'Our cleans', [
        ('Regular clean', 'Weekly or every two weeks: floors, bathrooms, kitchen, dusting', '4–6 hrs'),
        ('Deep clean', 'Inside cupboards, ovens, windows and the corners', '1 day'),
        ('Move-in / move-out', 'Empty home, top to bottom, ready for the keys', '1 day'),
        ('Ironing', 'Washed clothes ironed and folded', '2–4 hrs')]),
    steps('how', 'How it works', [
        ('Ask for a quote', 'Tell us the size of your home and the clean you need.'), ('Pick a day', 'We confirm the time and who is coming.'),
        ('Relax', 'Your cleaner arrives with products and equipment.')]),
    features('why', 'Why families trust us', [
        ('shield', 'Vetted cleaners', 'References and background checks for everyone we send.'),
        ('users', 'Same cleaner', 'For regular cleans, the same person each time where possible.'),
        ('check', 'Happy or we return', 'Missed something? Tell us within 24 hours and we come back.')]),
    image_text('team', 'People you’ll be glad to let in', 'Introduce your team: how you train them, how long they’ve been with you, and how you look after them.', team_img, 'left'),
    reviews('What families say'),
    cta('cta', 'Book your first clean', 'Get a price in a few minutes.', 'Get a quote', '/quote'),
], seo_title='Home cleaning — regular, deep and move-out cleans')
t.page('services', 'Our cleans', [
    services('services', 'Cleans and prices', [
        ('Regular clean', 'Up to 3 bedrooms', '4–6 hrs'), ('Regular clean, larger home', '4 bedrooms or more', '6–8 hrs'),
        ('Deep clean', 'Inside cupboards, oven, windows', '1 day'), ('Move-in / move-out', 'Empty home', '1 day'),
        ('Windows', 'Inside and out, ground floor', '2–3 hrs'), ('Ironing', 'Per basket', '2 hrs')],
        note='Prices depend on the size of your home; we confirm before we book.'),
    packages('packages', 'Regular plans', [
        ('Weekly', 'The same day each week, the same cleaner', True, 'Get a quote', '/quote'),
        ('Every two weeks', 'A fresh home twice a month', False, 'Get a quote', '/quote')]),
    faq('faq', 'Questions', [
        ('Do I need to be home?', 'No. Many clients leave a key or a gate code with us.'),
        ('Do you bring products?', 'Yes, and equipment. Tell us if you prefer your own products.'),
        ('Are your cleaners insured?', 'Tell clients here how you cover damage or breakages.')]),
])
t.page('quote', 'Get a quote', [
    quote('Get a quote', 'A few details and we’ll send a price.', ['Regular clean', 'Deep clean', 'Move-in / move-out', 'Ironing', 'Windows'],
          [('Bedrooms', 'choice', '1, 2, 3, 4, 5 or more', 'yes'), ('Bathrooms', 'choice', '1, 2, 3, 4 or more', 'yes'), ('Preferred date', 'date', '', 'no')],
          submit='Get my price'),
    areas('Areas we clean'), contact('Contact us', 'Questions? WhatsApp us.')])
t.write()

t = T('cleaning', 'cleaning-commercial', 'Office & commercial cleaning', 2,
      'Corporate and reliable, for offices, shops, schools and clinics.', '#1E3A8A', '#38BDF8', 'modern', 'soft')
t.button = ('Request a site visit', '/quote')
hero_img = t.img('hero', 'Cleaners working in an office after hours', 'Uniformed cleaners working in a modern office', 'office cleaning staff', 'building')
site_img = t.img('site', 'A clean, empty reception area', 'A spotless reception or open-plan office', 'clean modern office reception', 'building', decorative=False)
t.page('home', 'Home', [
    hero('photo', 'Clean, safe workplaces — every day', 'Daily office cleaning, hygiene services and deep cleans for offices, retail, schools and clinics.',
         ('Request a site visit', '/quote'), ('Services', '/services'), [hero_img], ['Trained, uniformed staff', 'Supervised and audited']),
    stats('numbers', 'How we work', [('24 h', 'to send your proposal'), ('1', 'supervisor for every site'), ('7 days', 'a week, on your schedule')],
          note='Change these to your own numbers.'),
    features('services', 'What we look after', [
        ('sparkles', 'Daily cleaning', 'Desks, floors, kitchens and bathrooms, before you open or after you close.'),
        ('shield', 'Hygiene services', 'Sanitary bins, washroom supplies and deep sanitising.'),
        ('star', 'Deep cleans', 'Carpets, windows, high-level dusting and after-builders cleans — early, late or at weekends.')]),
    logos('clients', 'Clients who trust us'),
    accreditations('Registered and compliant', [
        ('COIDA', 'Workers registered for injury cover'), ('UIF', 'All staff registered'), ('B-BBEE', 'Add your level')]),
    steps('how', 'Starting is simple', [
        ('Site visit', 'We walk the site with you and listen.'), ('Proposal', 'A clear scope, schedule and monthly price.'),
        ('Start', 'A supervisor sets the team up and checks in with you weekly.')]),
    cta('cta', 'Let’s see your site', 'A free site visit, a clear proposal.', 'Request a site visit', '/quote'),
], seo_title='Office and commercial cleaning services')
t.page('services', 'Services', [
    image_text('promise', 'A team you can rely on', 'Explain how you supervise sites, check quality and replace a cleaner who is off sick — so the client never notices.', site_img, 'right'),
    services('services', 'Services', [
        ('Daily office cleaning', 'Monthly contract', ''), ('Retail and shopping centres', 'Monthly contract', ''),
        ('Schools and clinics', 'Hygiene-focused', ''), ('After-builders clean', 'Once-off', ''),
        ('Carpet and upholstery', 'Once-off or scheduled', ''), ('Washroom hygiene', 'Monthly service', '')],
        note='We quote per site after a visit.'),
    faq('faq', 'Questions businesses ask', [
        ('Do you sign contracts?', 'Yes — monthly service agreements with a notice period.'),
        ('Who supplies the products?', 'We do, unless you prefer your own.'),
        ('How do you check quality?', 'Supervisor checklists and a monthly review with you.')]),
])
t.page('quote', 'Request a site visit', [
    quote('Request a site visit', 'Tell us about your premises.', ['Daily cleaning', 'Hygiene services', 'Deep clean', 'After-builders clean'],
          [('Company name', 'short', '', 'yes'), ('Type of premises', 'choice', 'Office, Retail, School, Clinic, Warehouse, Other', 'yes'),
           ('Size (square metres, roughly)', 'short', '', 'no'), ('How often?', 'choice', 'Daily, A few times a week, Weekly, Once-off', 'yes')],
          submit='Request a visit'),
    contact('Contact us', 'Or call our office.')])
t.write()

t = T('cleaning', 'cleaning-specialist', 'Carpet, upholstery & pressure washing', 3,
      'Results-first, for carpet, couch, mattress and pressure-washing specialists.', '#7C3AED', '#22C55E', 'technical', 'soft')
t.button = ('Book a clean', '/quote')
c1 = t.img('carpet', 'A carpet being steam-cleaned', 'A carpet or rug being steam-cleaned, with a clear clean stripe', 'carpet steam cleaning', 'sparkles')
c2 = t.img('couch', 'A couch being cleaned', 'A couch or mattress being deep-cleaned', 'upholstery cleaning sofa', 'armchair')
c3 = t.img('paving', 'Paving being pressure-washed', 'Paving or a driveway being pressure-washed, half done', 'pressure washing driveway', 'droplets')
t.page('home', 'Home', [
    hero('collage', 'Like new again', 'Carpets, couches, mattresses and paving — deep-cleaned, dried fast, and priced before we start.',
         ('Book a clean', '/quote'), ('Prices', '/prices'), [c1, c2, c3]),
    before_after('See the difference', 'Real jobs, same day.'),
    services('services', 'What we clean', [
        ('Carpets', 'Hot-water extraction, fast drying', ''), ('Couches & chairs', 'Fabric and leather', ''),
        ('Mattresses', 'Dust mites, stains and odours', ''), ('Rugs', 'At your home or at our plant', ''),
        ('Paving & driveways', 'Pressure washing and sealing', ''), ('Car interiors', 'Seats, carpets and roof lining', '')]),
    features('why', 'Why it works', [
        ('sparkles', 'Machines, not shortcuts', 'Truck-mounted and portable extraction for deep cleans.'),
        ('heart', 'Safe for children and pets', 'Products we’re happy to use in our own homes.'),
        ('clock', 'Dry in hours', 'Most carpets are dry the same day.')]),
    reviews(),
    cta('cta', 'Get a price today', 'Send photos on WhatsApp for a fast quote.', 'Book a clean', '/quote'),
], seo_title='Carpet, couch and mattress cleaning, pressure washing')
t.page('prices', 'Prices', [
    services('services', 'Prices', [
        ('Carpet, per room', 'Up to 16 m²', ''), ('3-seater couch', 'Fabric', ''), ('Mattress', 'Single to king', ''),
        ('Rug', 'Per square metre', ''), ('Paving', 'Per square metre', ''), ('Car interior', 'Sedan or SUV', '')],
        note='Stains like red wine or ink may need extra treatment; we tell you first.'),
    packages('bundles', 'Bundles', [
        ('Moving out', 'All carpets and the oven cleaned for your deposit', True, 'Book', '/quote'),
        ('Allergy care', 'Mattresses and couches, every 6 months', False, 'Book', '/quote')]),
    faq('faq', 'Questions', [
        ('How long until I can use the carpet?', 'Usually 2–6 hours, depending on the weather and airflow.'),
        ('Will every stain come out?', 'Most do. We test first and tell you honestly what to expect.')]),
])
t.page('quote', 'Book a clean', [
    quote('Book a clean', 'Tell us what needs cleaning; add photos if you can.', ['Carpets', 'Couches', 'Mattresses', 'Rugs', 'Paving', 'Car interior'],
          [('Photos (optional)', 'file', '', 'no'), ('Preferred date', 'date', '', 'no')], submit='Get my price'),
    areas('Areas we cover'), contact('Contact us', 'WhatsApp photos for the fastest quote.')])
t.write()

# ───────────────────────── Car wash ─────────────────────────

t = T('car-wash', 'carwash-drive-in', 'Drive-in car wash', 1,
      'Fast and friendly: a wash list on the board and a queue that moves.', '#1D4ED8', '#FACC15', 'bold', 'round')
t.button = ('Prices', '/prices')
hero_img = t.img('hero', 'A car covered in foam at the wash bay', 'A car covered in foam in the wash bay, washers at work', 'car wash foam', 'droplets')
bay = t.img('bay', 'Washers drying a car with cloths', 'Washers drying and finishing a clean car', 'car wash workers drying car', 'users')
t.page('home', 'Home', [
    hero('photo', 'Clean car, quick', 'Hand wash, vacuum and valet while you wait — or drop it off and come back to a shine.',
         ('See prices', '/prices'), ('Find us', '/find-us'), [hero_img], ['No booking needed', 'Wi-Fi and coffee while you wait']),
    services('services', 'Wash list', [
        ('Wash & go', 'Outside wash and dry', '15 min'), ('Wash & vac', 'Outside, vacuum, dashboard wipe', '30 min'),
        ('Full valet', 'Inside and out, tyre shine, windows', '1 hr'), ('Engine wash', 'Degrease and rinse', '20 min'),
        ('Mats & boot', 'Mats washed, boot vacuumed', '10 min')], intro='Taxis, bakkies and trucks: ask for our prices.'),
    packages('club', 'Wash club', [
        ('Monthly wash club', 'Four wash & vacs a month, skip the queue', True, 'Join at the bay', '/find-us'),
        ('Fleet', 'Company cars and taxis on account', False, 'Ask us', '/find-us')]),
    image_text('team', 'A team that takes pride', 'Tell people about your washers, how you train them and how you pay them fairly.', bay, 'right'),
    hours('Open every day, including most public holidays.'),
    reviews(),
    cta('cta', 'Pop in today', 'No appointment needed.', 'Find us', '/find-us'),
], seo_title='Car wash — hand wash, vacuum and valet')
t.page('prices', 'Prices', [
    services('services', 'Prices', [
        ('Wash & go', 'Sedan / SUV / bakkie', '15 min'), ('Wash & vac', 'Sedan / SUV / bakkie', '30 min'),
        ('Full valet', 'Inside and out', '1 hr'), ('Engine wash', '', '20 min'), ('Polish', 'Hand polish and buff', '1 hr'),
        ('Taxi wash', '15-seater', '45 min')], note='Prices on the board at the wash bay.'),
])
t.page('find-us', 'Find us', [directions('Find us', 'Tell people what you’re next to — a garage, a mall or a taxi rank.'), hours(), contact('Contact us', 'Questions about fleets or taxis? Call us.')])
t.write()

t = T('car-wash', 'carwash-detailing', 'Detailing studio', 2,
      'Premium and precise: paint correction, ceramic coating and interior detailing.', '#0F172A', '#E11D48', 'technical', 'square')
t.button = ('Book a detail', '/book')
hero_img = t.img('hero', 'A glossy car bonnet reflecting studio lights', 'A freshly detailed car with a mirror-like finish under studio lights', 'car detailing polish', 'sparkles')
g1 = t.img('detail-1', 'A machine polisher on car paint', 'A machine polisher correcting paint', 'car paint polishing', 'sparkles')
g2 = t.img('detail-2', 'A clean leather car interior', 'A spotless leather interior after a detail', 'clean car interior leather', 'armchair')
g3 = t.img('detail-3', 'Water beading on a coated car', 'Water beading on a ceramic-coated panel', 'ceramic coating water beading', 'droplets')
t.page('home', 'Home', [
    hero('split', 'Better than the day you bought it', 'Paint correction, ceramic coating and interior detailing — by appointment, with care for every panel.',
         ('Book a detail', '/book'), ('Packages', '/packages'), [hero_img]),
    before_after('Before & after', 'Swirls, scratches and stains — gone.'),
    packages('packages', 'Detailing packages', [
        ('Showroom wash', 'Snow-foam wash, decontamination, sealant', False, 'Book', '/book'),
        ('Paint correction', 'One- or two-stage machine polish', True, 'Book', '/book'),
        ('Ceramic coating', 'Long-lasting protection and gloss', False, 'Book', '/book'),
        ('Interior detail', 'Deep clean, leather care, odour removal', False, 'Book', '/book')]),
    steps('process', 'Our process', [
        ('Inspection', 'We check the paint and agree what’s possible.'), ('Decontamination', 'Wash, clay and iron removal.'),
        ('Correction & protection', 'Polish, then coat or seal.'), ('Handover', 'A walk-around and care tips.')]),
    gallery('gallery', 'In the studio', [g1, g2, g3]),
    faq('faq', 'Questions', [
        ('How long does a ceramic coating last?', 'Depending on the product and care, from one to several years — we explain the options.'),
        ('How long do you need the car?', 'From half a day for an interior detail to two or three days for correction and coating.')]),
], seo_title='Car detailing — paint correction, ceramic coating, interiors')
t.page('packages', 'Packages', [
    packages('packages', 'Packages', [
        ('Showroom wash', 'Wash, decontamination, sealant', False, 'Book', '/book'),
        ('Paint correction', 'Machine polish', True, 'Book', '/book'),
        ('Ceramic coating', 'Coating with aftercare', False, 'Book', '/book'),
        ('Interior detail', 'Deep clean and leather care', False, 'Book', '/book')], intro='Prices depend on the size and condition of the car.'),
    services('extras', 'Extras', [
        ('Headlight restoration', 'Clear, bright lenses', '1 hr'), ('Engine bay detail', 'Clean and dress', '1 hr'),
        ('Wheel coating', 'Easier cleaning', '2 hrs')]),
])
t.page('book', 'Book', [book('Book a detail', 'Choose a package and a drop-off time.'), contact('Contact the studio', 'Send photos of your car for advice.'), directions('Find the studio')])
t.write()

t = T('car-wash', 'carwash-mobile', 'Mobile car wash', 3,
      'Eco-friendly and convenient: we wash at your home or office, using little water.', '#047857', '#0EA5E9', 'friendly', 'round')
t.button = ('Book a wash', '/book')
hero_img = t.img('hero', 'A car being washed in a driveway', 'A washer cleaning a car in a home driveway or office parking', 'mobile car wash driveway', 'droplets')
eco = t.img('eco', 'A spray bottle and microfibre cloths', 'Eco products and microfibre cloths ready for a waterless wash', 'waterless car wash products', 'leaf', decorative=True)
t.page('home', 'Home', [
    hero('centered', 'We wash your car where it’s parked', 'At home or at work, with little water and no mess. You carry on with your day.',
         ('Book a wash', '/book'), ('Washes and prices', '/book')),
    steps('how', 'How it works', [
        ('Book', 'Choose a time and tell us where the car is.'), ('We wash', 'Eco products and microfibre — about a glass of water per panel.'),
        ('Done', 'We send you a photo when it’s ready.')]),
    services('services', 'Washes', [
        ('Waterless wash', 'Outside, safe for every paint', '30 min'), ('Wash & vac', 'Outside and inside', '1 hr'),
        ('Full valet', 'Inside and out, tyres and windows', '2 hrs')]),
    image_text('eco', 'Kind to the water supply', 'Explain your products and how much water you save compared with a hose — important in water restrictions.', eco, 'left'),
    packages('plans', 'Plans', [
        ('Office parking day', 'We wash several cars at your office the same day', True, 'Ask us', '/book'),
        ('Monthly at home', 'A wash every week, same day', False, 'Book', '/book')]),
    areas('Areas we cover'),
    reviews(),
], seo_title='Mobile car wash — at home or at the office')
t.page('book', 'Book', [
    book('Book a wash', 'Choose a time; add the address when you book.'),
    quote('Offices and fleets', 'Several cars? Tell us how many and where.', ['Office parking day', 'Fleet', 'Dealership'],
          [('How many cars?', 'short', '', 'yes'), ('Address', 'short', '', 'yes')], submit='Ask for a quote'),
    faq('faq', 'Questions', [
        ('Do you need water or power?', 'No. We bring everything.'), ('Is waterless safe for my paint?', 'Yes, our products lift dirt so cloths don’t scratch.')]),
    contact('Contact us', 'WhatsApp is quickest.')])
t.write()

# ───────────────────────── Catering ─────────────────────────

t = T('catering', 'catering-events', 'Event catering', 1,
      'Generous and celebratory: weddings, funerals, parties and traditional ceremonies.', '#9A3412', '#16A34A', 'elegant', 'soft')
t.button = ('Get a quote', '/quote')
s1 = t.img('spread', 'A buffet table with many dishes', 'A generous buffet spread at an event', 'African buffet catering', 'utensils')
s2 = t.img('plated', 'A plated main course', 'A beautifully plated main course', 'plated food catering', 'utensils')
s3 = t.img('team', 'Chefs serving guests at a function', 'Catering staff serving guests at a wedding or function', 'catering staff serving', 'users')
s4 = t.img('dessert', 'A dessert table', 'A dessert table or wedding cake', 'dessert table wedding', 'cake')
t.page('home', 'Home', [
    hero('slideshow', 'Food your guests will talk about', 'Weddings, memorials, birthdays and traditional ceremonies — cooked with love, served on time.',
         ('Get a quote', '/quote'), ('Sample menus', '/menus'), [s1, s2, s3, s4]),
    features('why', 'Why families choose us', [
        ('heart', 'Cooked like at home', 'Traditional and modern dishes, made fresh on the day.'),
        ('users', 'Staff included', 'Servers, chafing dishes and clean-up on request.'),
        ('calendar', 'Big or small', 'From 30 to 1 000 guests.')]),
    packages('packages', 'Menus per guest', [
        ('Traditional', 'Pap, samp, rice, chakalaka, salads, two meats, dessert', True, 'Get a quote', '/quote'),
        ('Celebration buffet', 'Three mains, sides, salads and desserts', False, 'Get a quote', '/quote'),
        ('Plated dinner', 'Starter, main and dessert, served at the table', False, 'Get a quote', '/quote')]),
    gallery('gallery', 'From recent events', [s1, s2, s3, s4]),
    reviews('What hosts say'),
    cta('cta', 'Planning an event?', 'Tell us the date and guests — we’ll send a menu and price.', 'Get a quote', '/quote'),
], seo_title='Event catering — weddings, funerals and celebrations')
t.page('menus', 'Sample menus', [
    menu('menu', 'Sample menu', [
        ('Mains', [('Beef stew', 'Slow-cooked'), ('Roast chicken', 'Herb and lemon'), ('Lamb curry', 'Mild or hot')]),
        ('Sides', [('Pap & chakalaka', ''), ('Samp & beans', ''), ('Yellow rice', 'With raisins')]),
        ('Salads', [('Beetroot salad', ''), ('Coleslaw', ''), ('Potato salad', '')]),
        ('Desserts', [('Malva pudding', 'With custard'), ('Trifle', ''), ('Fruit platter', '')])],
         intro='Every menu is made to order — tell us what you love.', note='Halaal, vegetarian and other needs on request.'),
])
t.page('quote', 'Get a quote', [
    quote('Get a quote', 'Tell us about your event.', ['Wedding', 'Memorial / funeral', 'Birthday', 'Traditional ceremony', 'Corporate event'],
          [('Event date', 'date', '', 'yes'), ('Number of guests', 'short', '', 'yes'), ('Venue / area', 'short', '', 'yes'),
           ('Staff and equipment?', 'choice', 'Food only, Food and servers, Full service', 'no')], submit='Get my quote'),
    contact('Contact us', 'Call or WhatsApp to talk about your menu.')])
t.write()

t = T('catering', 'catering-corporate', 'Corporate & office catering', 2,
      'Crisp and dependable: daily lunches, meeting platters and year-end functions.', '#0F766E', '#F97316', 'clean', 'soft')
t.button = ('Order for the office', '/order')
p1 = t.img('platter', 'A platter of wraps and sandwiches', 'A meeting platter: wraps, sandwiches and fruit', 'catering platter sandwiches', 'utensils')
p2 = t.img('lunchbox', 'Lunch boxes ready for delivery', 'Neatly packed lunch boxes ready to deliver', 'lunch boxes delivery', 'package')
t.page('home', 'Home', [
    hero('split', 'Lunch, sorted', 'Meeting platters, daily lunches and year-end functions — delivered on time, every time.',
         ('Order for the office', '/order'), ('Menu', '/menu'), [p1], ['Order by 10:00 for lunch', 'Invoices for your accounts team']),
    features('offer', 'For busy teams', [
        ('clock', 'On time', 'Delivered before your meeting starts.'), ('check', 'Easy invoicing', 'Monthly accounts and purchase orders.'),
        ('heart', 'Something for everyone', 'Vegetarian, halaal and low-carb options, for 5 people or 500.')]),
    steps('how', 'How ordering works', [
        ('Choose', 'Pick platters or lunch boxes from the menu.'), ('Order by 10:00', 'For lunch the same day; earlier for big orders.'),
        ('We deliver', 'Set up in your boardroom, collected later.')]),
    image_text('boxes', 'Daily lunch boxes', 'A different menu every day, delivered to your office for the whole team.', p2, 'left', ('See the menu', '/menu')),
    logos('clients', 'Companies we feed'),
    cta('cta', 'Feeding a meeting tomorrow?', 'Order now, or ask about a monthly account.', 'Order', '/order'),
], seo_title='Office catering — platters, lunch boxes and functions')
t.page('menu', 'Menu', [
    menu('menu', 'Menu', [
        ('Platters (serves 10)', [('Wrap platter', 'Chicken, beef and veg'), ('Sandwich platter', 'Assorted'), ('Fruit platter', 'Seasonal')]),
        ('Lunch boxes', [('Chicken & rice', 'With salad'), ('Beef stew & pap', ''), ('Vegetarian bowl', 'Grains and roast veg')]),
        ('Breakfast', [('Muffins & pastries', ''), ('Yoghurt & granola cups', '')])],
         note='Ask about allergies and dietary needs.'),
    packages('functions', 'Functions', [
        ('Year-end function', 'Buffet or plated, staff included', True, 'Ask us', '/order'),
        ('Product launch', 'Canapés and drinks service', False, 'Ask us', '/order')]),
])
t.page('order', 'Order', [
    quote('Order for the office', 'Tell us what you need and when.', ['Platters', 'Lunch boxes', 'Breakfast', 'Function'],
          [('Company', 'short', '', 'yes'), ('Delivery date', 'date', '', 'yes'), ('Number of people', 'short', '', 'yes'),
           ('Delivery time', 'short', '', 'yes')], submit='Send my order'),
    contact('Contact us', 'Accounts and big orders: call us.')])
t.write()

t = T('catering', 'catering-home-kitchen', 'Home kitchen & meal prep', 3,
      'Homely and personal: weekly meals, braai packs, cakes and orders to collect.', '#B45309', '#65A30D', 'friendly', 'round')
t.button = ('Order', '/order')
k1 = t.img('meals', 'Weekly meals in containers', 'Weekly meal-prep containers, neatly packed', 'meal prep containers', 'utensils')
k2 = t.img('cake', 'A home-baked celebration cake', 'A home-baked celebration cake', 'homemade birthday cake', 'cake')
k3 = t.img('cook', 'The cook in her kitchen', 'The cook in a home kitchen, smiling', 'African woman cooking kitchen', 'chef-hat')
t.page('home', 'Home', [
    hero('collage', 'Home cooking, ready when you are', 'Weekly meals, family trays, braai packs and cakes — made in my kitchen, ready to collect or delivered nearby.',
         ('Order', '/order'), ('This week’s menu', '/menu'), [k1, k2, k3]),
    menu('menu', 'This week', [
        ('Meals', [('Chicken curry & rice', 'Serves 1 or a family tray'), ('Beef stew & dumplings', ''), ('Veg lasagne', '')]),
        ('Bakes', [('Scones', 'Per dozen'), ('Vetkoek', 'Per dozen'), ('Celebration cake', 'Order 3 days ahead')])],
         intro='The menu changes every week. Order by Wednesday for Friday collection.'),
    image_text('story', 'From my kitchen to your table', 'Tell people who you are, how you learnt to cook, and what makes your food special.', k3, 'right'),
    hours('Collection: Fridays 14:00–18:00 and Saturdays 09:00–12:00.'),
    directions('Collection point', 'Tell people where to collect — a street, a complex or a landmark.'),
    faq('faq', 'Good to know', [
        ('Do you deliver?', 'Nearby, for a small fee. Collection is free.'), ('How do I pay?', 'Pay when you order to confirm it.'),
        ('Can you cook for my party?', 'Yes — order family trays a week ahead.')]),
], seo_title='Home-cooked meals, cakes and meal prep')
t.page('menu', 'Menu', [
    services('trays', 'Family trays & packs', [
        ('Family tray', 'Serves 4–6', ''), ('Braai pack', 'Wors, chops, chicken — for 4', ''),
        ('Salads', 'Potato, coleslaw or beetroot', ''), ('Celebration cake', '20 cm, choice of flavour', '')]),
    menu('menu', 'Weekly meals', [
        ('This week', [('Chicken curry & rice', ''), ('Beef stew & dumplings', ''), ('Veg lasagne', '')])],
         note='Tell me about allergies when you order.'),
])
t.page('order', 'Order', [
    quote('Place an order', 'Choose what you’d like and when you’ll collect.', ['Weekly meals', 'Family tray', 'Braai pack', 'Cake', 'Bakes'],
          [('Collection or delivery date', 'date', '', 'yes'), ('Quantity and details', 'long', '', 'yes')], submit='Send my order'),
    contact('Contact me', 'WhatsApp is quickest.')])
t.write()

write_slots()
print('ok')
