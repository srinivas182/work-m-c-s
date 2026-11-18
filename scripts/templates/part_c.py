"""Sprint 20.4c: church & NGO, school, funeral services, security — three designs each."""
from lib import *


def form(purpose, heading, intro, questions=(), submit='Send', details='Anything else?'):
    return blk('form', 'Form', purpose=purpose, heading=heading, intro=intro, detailsLabel=details, submitLabel=submit,
               leads='yes', askNews='yes', questions=[{'label': l, 'type': t, 'choices': c, 'required': r} for l, t, c, r in questions])


def banner(text, label, href, tone='brand'):
    return blk('promo', 'PromotionBanner', text=text, linkLabel=label, linkHref=href, tone=tone, starts='', ends='')


def times(id, heading, items, intro=''):
    """Service or meeting times, shown as a feature grid (day and time as the title)."""
    return features(id, heading, [('clock', t, x) for t, x in items], intro)


# ───────────────────────── Church & NGO ─────────────────────────

t = T('church-ngo', 'church-community', 'Community church', 1,
      'Warm and welcoming: service times, “new here?”, ministries and giving.', '#4C1D95', '#F59E0B', 'classic', 'soft')
t.button = ('Plan your visit', '/visit')
h = t.img('hero', 'A congregation worshipping together', 'The congregation worshipping together on a Sunday', 'African church congregation worship', 'church')
choir = t.img('choir', 'The choir singing', 'The choir or worship team singing', 'African church choir', 'music')
kids = t.img('kids', 'Children in Sunday school', 'Children’s ministry: children in Sunday school with a teacher', 'Sunday school children Africa', 'smile')
t.page('home', 'Home', [
    hero('photo', 'You belong here', 'A church family that worships, prays and serves our community together. Come as you are.',
         ('Plan your visit', '/visit'), ('Service times', '/visit'), [h], ['Sundays 09:00', 'Everyone welcome']),
    times('services', 'Join us', [('Sunday 09:00', 'Main service, with children’s church'), ('Wednesday 18:30', 'Prayer and Bible study'),
                                  ('Friday 18:00', 'Youth')]),
    steps('new', 'New here?', [('Come as you are', 'No dress code; just come.'), ('We’ll meet you', 'Our welcome team will show you around.'),
                               ('Stay for tea', 'Meet people after the service.')]),
    features('ministries', 'Ministries', [
        ('users', 'Youth', 'Fridays — games, worship and real talk.'), ('heart', 'Women and men', 'Fellowship and support groups.'),
        ('star', 'Children', 'Safe, fun Sunday school for every age.')]),
    image_text('choir', 'Worship', 'Tell people about your worship, your choir and the languages you worship in.', choir, 'left'),
    video('A message from our pastor', 'A short welcome or part of last Sunday’s message.', 'Our pastor welcoming visitors'),
    cta('give', 'Partner with us', 'Your giving supports our ministries and community outreach.', 'Give', '/give'),
], seo_title='Community church — service times and ministries')
t.page('about', 'About us', [
    text('beliefs', 'What we believe', 'Share your statement of faith in a few short paragraphs.'),
    team('Our leaders', 'Pastors, elders and ministry leaders.'),
    image_text('kids', 'For the whole family', 'Describe children’s and youth ministries and how you keep children safe.', kids, 'right'),
])
t.page('visit', 'Plan your visit', [
    times('services', 'Service times', [('Sunday 09:00', 'Main service'), ('Wednesday 18:30', 'Prayer and Bible study'), ('Friday 18:00', 'Youth')]),
    directions('Find us', 'Tell visitors where to park or which entrance to use.'),
    form('enquiry', 'Get in touch', 'Questions, prayer or a visit from the pastor — we’re here.',
         [('How can we help?', 'choice', 'I’m planning a visit, Prayer, Pastoral visit, Wedding or baptism, Other', 'yes')]),
])
t.page('give', 'Give', [
    text('give', 'Give', 'Thank you for giving. Add your bank details or giving options here (EFT, SnapScan, cash on Sunday), and what giving supports.'),
    faq('faq', 'Questions about giving', [('Can I get a receipt?', 'Say whether you issue receipts and Section 18A certificates (if registered).'),
                                          ('Can I give once-off?', 'Yes — any amount, any time.')]),
])
t.write()

t = T('church-ngo', 'ngo-impact', 'Charity & NGO', 2,
      'Clear and credible: programmes, impact stories, partners, volunteering and donations.', '#0F766E', '#F97316', 'modern', 'round')
t.button = ('Donate', '/donate')
h = t.img('hero', 'Volunteers working with children in the community', 'Volunteers working with people in the community (with consent)', 'NGO volunteers community Africa', 'heart-handshake')
p1 = t.img('programme', 'A community food garden', 'A programme in action: a food garden, feeding scheme or class', 'community garden Africa', 'sprout')
p2 = t.img('volunteers', 'A group of volunteers', 'A group of volunteers ready to work', 'volunteers group smiling', 'users')
t.page('home', 'Home', [
    hero('split', 'Change that lasts, one family at a time', 'Tell people in one sentence who you help, where, and how.',
         ('Donate', '/donate'), ('Our work', '/work'), [h]),
    features('programmes', 'Our programmes', [
        ('heart', 'Feeding', 'Meals for children after school.'), ('star', 'Education', 'Homework clubs and bursaries.'),
        ('users', 'Skills', 'Training that leads to work.')], intro='Replace these with your own programmes.'),
    image_text('programme', 'Where your money goes', 'Explain simply what a donation pays for, and how you report on it.', p1, 'right', ('Donate', '/donate')),
    packages('give', 'Ways to give', [
        ('Once-off', 'Any amount, any time', False, 'Donate', '/donate'),
        ('Monthly', 'Regular giving lets us plan ahead', True, 'Give monthly', '/donate'),
        ('Corporate', 'CSI and employee giving', False, 'Talk to us', '/donate')]),
    logos('partners', 'Our partners and funders'),
    image_text('volunteer', 'Volunteer with us', 'Tell people how they can give their time and skills.', p2, 'left', ('Volunteer', '/volunteer')),
], seo_title='Charity and NGO — programmes, impact and donations')
t.page('work', 'Our work', [
    text('mission', 'Our mission', 'Your mission, where you work, and who you serve.'),
    blk('stories', 'Portfolio', heading='Stories', intro='Real stories, shared with permission.', projects=[]),
    accreditations('Registered and accountable', [('NPO number', 'Add your NPO registration'), ('PBO / Section 18A', 'If donations are tax-deductible'),
                                                  ('Annual report', 'Link to your latest report')]),
    team('Our team and board'),
])
t.page('donate', 'Donate', [
    text('donate', 'Donate', 'Add your banking details or online giving link, and say whether you issue Section 18A tax certificates.'),
    form('interest', 'Corporate and monthly giving', 'Leave your details and we’ll be in touch.',
         [('Organisation (if any)', 'short', '', 'no'), ('How would you like to give?', 'choice', 'Monthly, Once-off, Corporate / CSI', 'yes')]),
])
t.page('volunteer', 'Volunteer', [
    form('interest', 'Volunteer with us', 'Tell us how you’d like to help.',
         [('How would you like to help?', 'choice', 'Weekends, Weekdays, Skills (e.g. accounting, IT), Events', 'yes'), ('Area', 'short', '', 'no')],
         submit='Sign me up'),
    contact('Contact us', 'Partnerships, media and general questions.')])
t.write()

t = T('church-ngo', 'ministry-outreach', 'Ministry & outreach', 3,
      'Bold and energetic: a ministry, youth movement or outreach with events and media.', '#B91C1C', '#1F2937', 'bold', 'round')
t.button = ('Get involved', '/connect')
h = t.img('hero', 'A youth event with worship and lights', 'A youth event or crusade: people worshipping under stage lights', 'youth worship concert Africa', 'mic')
o1 = t.img('outreach', 'Volunteers handing out food parcels', 'An outreach: volunteers handing out food parcels or blankets', 'outreach food parcels volunteers', 'heart-handshake')
t.page('home', 'Home', [
    banner('Youth conference this winter — registration opens soon', 'Find out more', '/events', tone='dark'),
    hero('centered', 'Faith that moves', 'Worship, teaching and outreach that takes the message to the streets.',
         ('Get involved', '/connect'), ('Events', '/events')),
    video('Watch', 'Messages, worship and stories from our outreach.', 'Highlights from our latest event'),
    times('weekly', 'Every week', [('Friday 18:00', 'Youth night'), ('Saturday 09:00', 'Street outreach'), ('Sunday 14:00', 'Worship gathering')]),
    image_text('outreach', 'Out in the community', 'Describe your outreach: where you go, what you do and how people can join.', o1, 'right', ('Join the team', '/connect')),
    team('Leaders'),
    cta('give', 'Fuel the mission', 'Every gift sends us further.', 'Give', '/connect'),
], seo_title='Ministry and outreach — events, media and community')
t.page('events', 'Events', [
    text('events', 'Upcoming events', 'List conferences, camps and outreaches with dates, venues and how to register.'),
    faq('faq', 'Event questions', [('Is there a cost?', 'Say whether events are free or the registration fee.'),
                                   ('Can I bring my youth group?', 'Yes — register as a group so we can plan.')]),
])
t.page('connect', 'Get involved', [
    form('interest', 'Get involved', 'Volunteer, partner or join a team.',
         [('I’d like to', 'choice', 'Volunteer, Join the worship team, Partner or give, Invite you to speak', 'yes')], submit='Send'),
    contact('Contact us', 'Bookings and partnerships.')])
t.write()

# ───────────────────────── School ─────────────────────────

t = T('school', 'school-primary', 'Primary school', 1,
      'Friendly and reassuring for parents: admissions, fees, school day and staff.', '#1D4ED8', '#F59E0B', 'friendly', 'round')
t.button = ('Apply', '/admissions')
h = t.img('hero', 'Learners in a bright classroom', 'Learners working together in a bright classroom', 'African primary school classroom', 'school')
p = t.img('playground', 'Children playing on the school field', 'Children playing sport or games on the school field', 'school children playing field Africa', 'trophy')
r = t.img('reading', 'A teacher reading with learners', 'A teacher reading with a small group of learners', 'teacher reading children', 'book-open')
t.page('home', 'Home', [
    banner('Applications for next year are open', 'Apply now', '/admissions'),
    hero('split', 'Where every child is known by name', 'Small classes, caring teachers and a school day full of learning, sport and the arts.',
         ('Apply', '/admissions'), ('Book a tour', '/admissions'), [h], ['Grade R to Grade 7', 'Aftercare until 17:30']),
    features('why', 'Why parents choose us', [
        ('users', 'Small classes', 'Teachers who know every learner.'), ('star', 'Strong basics', 'Reading, maths and languages from Grade R.'),
        ('heart', 'Safe and caring', 'Secure grounds and a culture of respect.')]),
    image_text('reading', 'Learning that sticks', 'Describe your approach: curriculum (CAPS or other), languages of teaching, reading programmes and support.', r, 'right'),
    image_text('sport', 'Sport, culture and play', 'List extra-murals: soccer, netball, choir, chess, art — and when they run.', p, 'left'),
    steps('apply', 'How to apply', [('Book a tour', 'See the school and meet the principal.'), ('Apply', 'Complete the form and bring documents.'),
                                   ('Confirmation', 'We confirm a place and the start date.')]),
    reviews('What parents say'),
], seo_title='Primary school — admissions, fees and school life')
t.page('about', 'Our school', [
    text('ethos', 'Our school', 'Your history, values and what makes the school special.'),
    team('Our staff', 'Principal, teachers and support staff.'),
    accreditations('Registered', [('Department of Basic Education', 'Add your registration number'), ('Umalusi / ISASA', 'If applicable')]),
])
t.page('admissions', 'Admissions', [
    packages('fees', 'School fees', [('Grade R', 'Per year, payable monthly', False, 'Apply', '/admissions'),
                                     ('Grades 1–3', 'Per year', False, 'Apply', '/admissions'), ('Grades 4–7', 'Per year', False, 'Apply', '/admissions')],
             intro='Ask about sibling discounts and payment plans.'),
    form('waiting_list', 'Apply or book a tour', 'Tell us about your child.',
         [('Child’s name', 'short', '', 'yes'), ('Grade applying for', 'choice', 'Grade R, 1, 2, 3, 4, 5, 6, 7', 'yes'),
          ('Year', 'short', '', 'yes'), ('I’d like to', 'choice', 'Apply, Book a tour', 'yes')], submit='Send'),
    faq('faq', 'Questions parents ask', [('What documents do I need?', 'Birth certificate, immunisation card, last report and proof of address.'),
                                         ('Is there aftercare?', 'Yes, until 17:30 on school days.'), ('Do you offer transport?', 'Say whether you have school transport.')]),
    contact('Contact the school office', 'Office hours 07:30–15:00.'),
])
t.write()

t = T('school', 'school-high', 'High school & college', 2,
      'Confident and achievement-led: subjects, results, campus and applications.', '#7F1D1D', '#CA8A04', 'editorial', 'square')
t.button = ('Apply', '/apply')
h = t.img('hero', 'High school learners on campus', 'High-school learners on campus, confident and smiling', 'African high school students campus', 'graduation-cap')
lab = t.img('lab', 'Learners in a science laboratory', 'Learners in a science lab or computer room', 'students science lab Africa', 'flask-conical')
grad = t.img('matric', 'Matric learners celebrating results', 'Matric learners celebrating results or a prize-giving', 'students celebrating graduation Africa', 'award')
t.page('home', 'Home', [
    hero('photo', 'Ready for what comes next', 'Strong academics, sport and leadership — preparing learners for university, college and work.',
         ('Apply', '/apply'), ('Subjects', '/academics'), [h], ['Grades 8–12', 'Open day every term']),
    text('results', 'Our results', 'Share last year’s matric results: pass rate, bachelor passes and distinctions — and where learners went next.'),
    image_text('lab', 'Facilities', 'Labs, computer centre, library and sports fields — tell parents what learners use every day.', lab, 'left'),
    features('streams', 'Subject streams', [
        ('star', 'Science', 'Maths, physical and life sciences.'), ('users', 'Commerce', 'Accounting, business and economics.'),
        ('sparkles', 'Humanities and arts', 'History, geography, languages and visual arts.')]),
    image_text('matric', 'Celebrating achievement', 'Prize-giving, matric results and alumni — show what learners go on to do.', grad, 'right'),
    cta('openday', 'Come to an open day', 'Meet teachers, see the campus and ask your questions.', 'Book a place', '/apply'),
], seo_title='High school — subjects, results and admissions')
t.page('academics', 'Academics', [
    text('curriculum', 'Curriculum', 'CAPS or IEB, languages, subject choices in Grade 10, and extra support.'),
    blk('timetable', 'ClassTimetable', heading='Extra lessons', intro='Free extra classes for matrics.', note='Times may change in exam season.',
        bookLabel='', bookHref='', classes=[
            {'day': 'Monday', 'start': '14:30', 'end': '15:30', 'name': 'Maths support', 'instructor': '', 'level': 'All levels'},
            {'day': 'Wednesday', 'start': '14:30', 'end': '15:30', 'name': 'Physical sciences', 'instructor': '', 'level': 'All levels'},
            {'day': 'Saturday', 'start': '08:00', 'end': '11:00', 'name': 'Matric revision', 'instructor': '', 'level': 'Advanced'}]),
    team('Heads of department'),
])
t.page('apply', 'Apply', [
    steps('steps', 'Applying', [('Open day', 'Visit and meet us.'), ('Application', 'Submit the form and documents.'),
                                ('Assessment', 'A short assessment and interview.'), ('Offer', 'We confirm a place in writing.')]),
    packages('fees', 'Fees', [('Grades 8–9', 'Per year', False, 'Apply', '/apply'), ('Grades 10–12', 'Per year', False, 'Apply', '/apply')],
             intro='Ask about bursaries and payment plans.'),
    form('waiting_list', 'Apply', 'Tell us about the learner.',
         [('Learner’s name', 'short', '', 'yes'), ('Grade applying for', 'choice', 'Grade 8, 9, 10, 11, 12', 'yes'), ('Current school', 'short', '', 'no')],
         submit='Apply'),
    contact('Admissions office', 'Questions about applying.'),
])
t.write()

t = T('school', 'skills-academy', 'Skills academy & training centre', 3,
      'Career-focused: accredited short courses and learnerships, with intakes and outcomes.', '#0E7490', '#84CC16', 'technical', 'soft')
t.button = ('Enrol', '/enrol')
h = t.img('hero', 'Students in a practical workshop', 'Students in a hands-on workshop (welding, electrical, IT or hospitality)', 'vocational training students Africa', 'wrench')
c = t.img('class', 'A trainer with students at computers', 'A trainer helping students at computers', 'computer training class Africa', 'monitor')
t.page('home', 'Home', [
    hero('split', 'Skills that get you hired', 'Accredited short courses and learnerships — practical, affordable and taught by people from the industry.',
         ('Enrol', '/enrol'), ('Courses', '/courses'), [h], ['Next intake: add the date', 'Certificates on completion']),
    packages('courses', 'Popular courses', [
        ('IT end-user', 'Computer skills for the office — 4 weeks', False, 'Enrol', '/enrol'),
        ('Electrical basics', 'Practical and theory — 3 months', True, 'Enrol', '/enrol'),
        ('Business skills', 'Start and run a small business — 6 weeks', False, 'Enrol', '/enrol')]),
    steps('how', 'How it works', [('Choose a course', 'See dates, fees and requirements.'), ('Enrol', 'Apply online and bring your documents.'),
                                 ('Learn by doing', 'Practical classes with real equipment.'), ('Get certified', 'Assessment and a certificate.')]),
    accreditations('Accredited', [('QCTO', 'Add your accreditation number'), ('SETA', 'Add the SETA and programme')]),
    image_text('class', 'Learn from people who do the work', 'Introduce your trainers and the employers you work with.', c, 'right'),
    logos('employers', 'Where our graduates work'),
], seo_title='Skills academy — accredited courses and learnerships')
t.page('courses', 'Courses', [
    services('courses', 'Courses and fees', [('IT end-user', 'Office computer skills', '4 weeks'), ('Electrical basics', 'Practical and theory', '3 months'),
                                             ('Welding', 'Practical', '8 weeks'), ('Business skills', 'Start a small business', '6 weeks'),
                                             ('Hospitality', 'Food and service', '3 months')], note='Ask about learnerships and bursaries.'),
    faq('faq', 'Questions', [('What do I need to enrol?', 'ID, highest qualification and proof of address.'),
                             ('Can I pay in instalments?', 'Explain your payment options.'), ('Do you help find work?', 'Describe job placement support.')]),
])
t.page('enrol', 'Enrol', [
    form('waiting_list', 'Enrol for the next intake', 'Choose a course and leave your details.',
         [('Course', 'choice', 'IT end-user, Electrical basics, Welding, Business skills, Hospitality', 'yes'),
          ('Highest qualification', 'choice', 'Grade 9, Grade 12, Diploma or degree', 'no')], submit='Enrol'),
    contact('Contact the academy', 'Companies: ask about training your staff.'), directions('Find the campus')])
t.write()

# ───────────────────────── Funeral services ─────────────────────────

t = T('funeral-services', 'funeral-home', 'Funeral home', 1,
      'Calm and dignified: a 24-hour line, what to do first, services and plans.', '#1F2937', '#A16207', 'elegant', 'soft')
t.button = ('Call us, day or night', '/contact')
h = t.img('hero', 'White lilies in soft light', 'Calm, respectful imagery: flowers, candles or the chapel in soft light', 'white lilies soft light', 'flower-2', decorative=True)
ch = t.img('chapel', 'The chapel set for a service', 'The chapel or memorial room set for a service', 'funeral chapel interior', 'landmark')
t.page('home', 'Home', [
    banner('We answer 24 hours a day, every day', 'Contact us', '/contact', tone='dark'),
    hero('photo', 'With care, dignity and respect', 'We look after your loved one and your family — from the first call to the graveside.',
         ('Call us, day or night', '/contact'), ('What to do first', '/first-steps'), [h]),
    steps('first', 'When someone has died', [
        ('Call us', 'Any time, day or night. We come to the home or hospital.'), ('Paperwork', 'We help with the death certificate and Home Affairs.'),
        ('Plan the service', 'Church, home or graveside — your traditions, your way.'), ('The day', 'Transport, tent, chairs and catering if you need them.')]),
    services('services', 'How we help', [
        ('Funeral arrangements', 'Collection, care and preparation', ''), ('Repatriation', 'Bringing loved ones home, across provinces and borders', ''),
        ('Cremation', 'With a memorial service if you wish', ''), ('Tent, chairs and catering', 'For the service and gathering', '')],
        intro='We explain every cost before you decide.'),
    image_text('chapel', 'Our chapel', 'Describe your chapel, mortuary facilities and fleet, so families know their loved one is cared for.', ch, 'right'),
    cta('plans', 'Plan ahead', 'A funeral plan protects your family from unexpected costs.', 'See funeral plans', '/plans'),
], seo_title='Funeral home — 24-hour service, arrangements and funeral plans')
t.page('first-steps', 'What to do first', [
    text('first', 'What to do first', 'Write a calm, simple guide: who to call, documents needed (ID, death notification), and how you help with each step.'),
    faq('faq', 'Questions families ask', [
        ('How quickly can you collect?', 'Usually within a few hours; tell families your response times.'),
        ('What documents are needed?', 'The deceased’s ID, the informant’s ID and the medical death notification (DHA-1663).'),
        ('Can you arrange repatriation?', 'Yes — between provinces and to neighbouring countries.')]),
])
t.page('plans', 'Funeral plans', [
    packages('plans', 'Funeral plans', [
        ('Single', 'Cover for one person', False, 'Ask us', '/contact'), ('Family', 'You, your spouse and children', True, 'Ask us', '/contact'),
        ('Extended family', 'Parents and extended family', False, 'Ask us', '/contact')],
        intro='Tell people who underwrites your plans and the waiting periods.'),
    accreditations('Regulated', [('FSP number', 'Add your licence or your underwriter’s')]),
])
t.page('contact', 'Contact', [contact('Contact us — 24 hours', 'Call or WhatsApp us any time.'), directions('Visit our offices'), hours('Our phone line is answered 24 hours a day.')])
t.write()

t = T('funeral-services', 'funeral-cover-society', 'Funeral cover & burial society', 2,
      'Clear and trustworthy: plans, how to join, how claims work.', '#1E3A8A', '#059669', 'clean', 'round')
t.button = ('Join', '/join')
h = t.img('hero', 'A family together at home', 'A family together at home, warm and calm', 'African family home together', 'users', decorative=False)
t.page('home', 'Home', [
    hero('split', 'Peace of mind for your family', 'Affordable funeral cover with fast claims — so your family can say goodbye without money worries.',
         ('Join', '/join'), ('Plans', '/plans'), [h], ['Claims paid in 48 hours', 'Family cover from one premium']),
    steps('how', 'How it works', [('Choose a plan', 'Single, family or extended.'), ('Join', 'Sign up with your ID; pay monthly.'),
                                 ('Claim', 'One call; we pay quickly so the funeral can go ahead.')]),
    packages('plans', 'Plans', [
        ('Single', 'Cover for you', False, 'Join', '/join'), ('Family', 'You, your spouse and children', True, 'Join', '/join'),
        ('Extended', 'Add parents and extended family', False, 'Join', '/join')]),
    features('why', 'Why families choose us', [
        ('clock', 'Fast claims', 'Paid within two working days of the documents.'), ('shield', 'Regulated', 'Underwritten by a licensed insurer.'),
        ('heart', 'Extra help', 'Tent, chairs and grocery benefits on some plans.')]),
    faq('faq', 'Questions', [
        ('Is there a waiting period?', 'State your waiting periods for natural and accidental death.'),
        ('Which documents are needed to claim?', 'Death certificate, ID of the deceased and the claimant, and bank details.'),
        ('What if I miss a payment?', 'Explain your grace period.')]),
], seo_title='Funeral cover and burial society — plans and claims')
t.page('plans', 'Plans', [
    packages('plans', 'Plans and benefits', [
        ('Single', 'Cover for one person', False, 'Join', '/join'), ('Family', 'Spouse and children', True, 'Join', '/join'),
        ('Extended', 'Parents and extended family', False, 'Join', '/join')]),
    accreditations('Regulated and registered', [('FSP number', 'Add your licence'), ('Underwriter', 'Name the insurer')]),
    text('terms', 'Important', 'Waiting periods, age limits and exclusions — written plainly. Link to your full policy terms.'),
])
t.page('join', 'Join', [
    form('interest', 'Join or get a call back', 'Leave your details and an agent will call you.',
         [('Plan', 'choice', 'Single, Family, Extended', 'yes'), ('Best time to call', 'choice', 'Morning, Afternoon, Evening', 'no')],
         submit='Call me back'),
    contact('Contact us', 'Claims line and branch details.')])
t.write()

t = T('funeral-services', 'memorials-tombstones', 'Tombstones & memorials', 3,
      'Respectful and crafted: tombstones, unveilings and memorial work, with quotes.', '#44403C', '#B45309', 'classic', 'square')
t.button = ('Ask for a quote', '/quote')
h = t.img('hero', 'A granite headstone in a garden of remembrance', 'A finished granite headstone in a peaceful setting', 'granite headstone memorial', 'landmark', decorative=False)
s1 = t.img('design-1', 'A black granite headstone with gold lettering', 'A black granite headstone with gold lettering', 'black granite headstone', 'landmark')
s2 = t.img('design-2', 'A double headstone for a couple', 'A double headstone', 'double headstone granite', 'landmark')
s3 = t.img('workshop', 'Engraving in the workshop', 'Lettering being engraved in the workshop', 'stone engraving workshop', 'pen-tool')
t.page('home', 'Home', [
    hero('split', 'A lasting tribute', 'Tombstones and memorials made with care — designed with your family and installed in time for the unveiling.',
         ('Ask for a quote', '/quote'), ('Designs', '/designs'), [h]),
    steps('process', 'How we work', [('Choose a design', 'From our catalogue, or your own.'), ('Approve the wording', 'We send a proof to check.'),
                                     ('We make it', 'Cut, polished and engraved in our workshop.'), ('Installation', 'Delivered and installed before the unveiling.')]),
    gallery('designs', 'Designs', [s1, s2, s3]),
    services('services', 'What we make', [
        ('Single headstone', 'Granite, with engraving', '4–6 weeks'), ('Double headstone', 'For a couple', '6–8 weeks'),
        ('Full kerb set', 'Headstone and kerbing', '6–8 weeks'), ('Restoration', 'Cleaning and re-lettering', '2 weeks')]),
    reviews('Families we’ve helped'),
], seo_title='Tombstones and memorials — designs, quotes and installation')
t.page('designs', 'Designs', [gallery('designs', 'Designs', [s1, s2, s3], 'Every design can be changed in size, stone and wording.')])
t.page('quote', 'Quote', [
    quote('Ask for a quote', 'Tell us what you have in mind; we’ll send a quote and a drawing.', ['Single headstone', 'Double headstone', 'Kerb set', 'Restoration'],
          [('Cemetery', 'short', '', 'yes'), ('Unveiling date', 'date', '', 'no'), ('Photo of a design you like', 'file', '', 'no')], submit='Ask for a quote'),
    contact('Visit the showroom', 'See stone colours and finishes.'), directions('Find the showroom')])
t.write()

# ───────────────────────── Security ─────────────────────────

t = T('security', 'security-armed-response', 'Armed response', 1,
      'Strong and reassuring: response times, coverage, monitoring and sign-up.', '#111827', '#DC2626', 'bold', 'square')
t.button = ('Get protected', '/quote')
h = t.img('hero', 'An armed response vehicle on patrol at night', 'A branded response vehicle on patrol', 'security patrol vehicle night', 'shield-check')
ctrl = t.img('control', 'Operators in a control room', 'Operators monitoring screens in a control room', 'security control room operators', 'monitor')
t.page('home', 'Home', [
    hero('photo', 'When the alarm goes, we’re on our way', 'Armed response, 24-hour monitoring and patrols for homes and businesses.',
         ('Get protected', '/quote'), ('Areas we cover', '/coverage'), [h], ['24-hour control room', 'PSIRA registered']),
    steps('alarm', 'When your alarm goes off', [('We see it', 'The control room receives the signal instantly.'),
                                                ('We call you', 'To check if it’s a false alarm.'),
                                                ('We respond', 'An armed officer is dispatched to your property.')]),
    features('services', 'Services', [
        ('shield', 'Armed response', 'Officers dispatched the moment your alarm triggers.'),
        ('clock', 'Monitoring', 'Alarms, panic buttons and cameras watched 24/7.'),
        ('pin', 'Patrols', 'Regular, visible patrols in your area.')]),
    image_text('control', 'Our control room', 'Describe your control room, staff and backup power, so clients know who is watching.', ctrl, 'right'),
    accreditations('Registered and compliant', [('PSIRA', 'Add your registration number'), ('Insurance', 'Public liability cover')]),
    cta('cta', 'Sign up today', 'Ask about installation and monthly fees.', 'Get a quote', '/quote'),
], seo_title='Armed response and alarm monitoring')
t.page('coverage', 'Coverage', [areas('Areas we cover', note='Not covered yet? Ask us — we may be expanding.'),
                                 packages('plans', 'Plans', [('Home', 'Monitoring and response', True, 'Get a quote', '/quote'),
                                                              ('Business', 'Monitoring, response and patrols', False, 'Get a quote', '/quote')])])
t.page('quote', 'Get a quote', [
    quote('Get a quote', 'Tell us about the property.', ['Home', 'Business', 'Estate or complex', 'Farm'],
          [('Suburb / area', 'short', '', 'yes'), ('Existing alarm?', 'yesno', '', 'no')], submit='Get my quote'),
    contact('Contact us', 'Emergencies: use your panic button or call the control room.')])
t.write()

t = T('security', 'security-guarding', 'Guarding services', 2,
      'Corporate and disciplined: guarding for sites, events and estates, with clear contracts.', '#1E3A8A', '#F59E0B', 'modern', 'soft')
t.button = ('Request a risk assessment', '/quote')
h = t.img('hero', 'A uniformed security officer at a site entrance', 'A uniformed guard at an access point', 'security guard uniform Africa', 'shield-check')
ev = t.img('events', 'Security staff at an event', 'Security staff managing an event crowd', 'event security staff', 'users')
t.page('home', 'Home', [
    hero('split', 'Trained officers. Accountable service.', 'Guarding for offices, construction sites, estates, schools and events — supervised around the clock.',
         ('Request a risk assessment', '/quote'), ('Services', '/services'), [h]),
    features('sectors', 'Who we protect', [
        ('star', 'Commercial', 'Offices, retail and warehouses.'), ('pin', 'Construction', 'Sites, plant and materials.'),
        ('users', 'Events', 'Access control and crowd management.')]),
    steps('how', 'How we start', [('Risk assessment', 'We visit and assess the site.'), ('Proposal', 'Officers, shifts and costs in writing.'),
                                 ('Deployment', 'Officers briefed and supervised from day one.')]),
    image_text('events', 'Events and crowds', 'Describe your event security: access control, crowd management and first aid.', ev, 'left'),
    accreditations('Compliance', [('PSIRA', 'Company and officers registered'), ('COIDA & UIF', 'All staff registered'), ('B-BBEE', 'Add your level')]),
    logos('clients', 'Clients'),
], seo_title='Security guarding for sites, estates and events')
t.page('services', 'Services', [services('services', 'Services', [
    ('Static guarding', 'Day and night shifts', ''), ('Access control', 'Gates, visitors and vehicles', ''), ('Patrols', 'On foot or vehicle', ''),
    ('Event security', 'Per event', ''), ('Supervision', 'Site checks and reports', '')], note='Quoted per site after a risk assessment.'),
    faq('faq', 'Questions', [('Are officers armed?', 'Say which grades and whether armed officers are available.'),
                             ('How do you supervise?', 'Explain patrol checks, reports and supervisors.')])])
t.page('quote', 'Risk assessment', [
    quote('Request a risk assessment', 'Tell us about the site.', ['Office', 'Construction', 'Estate', 'School', 'Event'],
          [('Company', 'short', '', 'yes'), ('Site address', 'short', '', 'yes'), ('Officers needed (if known)', 'short', '', 'no')], submit='Request'),
    contact('Contact us', 'Our operations desk.')])
t.write()

t = T('security', 'security-installations', 'Alarms, CCTV & fencing', 3,
      'Technical and tidy: alarms, cameras, electric fencing and gate motors, installed and serviced.', '#155E75', '#F97316', 'technical', 'soft')
t.button = ('Free site quote', '/quote')
h = t.img('hero', 'A technician installing a CCTV camera', 'A technician installing a security camera', 'technician installing CCTV camera', 'cctv')
f = t.img('fence', 'Electric fencing on a boundary wall', 'Electric fencing installed on a boundary wall', 'electric fence wall', 'zap')
g = t.img('app', 'Camera view on a phone', 'Live camera view on a phone app', 'CCTV phone app view', 'smartphone')
t.page('home', 'Home', [
    hero('collage', 'See it. Secure it.', 'Alarms, CCTV, electric fencing and gate motors — installed neatly, explained clearly, serviced when you need us.',
         ('Free site quote', '/quote'), ('What we install', '/services'), [h, f, g]),
    services('services', 'What we install', [
        ('CCTV', 'HD cameras with phone viewing', '1–2 days'), ('Alarms', 'Wireless or wired, with panic buttons', '1 day'),
        ('Electric fencing', 'Compliant installations with certificates', '1–3 days'), ('Gate motors', 'Supply, install and repair', '1 day'),
        ('Intercoms & access', 'Video intercoms and keypads', '1 day')]),
    before_after('Our installations', 'Neat cabling and tidy finishes.', 'side-by-side'),
    blk('projects', 'Portfolio', heading='Recent projects', intro='Homes, shops and estates.', projects=[]),
    features('why', 'Why us', [('check', 'Certified installers', 'Electric fence certificates issued.'),
                               ('clock', 'Fast call-outs', 'Repairs and services when you need them.'),
                               ('shield', 'Warranty', 'On parts and workmanship.')]),
    areas('Areas we cover'),
], seo_title='Alarms, CCTV, electric fencing and gate motors')
t.page('services', 'Services', [
    services('services', 'Prices', [('4-camera CCTV kit', 'Installed', ''), ('8-camera CCTV kit', 'Installed', ''), ('Alarm system', 'Installed', ''),
                                    ('Electric fencing', 'Per metre', ''), ('Gate motor', 'Supply and install', ''), ('Call-out', 'Repairs', '')],
             note='We quote after a free site visit.'),
    faq('faq', 'Questions', [('Do I get an electric fence certificate?', 'Yes, on every compliant installation.'),
                             ('Can I see cameras on my phone?', 'Yes, we set up the app and show you how.')])])
t.page('quote', 'Free site quote', [
    quote('Free site quote', 'Tell us what you need.', ['CCTV', 'Alarm', 'Electric fencing', 'Gate motor', 'Intercom'],
          [('Address / area', 'short', '', 'yes'), ('Photos (optional)', 'file', '', 'no')], submit='Book a site visit'),
    contact('Contact us', 'Service and repairs: WhatsApp us.')])
t.write()

write_slots()
print('ok')
