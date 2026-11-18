"""Sprint 20.4d: real estate (with the property listings section) and legal & accounting — three designs each."""
from lib import *


def form(purpose, heading, intro, questions=(), submit='Send', details='Anything else?'):
    return blk('form', 'Form', purpose=purpose, heading=heading, intro=intro, detailsLabel=details, submitLabel=submit,
               leads='yes', askNews='yes', questions=[{'label': l, 'type': t, 'choices': c, 'required': r} for l, t, c, r in questions])


def listings(t, id, heading, items, intro='', button=('', '')):
    """Demo listings: real-looking cards whose photos are demo photos (the launch checklist asks the owner to replace them)."""
    return blk(id, 'Listings', heading=heading, intro=intro, buttonLabel=button[0], buttonHref=button[1], listings=[
        {'title': title, 'photo': photo['src'], 'photoAlt': photo['alt'], 'deal': deal, 'price': 0, 'area': '', 'beds': beds,
         'baths': baths, 'parking': parking, 'size': size, 'status': status, 'detailsHref': '/contact'}
        for title, photo, deal, beds, baths, parking, size, status in items])


# ───────────────────────── Real estate ─────────────────────────

t = T('real-estate', 'estate-agency', 'Estate agency', 1,
      'Polished and local: featured homes, a free valuation and the agents who know the area.', '#0F172A', '#B45309', 'elegant', 'square')
t.button = ('Free valuation', '/valuation')
h = t.img('hero', 'A modern family home at dusk', 'A beautiful family home exterior in good light', 'modern house exterior South Africa', 'house')
p1 = t.img('home-1', 'A family home with a garden', 'A family home with a garden', 'family home garden', 'house')
p2 = t.img('home-2', 'A modern apartment living room', 'A bright apartment living room', 'apartment living room modern', 'sofa')
p3 = t.img('home-3', 'A townhouse in a secure estate', 'A townhouse in a secure complex or estate', 'townhouse complex', 'building')
agent = t.img('agent', 'An estate agent with clients', 'An agent showing clients around a home', 'Black estate agent showing house', 'users')
t.page('home', 'Home', [
    hero('photo', 'Find home. Sell well.', 'Homes for sale and to rent in your area, with agents who know every street — and a free valuation when you’re ready to sell.',
         ('Free valuation', '/valuation'), ('View properties', '/properties'), [h]),
    listings(t, 'featured', 'Featured properties', [
        ('Four-bedroom family home with a garden and pool', p1, 'sale', '4', '3', '2', '320', 'available'),
        ('Two-bedroom apartment close to shops and transport', p2, 'rent', '2', '1', '1', '78', 'available'),
        ('Three-bedroom townhouse in a secure estate', p3, 'sale', '3', '2', '2', '160', 'under-offer')],
        button=('All properties', '/properties')),
    steps('sell', 'Selling with us', [('Free valuation', 'A realistic price, based on recent sales nearby.'),
                                     ('Marketing', 'Photos, portals, social media and show days.'),
                                     ('Sold', 'We handle offers, bond approvals and transfer with you.')]),
    image_text('agents', 'Agents who know the area', 'Introduce your agents, the areas they cover and the homes they’ve sold.', agent, 'right', ('Meet the team', '/about')),
    reviews('What buyers and sellers say'),
    cta('cta', 'Thinking of selling?', 'Get a free, no-obligation valuation.', 'Book a valuation', '/valuation'),
], seo_title='Estate agency — homes for sale and to rent')
t.page('properties', 'Properties', [
    listings(t, 'all', 'Properties', [
        ('Four-bedroom family home with a garden and pool', p1, 'sale', '4', '3', '2', '320', 'available'),
        ('Two-bedroom apartment close to shops and transport', p2, 'rent', '2', '1', '1', '78', 'available'),
        ('Three-bedroom townhouse in a secure estate', p3, 'sale', '3', '2', '2', '160', 'under-offer')],
        intro='Ask us about homes that aren’t listed yet.'),
    form('interest', 'Tell us what you’re looking for', 'We’ll let you know when something matches.',
         [('Buy or rent?', 'choice', 'Buy, Rent', 'yes'), ('Area', 'short', '', 'yes'), ('Budget', 'short', '', 'no'), ('Bedrooms', 'choice', '1, 2, 3, 4 or more', 'no')],
         submit='Alert me'),
])
t.page('about', 'About us', [
    text('story', 'About us', 'Your agency’s story, the areas you cover and how you work.'),
    team('Our agents'),
    accreditations('Registered', [('PPRA', 'Fidelity Fund Certificates for every agent'), ('FICA', 'Compliant')]),
])
t.page('valuation', 'Valuation', [
    quote('Book a free valuation', 'Tell us about your property.', ['House', 'Townhouse', 'Apartment', 'Plot', 'Farm or smallholding'],
          [('Address or suburb', 'short', '', 'yes'), ('Bedrooms', 'choice', '1, 2, 3, 4, 5 or more', 'no'), ('When do you want to sell?', 'choice', 'Now, In 3 months, Just curious', 'no')],
          submit='Book my valuation'),
    contact('Visit the office', 'Or call an agent directly.'), directions('Find our office')])
t.write()

t = T('real-estate', 'property-development', 'New development', 2,
      'Aspirational and clear: one development, its units, the steps to buy and registration of interest.', '#1E3A8A', '#10B981', 'modern', 'soft')
t.button = ('Register interest', '/register')
h = t.img('hero', 'An artist’s impression of the new development', 'An artist’s impression or photo of the development', 'new apartment development render', 'building-2')
u1 = t.img('unit-1', 'A one-bedroom show unit', 'A show unit: one-bedroom living area', 'show apartment interior', 'sofa')
u2 = t.img('unit-2', 'A two-bedroom show unit', 'A show unit: kitchen or bedroom', 'new apartment kitchen', 'sofa')
site = t.img('site', 'Construction on site', 'The site under construction, or the finished clubhouse', 'apartment construction site', 'hard-hat')
t.page('home', 'Home', [
    hero('split', 'Your first home, brand new', 'Modern one- and two-bedroom homes, close to schools, transport and shops. Transfer duty included on selected units.',
         ('Register interest', '/register'), ('See the units', '/units'), [h], ['Phase 1 launching soon', 'From first-time-buyer prices']),
    features('why', 'Why buy here', [
        ('shield', 'Secure', 'Controlled access and 24-hour security.'), ('pin', 'Connected', 'Near taxi routes, schools and a mall.'),
        ('star', 'New', 'Energy-saving geysers and prepaid meters.')]),
    listings(t, 'units', 'Unit types', [
        ('One-bedroom apartment', u1, 'sale', '1', '1', '1', '45', 'available'),
        ('Two-bedroom apartment', u2, 'sale', '2', '1', '1', '62', 'available')]),
    steps('buy', 'How to buy', [('Register interest', 'We send prices and plans.'), ('Get pre-approved', 'We help with bond applications.'),
                               ('Sign and reserve', 'Pay a reservation deposit.'), ('Move in', 'Transfer and keys on completion.')]),
    video('Take a tour', 'Walk through the show unit.', 'A video tour of the show unit'),
    image_text('progress', 'Building progress', 'Share construction updates and the expected completion date.', site, 'left'),
    faq('faq', 'Questions', [('Do I pay transfer duty?', 'Say which units include transfer costs.'),
                             ('Can I buy with a housing subsidy?', 'Explain FLISP or other support if it applies.'),
                             ('Are levies included?', 'State the estimated monthly levies.')]),
], seo_title='New development — apartments for first-time buyers')
t.page('units', 'Units', [
    listings(t, 'units', 'Unit types and prices', [
        ('One-bedroom apartment', u1, 'sale', '1', '1', '1', '45', 'available'),
        ('Two-bedroom apartment', u2, 'sale', '2', '1', '1', '62', 'available')], intro='Prices on request; plans sent when you register.'),
    gallery('gallery', 'Show unit', [u1, u2, site]),
])
t.page('register', 'Register interest', [
    form('interest', 'Register your interest', 'Be first to hear about prices and launch dates.',
         [('Unit type', 'choice', 'One-bedroom, Two-bedroom', 'yes'), ('Pre-approved for a bond?', 'yesno', '', 'no')], submit='Register'),
    contact('Sales office', 'Visit the show unit on weekends.'), directions('Find the development')])
t.write()

t = T('real-estate', 'rentals-management', 'Rentals & property management', 3,
      'Practical and trusted: homes to rent for tenants, and management for landlords.', '#065F46', '#F59E0B', 'clean', 'round')
t.button = ('Landlords', '/landlords')
h = t.img('hero', 'Keys being handed over at a front door', 'Keys being handed over to a happy tenant at a door', 'handing over house keys', 'key')
r1 = t.img('rental-1', 'A flat to rent', 'A tidy flat to rent', 'apartment for rent interior', 'building')
r2 = t.img('rental-2', 'A cottage to rent', 'A garden cottage or backroom to rent', 'garden cottage', 'house')
t.page('home', 'Home', [
    hero('split', 'Rentals made simple', 'Tenants find good homes. Landlords get rent on time and properties looked after.',
         ('Find a rental', '/rentals'), ('For landlords', '/landlords'), [h]),
    listings(t, 'rentals', 'Available to rent', [
        ('Two-bedroom flat, close to transport', r1, 'rent', '2', '1', '1', '65', 'available'),
        ('Garden cottage, own entrance', r2, 'rent', '1', '1', '1', '40', 'available')], button=('All rentals', '/rentals')),
    packages('landlords', 'For landlords', [
        ('Tenant placement', 'We find and vet the tenant; you manage after that', False, 'Ask us', '/landlords'),
        ('Full management', 'Rent collection, inspections, repairs and reports', True, 'Ask us', '/landlords')]),
    steps('tenants', 'Renting through us', [('Find a home', 'Browse and book a viewing.'), ('Apply', 'Payslips, ID and bank statements.'),
                                           ('Move in', 'Sign the lease, pay the deposit, collect the keys.')]),
    accreditations('Registered', [('PPRA', 'Fidelity Fund Certificate'), ('Trust account', 'Deposits held in a trust account')]),
], seo_title='Rentals and property management')
t.page('rentals', 'Rentals', [
    listings(t, 'rentals', 'Rentals', [
        ('Two-bedroom flat, close to transport', r1, 'rent', '2', '1', '1', '65', 'available'),
        ('Garden cottage, own entrance', r2, 'rent', '1', '1', '1', '40', 'available')]),
    form('waiting_list', 'Apply or book a viewing', 'Tell us which home and when you’d like to move.',
         [('Which property?', 'short', '', 'yes'), ('Move-in date', 'date', '', 'no'), ('Monthly income', 'choice', 'Under R10 000, R10 000–R20 000, Over R20 000', 'no')],
         submit='Send'),
])
t.page('landlords', 'Landlords', [
    packages('services', 'Landlord services', [
        ('Tenant placement', 'Advertising, viewings, vetting and lease', False, 'Ask us', '/landlords'),
        ('Full management', 'Everything, every month', True, 'Ask us', '/landlords')]),
    quote('List your property', 'Tell us about your property.', ['Tenant placement', 'Full management'],
          [('Address or suburb', 'short', '', 'yes'), ('Property type', 'choice', 'House, Flat, Cottage, Room, Commercial', 'yes')], submit='Send'),
    faq('faq', 'Questions landlords ask', [('How do you vet tenants?', 'Credit checks, references and affordability.'),
                                           ('Where is the deposit kept?', 'In an interest-bearing trust account, as the Rental Housing Act requires.')]),
    contact('Contact us', 'Maintenance emergencies: use the number on your lease.')])
t.write()

# ───────────────────────── Legal & accounting ─────────────────────────

t = T('legal-accounting', 'law-firm', 'Law firm', 1,
      'Authoritative and approachable: practice areas, attorneys and a first consultation.', '#1C1917', '#B45309', 'classic', 'square')
t.button = ('Book a consultation', '/consult')
h = t.img('hero', 'An attorney meeting a client', 'An attorney meeting a client across a desk', 'Black lawyer client meeting office', 'scale')
office = t.img('office', 'The firm’s boardroom', 'The firm’s office or boardroom', 'law office boardroom', 'landmark')
t.page('home', 'Home', [
    hero('split', 'Clear advice. Strong representation.', 'We help people and businesses with family, property, labour and commercial matters — in plain language.',
         ('Book a consultation', '/consult'), ('Practice areas', '/practice'), [h]),
    features('areas', 'Practice areas', [
        ('users', 'Family law', 'Divorce, maintenance and custody.'), ('star', 'Property & conveyancing', 'Transfers and bonds.'),
        ('shield', 'Labour law', 'CCMA, disputes and contracts.'), ('check', 'Wills & estates', 'Wills, trusts and deceased estates.'),
        ('pin', 'Commercial', 'Contracts and company matters.'), ('clock', 'Litigation', 'Civil claims and debt collection.')]),
    steps('how', 'Working with us', [('First consultation', 'Tell us what happened; we explain your options.'),
                                    ('Clear fees', 'We tell you how we charge before we start.'), ('Action', 'We act for you and keep you informed.')]),
    image_text('firm', 'About the firm', 'Your history, values and the communities you serve. Mention languages spoken.', office, 'right', ('Our attorneys', '/attorneys')),
    text('disclaimer', 'Please note', 'Information on this website is general and is not legal advice. Contact us about your own situation.'),
], seo_title='Attorneys — family, property, labour and commercial law')
t.page('practice', 'Practice areas', [
    services('practice', 'How we can help', [
        ('Divorce and family', 'Settlement agreements, maintenance, custody', ''), ('Conveyancing', 'Transfers, bonds, sectional title', ''),
        ('Labour', 'CCMA, disciplinary hearings, contracts', ''), ('Wills and estates', 'Wills, trusts, deceased estates', ''),
        ('Commercial', 'Contracts, shareholder agreements', ''), ('Litigation', 'Civil claims, debt collection', '')],
        note='Fees depend on the matter; we confirm them in writing before we start.'),
    faq('faq', 'Questions', [('Is the first consultation free?', 'State your first-consultation fee or policy.'),
                             ('Do you do legal aid or contingency work?', 'Explain which matters you take on contingency, if any.')]),
])
t.page('attorneys', 'Attorneys', [
    team('Our attorneys', 'Names, admission and what each attorney does.'),
    accreditations('Admitted and regulated', [('Legal Practice Council', 'All attorneys admitted and in good standing'),
                                              ('Fidelity Fund', 'Certificate held')]),
])
t.page('consult', 'Consultation', [
    form('enquiry', 'Book a consultation', 'Tell us briefly what it’s about. Everything you share is confidential.',
         [('Type of matter', 'choice', 'Family, Property, Labour, Wills & estates, Commercial, Litigation, Other', 'yes'),
          ('Preferred language', 'short', '', 'no')], submit='Request a consultation'),
    contact('Our offices', 'Office hours: Monday to Friday, 08:00–17:00.'), directions('Find us')])
t.write()

t = T('legal-accounting', 'accounting-practice', 'Accounting & tax practice', 2,
      'Calm and organised: monthly accounting, tax and payroll, with packages and SARS registration.', '#0C4A6E', '#16A34A', 'modern', 'soft')
t.button = ('Get a quote', '/quote')
h = t.img('hero', 'An accountant reviewing reports with a business owner', 'An accountant reviewing figures with a small-business owner', 'Black accountant business owner meeting', 'calculator')
desk = t.img('desk', 'A tidy desk with a laptop and reports', 'A tidy desk with a laptop showing financial reports', 'accounting desk laptop reports', 'bar-chart-3', decorative=True)
t.page('home', 'Home', [
    hero('split', 'Your books, done. Your tax, sorted.', 'Monthly accounting, tax returns, payroll and advice for small businesses and individuals.',
         ('Get a quote', '/quote'), ('Packages', '/packages'), [h], ['Registered tax practitioners', 'Fixed monthly fees']),
    packages('packages', 'Monthly packages', [
        ('Starter', 'Bookkeeping, VAT and an annual return for small businesses', False, 'Get a quote', '/quote'),
        ('Growth', 'Monthly management accounts, payroll and tax planning', True, 'Get a quote', '/quote'),
        ('Individuals', 'Personal tax returns and SARS queries', False, 'Get a quote', '/quote')]),
    features('services', 'What we do', [
        ('check', 'Accounting', 'Monthly books and annual financial statements.'), ('calendar', 'Tax', 'Income tax, VAT, PAYE and provisional tax.'),
        ('users', 'Payroll', 'Payslips, UIF, SDL and EMP201s.')]),
    steps('switch', 'Switching to us', [('Talk to us', 'A free first meeting.'), ('Hand over', 'We collect your records from your old accountant.'),
                                       ('Relax', 'Deadlines and returns handled, every month.')]),
    image_text('desk', 'Reports you understand', 'Explain the software you use and how clients see their numbers each month.', desk, 'right'),
    accreditations('Registered', [('SAIPA / SAICA', 'Add your professional body'), ('SARS', 'Registered tax practitioners')]),
], seo_title='Accounting and tax practice for small businesses')
t.page('packages', 'Packages', [
    packages('packages', 'Packages', [
        ('Starter', 'Bookkeeping, VAT, annual return', False, 'Get a quote', '/quote'),
        ('Growth', 'Management accounts, payroll, tax planning', True, 'Get a quote', '/quote'),
        ('Individuals', 'Personal tax', False, 'Get a quote', '/quote')], intro='Fixed monthly fees, agreed up front.'),
    services('once-off', 'Once-off services', [('Company registration', 'CIPC', '1–2 weeks'), ('Tax clearance (TCS)', 'SARS', '1 week'),
                                                ('B-BBEE affidavit', 'EME and QSE', '2 days'), ('Annual financial statements', '', '2–4 weeks')]),
    faq('faq', 'Questions', [('Can you sort out my SARS problems?', 'Yes — overdue returns, penalties and payment arrangements.'),
                             ('Do you work with clients outside our town?', 'Yes, online.')]),
])
t.page('quote', 'Get a quote', [
    quote('Get a quote', 'Tell us about your business.', ['Bookkeeping', 'Tax', 'Payroll', 'Company registration', 'Personal tax'],
          [('Business type', 'choice', 'Sole proprietor, Company, NPO, Individual', 'yes'), ('Employees', 'short', '', 'no'),
           ('VAT registered?', 'yesno', '', 'no')], submit='Get my quote'),
    contact('Contact us', 'Office hours: Monday to Friday, 08:00–17:00.')])
t.write()

t = T('legal-accounting', 'business-services', 'Business registration & compliance', 3,
      'Fast and friendly: company registration, B-BBEE, tax and compliance for new businesses.', '#6D28D9', '#F59E0B', 'friendly', 'round')
t.button = ('Start my business', '/start')
h = t.img('hero', 'A young entrepreneur signing documents', 'A young entrepreneur at a desk completing documents', 'young African entrepreneur paperwork', 'file-check')
t.page('home', 'Home', [
    hero('centered', 'Register your business this week', 'Company registration, tax numbers, B-BBEE certificates and bank-ready documents — done for you, explained simply.',
         ('Start my business', '/start'), ('Prices', '/prices')),
    steps('how', 'How it works', [('Tell us about your business', 'Name ideas, directors and what you’ll do.'), ('We register', 'CIPC, SARS and the paperwork.'),
                                 ('You’re ready', 'Documents to open a bank account and tender.')]),
    services('services', 'Popular services', [
        ('Company registration', 'CIPC, with share certificates', '1–5 days'), ('Name reservation', 'Up to four names', '1–2 days'),
        ('B-BBEE affidavit', 'EME and QSE', 'Same day'), ('Tax and VAT registration', 'SARS', '1–2 weeks'),
        ('CSD registration', 'Central Supplier Database, for tenders', '1–2 days'), ('Annual returns', 'CIPC, to stay compliant', '1 day')]),
    image_text('done-for-you', 'Done for you, explained simply', 'Introduce the people who help: what they’ve registered, the languages they speak, and how quickly they answer.', h, 'right', ('Start my business', '/start')),
    packages('bundles', 'Bundles', [
        ('Starter', 'Company registration, tax number and B-BBEE affidavit', True, 'Start', '/start'),
        ('Tender-ready', 'Starter, plus CSD, tax clearance and a company profile', False, 'Start', '/start')]),
    faq('faq', 'Questions', [('Can I do this from home?', 'Yes — send documents on WhatsApp or email.'),
                             ('What do I need?', 'Certified IDs of directors and proof of address.'), ('How fast is it?', 'Most companies are registered within a week.')]),
    reviews('What business owners say'),
], seo_title='Company registration, B-BBEE and compliance services')
t.page('prices', 'Prices', [services('services', 'Prices', [
    ('Company registration', '', '1–5 days'), ('Name reservation', '', '1–2 days'), ('B-BBEE affidavit', '', 'Same day'),
    ('Tax and VAT registration', '', '1–2 weeks'), ('CSD registration', '', '1–2 days'), ('Annual returns', '', '1 day')],
    note='Government fees are included unless we say otherwise.')])
t.page('start', 'Start', [
    form('enquiry', 'Start my business', 'Tell us what you need; we’ll call you to get going.',
         [('What do you need?', 'choice', 'Register a company, B-BBEE, Tax, Tender-ready bundle, Not sure', 'yes'),
          ('Name ideas (optional)', 'short', '', 'no')], submit='Get started'),
    contact('Contact us', 'WhatsApp is quickest.')])
t.write()

write_slots()
print('ok')
