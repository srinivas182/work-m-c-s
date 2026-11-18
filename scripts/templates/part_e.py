"""Sprint 20.5: a third design for each of the first twelve categories."""
from lib import *


def form(purpose, heading, intro, questions=(), submit='Send', details='Anything else?'):
    return blk('form', 'Form', purpose=purpose, heading=heading, intro=intro, detailsLabel=details, submitLabel=submit,
               leads='yes', askNews='yes', questions=[{'label': l, 'type': t, 'choices': c, 'required': r} for l, t, c, r in questions])


def banner(text, label, href, tone='brand'):
    return blk('promo', 'PromotionBanner', text=text, linkLabel=label, linkHref=href, tone=tone, starts='', ends='')


# Salon & beauty — makeup artist and bridal beauty
t = T('salon-beauty', 'beauty-makeup-bridal', 'Makeup & bridal beauty', 3,
      'Glamorous and personal: makeup for weddings, matric dances and shoots, on location.', '#831843', '#D4A373', 'elegant', 'soft')
t.button = ('Check my date', '/book')
h = t.img('hero', 'A makeup artist doing a bride’s makeup', 'A makeup artist doing a bride’s makeup', 'African bride makeup artist', 'sparkles')
m1 = t.img('look-1', 'Bridal makeup, close up', 'A finished bridal look, close up', 'Black bride makeup', 'sparkles')
m2 = t.img('look-2', 'Soft glam for a matric dance', 'Soft glam for a matric dance or party', 'soft glam makeup Black woman', 'sparkles')
m3 = t.img('look-3', 'Bridesmaids getting ready', 'Bridesmaids getting ready together', 'bridesmaids getting ready African', 'users')
t.page('home', 'Home', [
    hero('slideshow', 'Look like you, on your best day', 'Bridal, matric dance and event makeup — flawless in photos, comfortable all day. We come to you.',
         ('Check my date', '/book'), ('Packages', '/packages'), [m1, m2, m3]),
    gallery('looks', 'Recent looks', [m1, m2, m3, h]),
    packages('packages', 'Packages', [
        ('Bride', 'Trial, wedding-day makeup and lashes', True, 'Check my date', '/book'),
        ('Bridal party', 'Bride plus bridesmaids and mothers', False, 'Ask us', '/book'),
        ('Matric dance & events', 'Soft or full glam, with lashes', False, 'Book', '/book')]),
    steps('how', 'How booking works', [('Check the date', 'Weekends book up months ahead.'), ('Trial', 'We perfect your look before the day.'),
                                      ('The day', 'We arrive early, with everything.')]),
    reviews('What brides say'),
], seo_title='Makeup artist — bridal, matric dance and events')
t.page('packages', 'Packages', [
    services('services', 'Prices', [('Bridal makeup', 'Including trial', '2 hrs'), ('Bridesmaid', 'Per person', '45 min'),
                                    ('Matric dance', 'Soft or full glam', '1 hr'), ('Photoshoot', 'Studio or location', '1 hr'),
                                    ('Lashes', 'Strip or individual', '15 min')], note='Travel fee depends on the venue.'),
    faq('faq', 'Questions', [('How far ahead should I book?', 'For weddings, three to six months.'),
                             ('Do you travel?', 'Yes — to homes, venues and hotels.'), ('Is a deposit needed?', 'Yes, to secure your date.')]),
])
t.page('book', 'Book', [
    quote('Check my date', 'Tell us about your day.', ['Wedding', 'Matric dance', 'Event', 'Photoshoot'],
          [('Date', 'date', '', 'yes'), ('Number of people', 'short', '', 'yes'), ('Venue / area', 'short', '', 'no')], submit='Check my date'),
    contact('Contact me', 'WhatsApp a photo of looks you love.')])
t.write()

# Health — physiotherapy and allied health
t = T('health', 'health-physio', 'Physiotherapy & allied health', 3,
      'Active and reassuring: treatments, conditions, medical aids and online booking.', '#0F766E', '#F97316', 'modern', 'round')
t.button = ('Book a session', '/book')
h = t.img('hero', 'A physiotherapist treating a patient’s knee', 'A physiotherapist treating a patient', 'Black physiotherapist patient treatment', 'activity')
gym = t.img('rehab', 'A patient doing rehab exercises', 'A patient doing guided exercises in the practice', 'physiotherapy rehab exercise', 'dumbbell')
t.page('home', 'Home', [
    hero('split', 'Move well. Feel better.', 'Treatment for injuries, back and neck pain, sports and recovery after surgery — with a plan that gets you back to normal.',
         ('Book a session', '/book'), ('What we treat', '/treatments'), [h], ['Medical aids accepted', 'Same-week appointments']),
    features('treat', 'What we treat', [
        ('heart', 'Back and neck pain', 'Posture, desk work and lifting injuries.'), ('star', 'Sports injuries', 'Ankles, knees, shoulders and hamstrings.'),
        ('clock', 'After surgery', 'Rehab after knee, hip and shoulder operations.')]),
    steps('how', 'Your first visit', [('Assessment', 'We find what’s causing the problem.'), ('Treatment', 'Hands-on treatment and exercises.'),
                                     ('Your plan', 'What to do at home, and how many sessions you need.')]),
    image_text('rehab', 'Our practice', 'Describe your rooms, rehab area and the therapists who work here.', gym, 'right'),
    logos('medical-aids', 'Medical aids we work with'),
    reviews('What patients say'),
], seo_title='Physiotherapy — injuries, pain and rehabilitation')
t.page('treatments', 'Treatments', [
    services('services', 'Treatments', [('First consultation', 'Assessment and treatment', '45 min'), ('Follow-up', 'Treatment', '30 min'),
                                        ('Sports massage', '', '45 min'), ('Dry needling', '', '30 min'), ('Rehab class', 'Small group', '45 min')],
             note='Rates as per medical aid; cash rates on request.'),
    faq('faq', 'Questions', [('Do I need a referral?', 'No, you can book directly.'), ('What should I wear?', 'Loose clothes you can move in.')]),
])
t.page('book', 'Book', [book('Book a session'), contact('Contact the practice', 'Bring your medical aid card and ID.'), hours(), directions('Find the practice')])
t.write()

# Spa & wellness — massage therapist and mobile spa
t = T('spa-wellness', 'spa-mobile-massage', 'Massage & mobile spa', 3,
      'Soothing and simple: massages and treatments at home, hotels or events.', '#365314', '#D97706', 'clean', 'round')
t.button = ('Book a massage', '/book')
h = t.img('hero', 'A relaxing massage with oils', 'A calm massage treatment', 'massage therapy relaxing', 'flower')
setup = t.img('setup', 'A massage table set up at home', 'A massage table set up in a home or hotel room', 'massage table candles towels', 'flower-2', decorative=True)
t.page('home', 'Home', [
    hero('photo', 'Relax. We’ll bring the spa.', 'Massages and treatments in your home, hotel or office — towels, oils and music included.',
         ('Book a massage', '/book'), ('Treatments', '/treatments'), [h], ['Home, hotel or office', 'Evenings and weekends']),
    services('treatments', 'Treatments', [('Swedish massage', 'Relaxing, full body', '60 min'), ('Deep tissue', 'For tight muscles', '60 min'),
                                          ('Hot stones', 'Warm, deeply relaxing', '75 min'), ('Couples massage', 'Two therapists', '60 min')]),
    image_text('setup', 'We set up everything', 'A professional table, fresh linen, oils and music. You just relax.', setup, 'left'),
    packages('events', 'For groups', [('Bridal shower', 'Massages for the bride and friends', True, 'Ask us', '/book'),
                                      ('Office wellness day', 'Chair massages for the team', False, 'Ask us', '/book')]),
    areas('Areas we travel to'),
], seo_title='Mobile massage — spa treatments at home')
t.page('treatments', 'Treatments', [services('treatments', 'Treatments and prices', [
    ('Swedish massage', '', '60 or 90 min'), ('Deep tissue', '', '60 min'), ('Hot stones', '', '75 min'), ('Foot massage', '', '30 min'),
    ('Couples massage', '', '60 min')], note='A travel fee may apply outside our main areas.'),
    faq('faq', 'Good to know', [('What do I need to provide?', 'A quiet room with space for the table.'),
                                ('Are your therapists qualified?', 'Say where your therapists trained.')])])
t.page('book', 'Book', [book('Book a massage', 'Choose a treatment and time; add your address.'), contact('Contact us', 'WhatsApp us for groups and events.')])
t.write()

# Retail — online gifts and homeware
t = T('retail', 'retail-gifts-homeware', 'Gifts & homeware', 3,
      'Curated and stylish: an online shop of gifts, décor and handmade goods.', '#9A3412', '#0F766E', 'editorial', 'soft')
t.button = ('Shop', '/shop')
h = t.img('hero', 'Handmade baskets and ceramics on a table', 'Styled homeware: baskets, ceramics, cushions', 'African homeware baskets ceramics', 'gift')
mk = t.img('maker', 'A craftswoman weaving a basket', 'A maker weaving or crafting', 'African woman weaving basket', 'hand')
t.page('home', 'Home', [
    banner('Free delivery on orders over a set amount — add yours', 'Shop now', '/shop'),
    hero('collage', 'Beautiful things, made here', 'Handmade homeware and gifts from local makers — for your home or someone you love.',
         ('Shop', '/shop'), ('Our makers', '/makers'), [h, mk]),
    blk('products', 'ProductGrid', heading='New in', intro='', category='', limit='up-to-6'),
    image_text('makers', 'Meet the makers', 'Tell the stories of the people who make your products, and how you work with them fairly.', mk, 'right', ('Our makers', '/makers')),
    features('why', 'Why shop with us', [('heart', 'Handmade', 'Every piece is unique.'), ('star', 'Gift-wrapped', 'Add a note; we wrap it.'),
                                         ('check', 'Easy returns', 'Explain your returns policy.')]),
], seo_title='Gifts and homeware — handmade by local makers')
t.page('shop', 'Shop', [text('intro', 'Shop', 'Homeware and gifts, made by hand.'), blk('products', 'ProductGrid', heading='All products', intro='', category='', limit='up-to-12')])
t.page('makers', 'Our makers', [text('story', 'Our makers', 'Introduce each maker: where they work, their craft and their story.'), gallery('studio', 'In the studio', [mk, h])])
t.write()

# Fitness — yoga, pilates and dance studio
t = T('fitness', 'fitness-yoga-dance', 'Yoga, pilates & dance studio', 3,
      'Light and calm: a timetable of classes, intro offer and booking.', '#6D28D9', '#14B8A6', 'clean', 'round')
t.button = ('Book a class', '/book')
h = t.img('hero', 'A yoga class in a bright studio', 'A class in a bright studio', 'yoga class Black women studio', 'flower')
d = t.img('dance', 'A dance class mid-movement', 'A dance or pilates class in motion', 'dance class studio Africa', 'music')
t.page('home', 'Home', [
    banner('New here? Your first week of classes for a special price', 'Book your first class', '/book'),
    hero('split', 'Move, breathe, feel at home', 'Yoga, pilates and dance classes for every body and every level.',
         ('Book a class', '/book'), ('Timetable', '/timetable'), [h]),
    blk('timetable', 'ClassTimetable', heading='This week', intro='Book ahead — classes are small.', note='', bookLabel='Book a class', bookHref='/book', classes=[
        {'day': 'Monday', 'start': '06:00', 'end': '07:00', 'name': 'Morning flow yoga', 'instructor': '', 'level': 'All levels'},
        {'day': 'Tuesday', 'start': '18:00', 'end': '19:00', 'name': 'Mat pilates', 'instructor': '', 'level': 'Beginner'},
        {'day': 'Wednesday', 'start': '18:30', 'end': '19:30', 'name': 'Afro dance', 'instructor': '', 'level': 'All levels'},
        {'day': 'Saturday', 'start': '08:00', 'end': '09:15', 'name': 'Slow yoga', 'instructor': '', 'level': 'All levels'}]),
    image_text('dance', 'Classes for every body', 'Describe your classes and teachers, and how beginners are looked after.', d, 'left'),
    packages('passes', 'Passes', [('Intro week', 'Unlimited classes for 7 days', True, 'Start', '/book'),
                                  ('10-class pass', 'Use within 3 months', False, 'Buy', '/book'), ('Monthly unlimited', 'Every class, every week', False, 'Join', '/book')]),
    reviews(),
], seo_title='Yoga, pilates and dance classes')
t.page('timetable', 'Timetable', [blk('timetable', 'ClassTimetable', heading='Timetable', intro='', note='Timetable changes over holidays.', bookLabel='Book', bookHref='/book', classes=[
    {'day': 'Monday', 'start': '06:00', 'end': '07:00', 'name': 'Morning flow yoga', 'instructor': '', 'level': 'All levels'},
    {'day': 'Tuesday', 'start': '18:00', 'end': '19:00', 'name': 'Mat pilates', 'instructor': '', 'level': 'Beginner'},
    {'day': 'Wednesday', 'start': '18:30', 'end': '19:30', 'name': 'Afro dance', 'instructor': '', 'level': 'All levels'},
    {'day': 'Thursday', 'start': '18:00', 'end': '19:00', 'name': 'Power yoga', 'instructor': '', 'level': 'Intermediate'},
    {'day': 'Saturday', 'start': '08:00', 'end': '09:15', 'name': 'Slow yoga', 'instructor': '', 'level': 'All levels'}]),
    team('Teachers')])
t.page('book', 'Book', [book('Book a class', 'Choose a class and reserve your spot.', mode='classes'), contact('Contact the studio', 'Private classes and events: ask us.'), directions('Find the studio')])
t.write()

# Professional services — coach or solo consultant
t = T('professional-services', 'professional-consultant', 'Consultant & coach', 3,
      'Personal and credible: one expert, their offer, results and a discovery call.', '#1E293B', '#EAB308', 'editorial', 'square')
t.button = ('Book a call', '/call')
h = t.img('hero', 'A consultant speaking with a client', 'The consultant, professional portrait or with a client', 'Black businesswoman consultant portrait', 'user')
w = t.img('workshop', 'A workshop with a team', 'Running a workshop with a team', 'business workshop facilitator Africa', 'presentation')
t.page('home', 'Home', [
    hero('split', 'Clarity for your next move', 'I help small-business owners and teams plan, grow and lead — practical advice, no jargon.',
         ('Book a call', '/call'), ('How I help', '/services'), [h]),
    logos('clients', 'Clients I’ve worked with'),
    features('offer', 'How I help', [('star', 'Strategy', 'A clear plan for the next 12 months.'), ('users', 'Leadership coaching', 'One-to-one, for owners and managers.'),
                                     ('sparkles', 'Workshops', 'Half- or full-day sessions with your team.')]),
    image_text('workshops', 'Workshops that change how teams work', 'Describe a typical workshop and what teams take away.', w, 'left'),
    steps('how', 'Working together', [('Discovery call', 'Free, 30 minutes.'), ('Proposal', 'Scope, timeline and fee.'), ('Work', 'Sessions, check-ins and results.')]),
    reviews('What clients say'),
], seo_title='Business consultant and coach')
t.page('services', 'Services', [
    packages('packages', 'Ways to work with me', [('Strategy day', 'One day, one plan', False, 'Book a call', '/call'),
                                                   ('Coaching', 'Monthly one-to-one sessions', True, 'Book a call', '/call'),
                                                   ('Team workshop', 'Half or full day', False, 'Book a call', '/call')]),
    text('about', 'About me', 'Your background, qualifications and why you do this work.')])
t.page('call', 'Book a call', [
    form('enquiry', 'Book a discovery call', 'Tell me a little about your business.',
         [('Business', 'short', '', 'no'), ('What would you like help with?', 'long', '', 'yes')], submit='Book my call'),
    contact('Contact me', 'Speaking and media requests welcome.')])
t.write()

# Car mechanics — tyres and fitment centre
t = T('car-mechanics', 'mechanics-tyres-fitment', 'Tyres & fitment centre', 3,
      'Fast and clear: tyres, alignment, batteries and exhausts while you wait.', '#111827', '#EAB308', 'bold', 'round')
t.button = ('Get a tyre quote', '/quote')
h = t.img('hero', 'A technician fitting a tyre', 'A technician fitting a tyre on a car', 'tyre fitting mechanic', 'circle-dot')
a = t.img('alignment', 'A car on an alignment machine', 'A car on a wheel-alignment machine', 'wheel alignment machine', 'gauge')
t.page('home', 'Home', [
    hero('photo', 'Tyres fitted while you wait', 'New tyres, wheel alignment, balancing, batteries and exhausts — fast, honest and priced up front.',
         ('Get a tyre quote', '/quote'), ('Services', '/services'), [h], ['No appointment needed', 'All major brands']),
    services('services', 'Services', [('Tyres', 'Supply and fit, all sizes', '30 min'), ('Wheel alignment', 'Computerised', '30 min'),
                                      ('Balancing', 'Per wheel', '10 min'), ('Batteries', 'Free battery test', '15 min'),
                                      ('Exhausts', 'Repairs and replacements', '1 hr'), ('Puncture repair', '', '15 min')]),
    image_text('alignment', 'Alignment that saves your tyres', 'Explain how alignment and balancing make tyres last longer.', a, 'right'),
    logos('brands', 'Tyre brands we fit'),
    hours(), directions('Find the centre'),
], seo_title='Tyres, alignment and batteries — fitment centre')
t.page('services', 'Services', [services('services', 'Prices', [('Tyres', 'Ask for your size', ''), ('Alignment', '', '30 min'), ('Balancing', 'Per wheel', ''),
                                                               ('Battery', 'Supply and fit', ''), ('Exhaust', 'Quote on inspection', '')]),
    faq('faq', 'Questions', [('Do I need to book?', 'No — drive in.'), ('Do you sell second-hand tyres?', 'Say whether you do.')])])
t.page('quote', 'Tyre quote', [
    quote('Get a tyre quote', 'Tell us your tyre size (it’s on the side of the tyre).', ['Tyres', 'Alignment', 'Battery', 'Exhaust'],
          [('Tyre size (e.g. 195/65 R15)', 'short', '', 'no'), ('How many?', 'choice', '1, 2, 3, 4', 'no')], submit='Get my quote'),
    contact('Contact us', 'Fleet and taxi accounts: ask us.')])
t.write()

# Hospitality — café and bakery
t = T('hospitality', 'hospitality-cafe-bakery', 'Café & bakery', 3,
      'Cosy and appetising: menu, fresh bakes, cake orders and opening hours.', '#78350F', '#DB2777', 'friendly', 'round')
t.button = ('Order a cake', '/cakes')
h = t.img('hero', 'Coffee and pastries on a café table', 'Coffee and fresh pastries on a café table', 'coffee pastries cafe', 'coffee')
c = t.img('cakes', 'A celebration cake', 'A celebration cake from the bakery', 'celebration cake bakery', 'cake')
i = t.img('inside', 'Inside the café', 'Inside the café, warm and busy', 'cosy cafe interior Africa', 'coffee')
t.page('home', 'Home', [
    hero('split', 'Fresh bakes, good coffee, every morning', 'Breakfast, light lunches, coffee and cakes baked here daily.',
         ('See the menu', '/menu'), ('Order a cake', '/cakes'), [h], ['Open from 07:00', 'Free Wi-Fi']),
    menu('menu', 'Favourites', [('Breakfast', [('Full breakfast', ''), ('Vetkoek & mince', ''), ('Granola bowl', '')]),
                                ('Bakery', [('Croissants', 'Baked every morning'), ('Scones', 'With jam and cream'), ('Carrot cake', 'Per slice')])]),
    image_text('cakes', 'Cakes for every celebration', 'Birthdays, weddings and baby showers — order three days ahead.', c, 'right', ('Order a cake', '/cakes')),
    gallery('inside', 'The café', [i, h, c]),
    hours('Open every day; kitchen closes 30 minutes before.'),
    directions('Find us'),
], seo_title='Café and bakery — breakfast, coffee and cakes')
t.page('menu', 'Menu', [menu('menu', 'Menu', [
    ('Breakfast', [('Full breakfast', ''), ('Vetkoek & mince', ''), ('Granola bowl', '')]),
    ('Lunch', [('Chicken wrap', ''), ('Toasted sandwiches', ''), ('Soup of the day', '')]),
    ('Coffee & drinks', [('Cappuccino', ''), ('Rooibos latte', ''), ('Fresh juice', '')]),
    ('Bakery', [('Croissants', ''), ('Scones', ''), ('Cake of the day', 'Per slice')])], note='Ask us about allergies.')])
t.page('cakes', 'Cakes', [
    quote('Order a cake', 'Tell us about the celebration.', ['Birthday', 'Wedding', 'Baby shower', 'Cupcakes'],
          [('Date needed', 'date', '', 'yes'), ('Number of people', 'short', '', 'yes'), ('Photo of a cake you like', 'file', '', 'no')], submit='Order'),
    contact('Contact us', 'Call or WhatsApp the café.')])
t.write()

# Trades & home — builder and renovations
t = T('trades-home', 'trades-builder-renovations', 'Builder & renovations', 3,
      'Solid and proven: projects, process, NHBRC registration and a site-visit quote.', '#7C2D12', '#0EA5E9', 'technical', 'square')
t.button = ('Book a site visit', '/quote')
h = t.img('hero', 'Builders working on a house extension', 'Builders at work on an extension or new house', 'construction workers building house Africa', 'hard-hat')
p1 = t.img('project-1', 'A finished home extension', 'A finished extension or new room', 'house extension finished', 'house')
p2 = t.img('project-2', 'A renovated bathroom', 'A renovated bathroom or kitchen', 'renovated bathroom tiles', 'bath')
t.page('home', 'Home', [
    hero('photo', 'Built properly. Finished on time.', 'Extensions, new homes, renovations, tiling and paving — with clear quotes and a builder who answers the phone.',
         ('Book a site visit', '/quote'), ('Our projects', '/projects'), [h], ['NHBRC registered', 'Written quotes']),
    steps('process', 'How we work', [('Site visit', 'We measure and listen.'), ('Quote and plans', 'A written quote; plans if you need them.'),
                                    ('Build', 'Weekly updates and photos.'), ('Handover', 'A walk-through and a clean site.')]),
    blk('projects', 'Portfolio', heading='Recent projects', intro='', projects=[
        {'title': 'Two-room extension', 'photo': p1['src'], 'photoAlt': p1['alt'], 'category': 'Extension', 'story': 'Describe the job.', 'outcome': 'What the client got.'},
        {'title': 'Bathroom renovation', 'photo': p2['src'], 'photoAlt': p2['alt'], 'category': 'Renovation', 'story': 'Describe the job.', 'outcome': 'What the client got.'}]),
    accreditations('Registered', [('NHBRC', 'Add your registration number'), ('CIDB', 'Add your grading, if any')]),
    reviews(),
], seo_title='Builder — extensions, renovations and new homes')
t.page('projects', 'Projects', [before_after('Before & after', 'Real projects.'), gallery('gallery', 'Gallery', [p1, p2, h])])
t.page('quote', 'Site visit', [
    quote('Book a site visit', 'Tell us about the project.', ['Extension', 'New house', 'Renovation', 'Tiling', 'Paving', 'Roofing'],
          [('Area', 'short', '', 'yes'), ('Do you have plans?', 'yesno', '', 'no'), ('Budget (rough)', 'short', '', 'no')], submit='Book my visit'),
    areas('Areas we build in'), contact('Contact us', 'Call or WhatsApp.')])
t.write()

# Education — driving school
t = T('education-training', 'education-driving-school', 'Driving school', 3,
      'Encouraging and practical: lessons, packages, licence codes and booking.', '#B91C1C', '#0EA5E9', 'bold', 'round')
t.button = ('Book a lesson', '/book')
h = t.img('hero', 'An instructor with a learner driver', 'An instructor with a learner driver in a dual-control car', 'driving instructor learner driver', 'car')
t.page('home', 'Home', [
    hero('split', 'Pass your licence with confidence', 'Patient instructors, dual-control cars and lessons around your schedule — Code 8, 10 and 14.',
         ('Book a lesson', '/book'), ('Packages', '/packages'), [h], ['Pick-up from home or work', 'Test-day car hire']),
    steps('road', 'From learner’s to licence', [('Learner’s licence', 'We help you prepare for the test.'), ('Lessons', 'In the car, at your pace.'),
                                               ('Mock test', 'Practise the yard and the road.'), ('Test day', 'We take you in our car.')]),
    packages('packages', 'Packages', [('Code 8 — 10 lessons', 'For beginners', True, 'Book', '/book'), ('Code 10', 'Light trucks', False, 'Book', '/book'),
                                      ('Refresher', 'Licensed but nervous? 3 lessons', False, 'Book', '/book')]),
    faq('faq', 'Questions', [('How many lessons will I need?', 'Most beginners need 10–20.'), ('Do you help book the test?', 'Yes — we explain how to book at the testing centre.')]),
    reviews('What learners say'),
], seo_title='Driving school — Code 8, 10 and 14 lessons')
t.page('packages', 'Packages', [services('lessons', 'Lessons and prices', [('Single lesson', 'Code 8', '1 hr'), ('10 lessons', 'Code 8', ''),
                                                                          ('Code 10 lessons', '', '1 hr'), ('Test-day car hire', 'Includes a warm-up lesson', '')])])
t.page('book', 'Book', [book('Book a lesson'), contact('Contact us', 'WhatsApp to book.'), areas('Areas we pick up from')])
t.write()

# Events & creative — DJ, sound and entertainment
t = T('events-creative', 'events-dj-sound', 'DJ, sound & entertainment', 3,
      'High-energy: a DJ or sound-hire business with mixes, packages and date checks.', '#0F172A', '#A3E635', 'technical', 'square')
t.button = ('Check my date', '/book')
h = t.img('hero', 'A DJ performing at an event', 'A DJ performing to a crowd', 'African DJ party crowd', 'disc-3')
s = t.img('sound', 'Speakers and lights set up for an event', 'Sound and lighting set up at a venue', 'event speakers lighting setup', 'speaker')
t.page('home', 'Home', [
    hero('photo', 'Your event. Your sound.', 'DJs, sound and lights for weddings, parties, corporate events and festivals.',
         ('Check my date', '/book'), ('Packages', '/packages'), [h]),
    video('Watch a set', 'A minute from a recent event.', 'Highlights from a recent event'),
    packages('packages', 'Packages', [('Party', 'DJ, sound and basic lights — 5 hours', False, 'Check my date', '/book'),
                                      ('Wedding', 'Ceremony sound, MC mic, DJ and dance floor lights', True, 'Check my date', '/book'),
                                      ('Corporate', 'PA, mics and background music', False, 'Ask us', '/book')]),
    image_text('sound', 'Sound and light hire', 'List your equipment for hire: speakers, mics, lights, staging.', s, 'right'),
    logos('venues', 'Venues we’ve played'),
], seo_title='DJ, sound and lighting hire for events')
t.page('packages', 'Packages', [services('hire', 'Equipment hire', [('Speaker pair', 'With stands', 'Per day'), ('Wireless mics', 'Each', 'Per day'),
                                                                    ('Dance-floor lights', 'Set', 'Per day'), ('Generator', 'For outdoor events', 'Per day')]),
    faq('faq', 'Questions', [('Do you bring a generator?', 'Ask us for outdoor events.'), ('Can we choose the music?', 'Yes — send a must-play and a do-not-play list.')])])
t.page('book', 'Book', [
    quote('Check my date', 'Tell us about your event.', ['Wedding', 'Party', 'Corporate', 'Festival', 'Equipment hire'],
          [('Date', 'date', '', 'yes'), ('Venue / area', 'short', '', 'yes'), ('Number of guests', 'short', '', 'no')], submit='Check my date'),
    contact('Contact us', 'Bookings and hire.')])
t.write()

# Transport & logistics — freight and trucking
t = T('transport-logistics', 'transport-freight', 'Freight & trucking', 3,
      'Reliable and corporate: long-distance freight, fleet, tracking and quotes.', '#1E3A8A', '#F97316', 'technical', 'square')
t.button = ('Get a freight quote', '/quote')
h = t.img('hero', 'A truck on the highway', 'A truck on the open road', 'truck highway South Africa', 'truck')
f = t.img('fleet', 'The fleet parked at the depot', 'The fleet at the depot', 'truck fleet depot', 'warehouse')
t.page('home', 'Home', [
    hero('photo', 'Your load, delivered on time', 'Long-distance and cross-border freight with tracked trucks and drivers you can reach.',
         ('Get a freight quote', '/quote'), ('Our fleet', '/fleet'), [h], ['Tracked 24/7', 'Cross-border to SADC']),
    features('services', 'Services', [('star', 'Full loads', 'Tautliners, flatbeds and tippers.'),
                                      ('pin', 'Cross-border', 'Botswana, Namibia, Zimbabwe, Mozambique and Zambia.'),
                                      ('shield', 'Insured', 'Goods-in-transit cover on every load.')]),
    image_text('fleet', 'Our fleet', 'List your trucks and trailers, payloads and how you maintain them.', f, 'right'),
    steps('how', 'How it works', [('Quote', 'Tell us the load and route.'), ('Load', 'We collect on the agreed day.'), ('Track', 'Updates until delivery.')]),
    logos('clients', 'Clients'),
], seo_title='Freight and trucking — long-distance and cross-border')
t.page('fleet', 'Fleet', [services('fleet', 'Fleet', [('Tautliner', '34 tonnes', ''), ('Flatbed', '30 tonnes', ''), ('Tipper', '30 tonnes', ''), ('8-tonner', 'Local loads', '')]),
                          accreditations('Compliance', [('RTMS', 'Add if accredited'), ('Insurance', 'Goods-in-transit cover')])])
t.page('quote', 'Freight quote', [
    quote('Get a freight quote', 'Tell us about the load.', ['Full load', 'Part load', 'Cross-border', 'Abnormal load'],
          [('From', 'short', '', 'yes'), ('To', 'short', '', 'yes'), ('Load (type and weight)', 'short', '', 'yes'), ('Date', 'date', '', 'no')],
          submit='Get my quote'),
    contact('Contact operations', 'Our control room is open 24 hours.')])
t.write()

write_slots()
print('ok')
