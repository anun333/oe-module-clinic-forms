# Clinic Forms for OpenEMR

Printable clinic paperwork, with the clinic's own name, address, phone, fax and logo filled in. Each piece can be printed blank to fill in by hand, or with the current patient's details filled in.

**Status: 0.41.0, a pre-release.** Checked on OpenEMR 7.0.2 to 8.5.0, as an administrator, with synthetic data (see [Compatibility](#compatibility)). The consents and releases are starting points: have them reviewed for your state before you use them (see [The documents are starting points](#the-documents-are-starting-points)).

Using the module day to day, task by task for each role: see [GUIDE.md](GUIDE.md).

It does three things:

- **Paperwork.** Consents, releases, referral letters and office slips, filled from the facility record and, optionally, the open patient and an Address Book entry.
- **Paper versions of layout forms.** Demographics, History, any active encounter form (LBF) and any transaction form (Referral and the like), printed as a blank form with tick boxes, date boxes and ruled lines. With the US pack, a short **UDS form** prints only the Demographics fields health center reporting reads (sex, gender identity and sexual orientation, race, ethnicity, language, household size and income, housing, agricultural work, military service), with the site's own answer lists. Clinics without a computer in every room can do intake on paper and type it in later, in the same order.
- **Ready-made packets.** New patient intake, annual update, telehealth, referral, after the visit, a procedure visit, a chronic care visit (home logs and a medicine review), an asthma visit, and a downtime kit (in the US pack with the superbill and visit summary). The screening packets are labelled by when they are used:
  - **every adult, yearly:** the yearly adult screen, the short first stage of each screen (PHQ-4 and TAPS-1, and in the US pack the Hunger Vital Sign);
  - **after a positive screen:** depression (the PHQ-9, then the ASQ), anxiety (the GAD-7), PTSD (the PCL-5), substance use (TAPS-1, then TAPS-2) and, in the US pack, food (the six-item survey); and a positive-screen handoff for staff;
  - **at 65 and over:** the older adult add-on (falls and depression), and in the US pack the Medicare Annual Wellness Visit (health risk assessment, PHQ-4, Stay Independent, TAPS-1 and the preventive care questions);
  - **for adolescents:** teen screening (NIDA's BSTAD for tobacco, alcohol and drugs, and the ASQ for suicide risk);
  - **for a visit about it:** depression and anxiety; depression and alcohol; behavioral health, which adds PTSD and substance use; starting a controlled medicine; relationship safety (HARK, given in private); and, in the US pack, pregnancy and postpartum, and a vaccine visit;
  - **in the US pack:** who we serve (the UDS questions and the sliding fee), preventive care, care management enrollment, uninsured or self-pay (the good faith estimate and the sliding fee), Medicare other insurance, notices to post in the office, language help, records from another office, and patient requests (blank forms to stock the front desk).

  Each packet opens as one document to print, and says what it is for when you point at its button.
- **Spanish.** 36 of the 70 patient documents print in Spanish too, and the rest in English, with the language assistance notices Section 1557 requires (see [Spanish and language assistance](#spanish-and-language-assistance)).
- **Word and LibreOffice.** Any document downloads as a filled Word (.docx) or LibreOffice (.odt) file to edit before printing, and a clinic can write its own letters and forms in Word or LibreOffice and have them filled in (see [Word and LibreOffice](#word-and-libreoffice)).
- **A PDF, and a copy in the chart.** The ticked documents download as one PDF, or are saved, filled for the patient, as a PDF in the patient's **Documents** (see [The chart number on every page, and a copy in the chart](#the-chart-number-on-every-page-and-a-copy-in-the-chart)).

It changes nothing in the database except when staff save a PDF copy to a patient's Documents, which goes through OpenEMR's own document storage. Every page is built when it's opened, from the site's own facility, Address Book and layout settings.

## Using it

**Miscellaneous › Clinic Forms**, just below Blank Forms.

1. Pick the letterhead (any active facility; the primary business entity comes first), the paper size, and optionally a recipient from the Address Book.
2. If a patient is open, tick **Fill in the current patient** to put their name, date of birth, chart ID, address and phone on the pages. Otherwise everything prints blank. Tick **Also fill Demographics and History** as well to print those forms with what's on file, for a returning patient to check and correct (see [Check and correct](#check-and-correct)).
3. Tick documents and forms, or click a ready-made packet, then **Open for printing**. The packet opens in a new tab with a **Print** button.

A packet prints in its own order; anything ticked by hand follows, in the order of the page.

Every printed page starts with a line for the patient's name, date of birth and ID, filled in when a patient is chosen, so a page that comes loose can still be matched to a chart.

## The documents

| Category | Document | What it is | LOINC | Pack |
|---|---|---|---|---|
| Front desk | Consent to Treatment | General consent for examination and treatment | 59284-0 | any |
| Front desk | Acknowledgment of the Notice of Privacy Practices | Receipt of the Notice, with a clinic-use section for when it wasn't obtained | 57016-8 | US |
| Front desk | Financial Policy and Assignment of Benefits | Payment responsibilities, insurance billing and assignment of benefits | 59284-0 | any |
| Front desk | Payment Plan Agreement | A balance paid in parts, to HRSA's Compliance Manual chapter 16: open to every pay class, no one refused care for being unable to pay, and the clinic's own steps if payments stop | — | US |
| Front desk | Authorization to Release Health Information | A HIPAA authorization with the elements 45 CFR 164.508 requires, separate initials for sensitive records, and a 42 CFR Part 2 note | 64292-6 | US |
| Front desk | Your Rights and Responsibilities | What patients can expect, and what the clinic asks of them | — | any |
| Front desk | Missed Appointments | The missed and late appointment policy, with help offered (rides, child care) | — | any |
| Front desk | Language Help and Interpreter Request | The patient's languages and the help they want, with a staff section for what was arranged | — | any |
| Front desk | Communication Preferences | How the clinic may contact the patient: phone, voicemail, text, email, portal | 59284-0 | any |
| Front desk | Consent for Telehealth Visits | Consent to visits by video or telephone | 59284-0 | any |
| Front desk | Consent for Photos and Recordings | Photos, video or audio for the record and care, or for teaching, each ticked separately; publicity needs its own permission | 59284-0 | any |
| Front desk | We Offer Discounts Based on Income | A notice to post or hand out: the sliding fee discount, who qualifies, and that no one is turned away; the clinic writes in its own schedule | — | US |
| Front desk | Sliding Fee Discount Application | Household members and income, proof attached, and a clinic section for the poverty-guideline percentage and discount level (the facts UDS Table 4 reports) | — | US |
| Screening | Patient Health Questionnaire-4 (PHQ-4) | Two anxiety and two depression questions; each part scored 0 to 6, and 3 or more on either is a positive screen | 69724-3 | any |
| Screening | Patient Health Questionnaire (PHQ-2) | The two-question depression screen, scored 0 to 6. The office section notes that it doesn't ask about self-harm | 55757-9 | any |
| Screening | Alcohol Use Screen (AUDIT-C) | The WHO AUDIT's first three questions, scored 0 to 12 | 72109-2 | any |
| Screening | Patient Health Questionnaire (PHQ-9) | Depression screening, scored 0 to 27, with an office prompt to follow the clinic's safety protocol on question 9 | 44249-1 | any |
| Screening | Generalized Anxiety Disorder Scale (GAD-7) | Anxiety screening, scored 0 to 21 | 69737-5 | any |
| Screening | PTSD Checklist for DSM-5 (PCL-5) | The VA's 20 questions for a positive PC-PTSD-5, scored 0 to 80; 31 or more suggests probable PTSD | 101697-1 | any |
| Screening | Physical Symptoms (PHQ-15) | Fifteen physical symptoms, scored 0 to 30; 5, 10 and 15 mark low, medium and high severity | 69728-4 | any |
| Screening | Healthy Days (CDC HRQOL-4) | CDC's four questions on general health and unhealthy days in the past 30 | | any |
| Screening | Everyday Discrimination Scale | David Williams's nine questions on unfair treatment in day-to-day life, and its reasons; no cut-off | 112307-4 | any |
| Screening | Primary Care PTSD Screen (PC-PTSD-5) | A question about a traumatic event, then five yes or no questions, scored 0 to 5 | 102010-6 | any |
| Screening | Geriatric Depression Scale, Short Form (GDS-15) | Fifteen yes or no questions for older adults, scored 0 to 15 | 48543-3 | any |
| Screening | Check Your Risk for Falling (Stay Independent) | CDC's STEADI falls checklist, scored 0 to 14 | 97874-2 | any |
| Screening | Ask Suicide-Screening Questions (ASQ) | NIMH's suicide risk screen, asked by a nurse or clinician, with NIMH's next steps for each result | 115564-7 | any |
| Screening | Hunger Vital Sign™ (Food Insecurity Screen) | Children's HealthWatch's two statements; often or sometimes true to either is a positive screen. Counts on UDS Table 6A line 39 when answered as an OpenEMR questionnaire | 88121-9 | US |
| Screening | Food Security Screen (USDA Six-Item) | USDA's six questions on affording food in the last 12 months, scored 0 to 6. Counts on UDS Table 6A line 39 when answered as an OpenEMR questionnaire | 95361-2 | US |
| Screening | Substance Use Screen (TAPS-1) | Tobacco, alcohol, drugs and prescription medications not used as prescribed, in the past year; any use is a positive screen for that substance | 96841-2 | any |
| Screening | Brief Screener for Tobacco, Alcohol, and other Drugs (BSTAD) | NIDA's screen for adolescents: days in the past year of tobacco, alcohol and marijuana, then other drugs and medications for anyone who used any; a risk level for each substance by NIDA's cut-offs | | any |
| Screening | HARK: Relationship Safety Questions | Four yes or no questions about a partner or ex-partner in the past year; 1 or more is a positive screen. Given when the patient is alone | 76499-3 | any |
| Screening | Health Risk Assessment (Annual Wellness Visit) | Every topic 42 CFR 410.15 asks a Medicare wellness visit's health risk assessment to address, plus the providers and medicines the first visit lists | | US |
| Front desk | Good Faith Estimate | The No Surprises Act estimate for an uninsured or self-pay patient, with every element of 45 CFR 149.610(c) | | US |
| Front desk | Your Right to a Good Faith Estimate | The notice to post, adapted from CMS's model | | US |
| Front desk | Care Management Services: Consent | What CMS says a patient must be told before chronic care management and related services | | US |
| Front desk | Medicare: Other Insurance Questions | CMS's model questions on whether another payer pays before Medicare, in plainer words | | US |
| Screening | Vaccine Record and Vaccine Information Statements | What the vaccine injury law asks a provider to record for each vaccine, with the information statement given | | US |
| After the visit | Asthma Action Plan | Daily medicines and what to do by green, yellow and red zones, following NHLBI's plan | | any |
| After the visit | Home Blood Pressure Log | Readings at home, with the steps for an accurate reading | | any |
| After the visit | Blood Sugar Log | Two weeks of glucose readings at the times the clinician asks for | | any |
| After the visit | My Medicines: Review | The medicines on file to check and correct, and everything else the patient takes | | any |
| Screening | Preventive Care Questions | The answers the chart doesn't hold that decide which USPSTF A and B screenings are due: pack-years, family history, sexual health, where the patient has lived, pregnancy | | US |
| Screening | Substance Use Assessment (TAPS-2) | For a positive TAPS-1: yes or no questions about each substance in the past 3 months, each substance scored on its own | 96845-3 | any |
| Care coordination | Referral Letter | To the Address Book recipient, with urgency, the question to answer, the problems, medicines and allergies on file, and what is enclosed | 57133-1 | any |
| Care coordination | Request for Records | A request to another provider for a patient's records | — | any |
| Office workflow | Patient Routing Slip | Travels with the patient: check-in, rooming, provider, checkout | — | any |
| Office workflow | Staff Handoff (SBAR) | Handing a patient from one staff member, shift or site to another | — | any |
| Office workflow | Internal Referral | To behavioral health, care management, nutrition, social work or another of the clinic's sites | 57133-1 | any |
| Office workflow | Chaperone Record | Each chaperone offer, the patient's choice, and who was in the room | — | any |
| Downtime | Encounter Form (Superbill) | One visit's charges on paper: CPT or HCPCS services, ICD-10-CM diagnoses A to L as on the claim form, the billing NPI, sliding fee level, payment | — | US |
| Downtime | Downtime Visit Note | A paper visit note for when OpenEMR is down, with a box to record when it was entered | 11506-3 | any |
| Privacy and patient rights | Notice of Privacy Practices | The notice itself (45 CFR 164.520), with the 42 CFR Part 2 statements required from February 16, 2026; fill in the effective date and privacy contact | 57017-6 | US |
| Privacy and patient rights | Request to See or Get a Copy of My Health Record | Right of access (164.524): paper or electronic, to the patient or a person they name | — | US |
| Privacy and patient rights | Request to Correct My Health Record | Right to amend (164.526) | — | US |
| Privacy and patient rights | Request for a List of Disclosures | Accounting of disclosures (164.528) | — | US |
| Privacy and patient rights | Request to Limit How My Information Is Shared | Restrictions (164.522(a)), including self-paid care kept from the health plan | — | US |
| Privacy and patient rights | Revoking My Authorization to Release Information | Revocation (164.508(b)(5)) | — | US |
| Privacy and patient rights | Consent to Share Substance Use Disorder Treatment Records | 42 CFR Part 2 consent with every element of 2.31, including the single consent for treatment, payment and operations | 64292-6 | US |
| Privacy and patient rights | Notice of Privacy Practices for Substance Use Disorder Records | The notice 42 CFR 2.22 (2024) requires of a Part 2 program, element by element; fill in the contact and effective date | 57017-6 | US |
| Privacy and patient rights | Revoking My Consent to Share Substance Use Disorder Records | Takes back a Part 2 consent, in writing (2.31(a)(6)) | — | US |
| Privacy and patient rights | Personal Representative | Who the law lets act for the patient (power of attorney, guardian, parent, executor), the paper that shows it, and the clinic's check of identity and authority (164.502(g), 164.514(h)) | — | US |
| Privacy and patient rights | Patient Concern or Grievance | A concern or grievance, including unfair treatment, with what 45 CFR 92.8(c) asks the record to hold | — | US |
| Privacy and patient rights | Notice of Nondiscrimination | The Section 1557 notice (45 CFR 92.10), as a 20-point poster; fill in the Section 1557 Coordinator | — | US |
| Privacy and patient rights | Free Language Help and Services for People with Disabilities | The Section 1557 notice of availability (92.11) with every tagline, as a 20-point poster | — | US |
| Privacy and patient rights | Your Family Planning Visit Is Private | The Title X notice to 42 CFR 59: confidentiality, minors, the policyholder's statement, voluntary services, options counseling and charges by income | — | US |
| Family planning | Title X Family Planning Visit Record | FPAR 2.0's family planning items, each coded as OPA codes it (pregnancy intention, the methods at intake and exit and why none, how provided, counseling), plus Title X's own items from 42 CFR 59 | — | US |
| Privacy and patient rights | You Can Ask for a Chaperone | A poster: a chaperone at any exam, at any time, without a reason | — | any |
| After the visit | Your Test Results | A results letter: each result and what it means, what to do next, signed by the clinician | — | any |
| After the visit | Visit Summary and Instructions | What was found, medicines on file and changed today, what to do at home, tests ordered, the next visit | 103796-9 | any |
| After the visit | Consent for a Procedure | Informed consent for one procedure: why, benefits, risks, other choices, questions answered | 64293-4 | any |
| After the visit | Controlled Medicine Agreement | Patient and clinician agree how a controlled medicine is taken, kept and refilled, and what the clinic will do (taper plans, help with addiction, naloxone) | 94136-9 | any |
| After the visit | Work or School Note | Confirms the visit and when the patient may return, without giving the reason for the visit | — | any |

The format, and the rules every document follows, are in [docs/](docs/README.md). Each is a JSON file, the same on every OpenEMR release: in `data/documents/` if it applies anywhere, or in `data/packs/us/documents/` if it belongs to United States law or programmes (the **Pack** column; see [Country packs](#country-packs)). The short UDS form is the US pack's too. The PHQ-9 and GAD-7 are printed with their authors' statement that no permission is needed to reproduce them. Their answers can be typed into OpenEMR's own PHQ-9 and GAD-7 encounter forms, which ship with OpenEMR but have to be enabled under **Administration › Forms › Forms Administration**.

### Standards the documents follow

- **LOINC document codes** where an exact one exists (the table above), as OpenEMR's own C-CDA referral uses 57133-1. Each code is checked by its LOINC check digit; a document with no exact code is left uncoded rather than mislabeled.
- **What the law says goes in.** `data/requirements.json` (the instruments' wording) and the US pack's `data/packs/us/requirements.json` list, for the documents a rule governs, each required element and its citation: the Notice of Privacy Practices (164.520(b)(1), element by element), the patient-rights requests (164.522, 164.524, 164.526, 164.528, 164.508(b)(5)), the Part 2 consent (42 CFR 2.31), the HIPAA authorization (45 CFR 164.508(c)), the privacy notice acknowledgment (164.520(c)(2)(ii)), the sliding fee application (HRSA Compliance Manual, chapter 9), the work or school note's minimum necessary (164.502(b)), and the PHQ-9 and GAD-7 wording. The tests fail if a shipped document loses one. A clinic's own version of one of these documents is checked too, and the Clinic Forms page names anything it can't find (by wording, so check before relying on the warning).
- **Scored as published.** A screening score means what its validation study found only when the form is the instrument the study used. `data/instruments/` holds a reference for each validated instrument (PHQ-9, PHQ-2, GAD-7, AUDIT-C, PC-PTSD-5, GDS-15, CDC's Stay Independent falls check, the ASQ, TAPS-1 and TAPS-2, the Hunger Vital Sign and USDA's six-item food security screen):
  - its questions and answers from LOINC's own questionnaire, and each answer's points from LOINC's answer lists;
  - its total range;
  - the published cut-offs, each with its paper (PubMed id) or the owner's document, and the sentence that states it;
  - or, for a screen read by rule rather than by a total (the ASQ: Yes to any of questions 1 to 4 is a positive screen), its published rules, with their source;
  - or, for an instrument scored in parts (TAPS-2 scores each substance), each part's questions and published cut-offs.

  The tests fail if a document's questions, their order, its answers, the points for each, its total range or a cut-off it prints differ, if it doesn't say a published rule with the questions it names, or if a translation changes any of them. A difference that is deliberate is listed in the reference with its reason (GAD-7's question 2 and PHQ-9's closing question follow Pfizer's printed form, not LOINC's coding of it). `tools/instrument-references.php` refreshes the references from LOINC (a free LOINC account) and keeps what was written by hand. `tools/loinc-names.php` does the same for `data/loinc-names.json`, LOINC's long common name for every LOINC code the documents and references use, and the tests hold each document's code to that name.

  Where the published instrument scores differently from LOINC's answer lists, the reference says so under `"scoring"`, with its source. The tools refuse an instrument whose answers have no score until that is written. LOINC scores PC-PTSD-5's trauma question, which the instrument doesn't; it leaves STEADI's first statement and every GDS-15 answer unscored. It has TAPS-1's prescription question as free text with no answers, and lists another question's answers backwards: a reference can give a question another's answers and put them in the published order. LOINC's six-item food security questionnaire leaves out one of the six questions, so that reference is written from USDA's own module, with LOINC's codes, and says so. `tools/import-instrument.php` brings a new instrument in from LOINC: its reference, and a draft document to compare with the instrument's printed form before it is made active.

  A clinic's own version of one of these instruments is checked the same way. That means its own file with the instrument's id, or its own document marked validated with a LOINC code there is a reference for. The Clinic Forms page names each question, answer or point that differs, because a score from a changed form may not mean what the published cut-offs say. The PHQ-9 and GAD-7 print their published severity bands in the office-use section, and the check holds those numbers to the papers.
- **Plain language.** Every patient document reads at an 8th-grade level or below (Flesch-Kincaid), as AHRQ's health literacy toolkit advises; the tests measure it. Validated instruments (PHQ-9, GAD-7) keep their published wording and are exempt.
- Each document says who reads it (patient, staff or provider) and whether its wording is a validated instrument's.
- **Each document has a version**, printed small at the foot of every page with its id (`consent-to-treat v1`) and recorded in the audit event, so a signed copy names the wording the patient signed. A version means one wording: the tests fail if a document's wording changes without its version being raised.
- **Each document says where its wording comes from and on what terms:** written for Clinic Forms, written to a rule's elements (with a link to the rule), or a published instrument (with its holder, terms and the permission they give: PHQ-9, PHQ-4, PHQ-2, PHQ-15 and GAD-7 from Pfizer, "no permission required"; AUDIT-C from the WHO manual; the Hunger Vital Sign from Children's HealthWatch, free with its citation; PC-PTSD-5 from the VA, the ASQ from NIMH, TAPS-1 from NIDA, Stay Independent from CDC and the GDS-15 from its developers, all public domain; documents written for Clinic Forms, CC0).

### The documents are starting points

They are written to be plain and complete, not as legal advice. Consent, release and financial wording varies by state and by payer, and some states have their own required forms (minors' consent, mental health, HIV testing). Read them with whoever reviews your clinic's forms before using them, and replace any of them with your own (see the next section).

### Country packs

The documents that work anywhere (consent to treatment, the screening questionnaires, letters, office slips) are the core. A **country pack** adds the documents of one country's law and programmes, and can replace a core document with its own version. Clinic Forms ships one pack, the **United States**: the HIPAA privacy set, 42 CFR Part 2, the Section 1557 notices and taglines, the HRSA sliding fee documents and the UDS form, with their legal checks and the packets that use them.

- **Which pack.** `settings.json` in your Clinic Forms folder (below) says `{"country": "us"}`, or `{"country": "none"}` for the core alone. Without it, the country typed for your first facility under **Administration › Facilities** decides: blank (as a default install leaves it) or a US spelling (US, USA, United States) means the US pack. Another country gets the core alone and a note on the page.
- **Regions.** The facility's State picks its region. For now that drives the tagline check: the page lists, for each facility's state, which of the 15 languages the Census counts there have no tagline yet (Census groups such as "Amharic, Somali, or other Afro-Asiatic languages" are named for you to check against HHS's list). A region can also carry documents of its own (`data/packs/us/regions/<code>/documents/`); none ship yet.
- **Order.** Core, then the pack, then the region, then your own folder: each replaces the one before with the same id. A translation made for the replaced version is out of date, and prints in English.
- **Paper.** The pack says the paper the picker starts on (Letter for the US; A4 without a pack).
- **Another country.** A pack is a folder in `data/packs/<two-letter code>/` with `pack.json` (name, the facility country names that choose it, paper, layout forms), `documents/`, and optionally `requirements.json`, `packets.json`, `taglines.json` and `regions.json`. The format is in `src/Paper/Pack.php`.

### Your own documents

Put your own documents in `sites/<site>/documents/clinic-forms/` (for most installs, `sites/default/documents/clinic-forms/`), in the same format as `data/documents/`. That folder belongs to the site, so module updates leave it alone.

- A file with a new id adds a document. A file with the id of a shipped document replaces it, so the clinic's edited consent prints instead of the module's.
- **Know when the module's version changes.** Start from a copy of the shipped file and add the `"based_on"` line the Clinic Forms page shows for it. When a later module release changes that document, the page says so and names the file to compare with. Your version keeps printing; it is never overwritten.
- **Stop offering a shipped document** without replacing it: list its id in `settings.json`, `{"hide": ["financial-policy"]}`. It leaves the picker and the packets.
- A document marked `"status": "draft"` is offered with a Draft badge and prints "draft" at the foot of each page; `"retired"` stops offering it.
- Files are checked strictly. A document with an unknown field, an unknown block type or a placeholder that doesn't exist is left out, and the Clinic Forms page names the file and the mistake.
- **Shared blocks.** A part the module writes once can go in your documents by name, so it stays the module's wording: `{"type": "include", "block": "consent-signature"}` puts the patient's signature line and the representative's name and authority there, and `{"type": "include", "block": "patient-identification"}` the patient's name, date of birth and ID. Your own blocks go in a `blocks/` folder in the same place, with an id, a title, an `about` line and the blocks (or a scale's questions, `rows`). The blocks the module has, and the format, are in [docs/DOCUMENT-FORMAT.md](docs/DOCUMENT-FORMAT.md#shared-blocks). A block of yours with the name of one of the module's is left out and reported.
- `settings.json` in the same folder chooses the country pack and hides shipped documents: `{"country": "us", "hide": ["financial-policy"]}` (both optional).
- Your own packets go in `packets.json` in the same folder, in the format of `data/packets.json`: an id, a title, the items and, optionally, an `about` line saying what the packet is for. A packet with the id of a shipped one replaces it. A file with a mistake is left out and reported; the shipped packets stay.

The format is in `src/Paper/Document.php`. Blocks are headings, paragraphs, fields, checklists, ruled lines, signature lines, scales (a question per row and a box per answer, optionally scored, as the PHQ-9 is) and tables (column headings over blank rows, as the household list is). Text can use these placeholders, which print as a blank line when the value isn't known:

| Group | Placeholders |
|---|---|
| `facility` | `{facility.name}` `{facility.address}` `{facility.phone}` `{facility.fax}` `{facility.email}` `{facility.website}` `{facility.npi}` |
| `patient` | `{patient.name}` `{patient.dob}` `{patient.id}` `{patient.address}` `{patient.street}` `{patient.city}` `{patient.state}` `{patient.zip}` `{patient.phone}` `{patient.sex}` `{patient.allergies}` `{patient.medications}` `{patient.problems}` (current allergies, medications and problems, as OpenEMR's issue lists hold them) |
| `recipient` | `{recipient.name}` `{recipient.organization}` `{recipient.specialty}` `{recipient.address}` `{recipient.phone}` `{recipient.fax}` `{recipient.email}` |
| `user` | `{user.name}` (the person printing) |
| `provider` | `{provider.name}` `{provider.credentials}` `{provider.npi}` `{provider.specialty}`: the **Signing provider** chosen at the top of the page. It defaults to the clinician printing, or else the patient's own provider. Credentials are the user's *Valedictory* (MD, NP) under Administration › Users |
| `clinic` | `{clinic.today}` |

## What each document is held to

[CONFORMANCE.md](CONFORMANCE.md), generated from the documents and kept current by CI, lists each document, what it follows and how that's checked. A document written to a rule is held to that rule's elements (`data/requirements.json` and the US pack's), and a validated instrument to LOINC's reference. Any other published instrument is held to its publisher's wording, kept in `data/references`. The tests fail on any document that isn't.

A document not yet cleared for public release is held back, with its reason, in `data/release-gates.json`. It ships as a draft, marked Draft in the picker and on every printed page, and no packet offers it. A document whose answers carry SNOMED CT US Extension codes is held automatically, because those codes' licence doesn't cover a public, worldwide download.

Checked outside the module, in the openhealthcare workspace: every FHIR export against FHIR R4 and SDC 4.0.0 (the HL7 validator, nightly), skip logic and totals in OpenEMR's own questionnaire runtime (nightly), and every patient document against PDF/UA-1 by all three routes (veraPDF, weekly).

## Spanish and language assistance

Choose **Español** as the language at the top of the Clinic Forms page. Documents marked **ES** print in Spanish; staff documents and the rest stay in English. The words Clinic Forms prints itself (Phone, Date, the patient line) follow the paper's language, not the language you use OpenEMR in. Word and LibreOffice downloads follow it too.

- **36 of the 70 patient documents** have Spanish versions, in the `es/` folder beside each (`data/documents/es/`, `data/packs/us/documents/es/`); the other 34 print in English until one is written. A Spanish version made from older English wording prints in English until it is updated; none is, as of 0.40.2. The last ten came from the backlog, chosen by use. 0.39.0 added Your Rights and Responsibilities and Missed Appointments (both in every new patient's packet), the Visit Summary and the medicine review (each in three packets), and the language help request. 0.40.0 added Preventive Care Questions, the Consent for a Procedure, the home blood pressure and blood sugar logs, and the Asthma Action Plan.
- **The validated questionnaires use their official Spanish wording.** PHQ-9, PHQ-2 and GAD-7 are the "Spanish for the USA" versions from phqscreeners.com. AUDIT-C is from the WHO's Spanish AUDIT manual. The ASQ (asked by staff) uses NIMH's Spanish translation for its questions and the steps that translation has; what NIMH added in its 2025 revision is translated by Clinic Forms, and the page says so.
- **The other translations are ours, made with machine help, and marked so.** On the Clinic Forms page each shows **ES needs review** beside its **ES** badge. Federal rules (45 CFR 92.201(c)(3)) ask that machine translation of what is critical to a patient's rights or access be reviewed by a qualified human translator, so have one review each before it goes to patients. A reviewed copy saved as your own translation (below) carries no mark. The file says so with `"machine_assisted": true`; a validated instrument can't ship with it, since it is only ever in its publisher's translation.
- **Nothing gets dropped in translation.** Each translation is checked against its English document: the same blocks, fields, items, signature lines and placeholders. The key legal wording is checked in Spanish too.
- **Old Spanish is never printed for new English.** Each translation records which English wording it was made from (its `translates` fingerprint). When the English changes, through a module update or your own version of the document, the document prints in English and the page lists it as out of date until its Spanish is updated. The picker marks it **ES out of date**.
- **Your own translations:** put them in `sites/<site>/documents/clinic-forms/es/`, with the English document's id. The page warns if one doesn't match its English document. If you start from a shipped Spanish file, keep its `translates` line: then your copy, too, is marked out of date when its English changes. A translation without that line is printed without this check.
- **Layout forms (Demographics, History) still print with your site's labels.** OpenEMR's own Spanish translation renders "Ethnicity" as "Raza", which means race, so it isn't used for intake forms.

**Section 1557 language assistance (45 CFR 92.10 and 92.11).**

- **Taglines on every patient page.** With *Add the language assistance taglines* ticked (the default), every patient document and intake form ends with short notices, in English and Spanish, saying that free language help and disability aids are available. They come first in the paper's own language. The rule asks for them on the notice of privacy practices, intake and consent forms, billing communications and complaint forms.
- **Your state's languages.** The rule asks for English and at least the 15 languages most spoken by people with limited English in your state. Clinic Forms ships English and Spanish, and the page lists the languages your facilities' states still need (from the Census Bureau's 2019–2023 American Community Survey; HHS's own list is the one the rule uses). Add the others, with HHS's translations, in `sites/<site>/documents/clinic-forms/taglines.json`:

  ```json
  {"taglines": {"vi": "CHÚ Ý: … Gọi số {facility.phone} (TTY: 711).", "zh-Hant": "注意：… {facility.phone} (TTY: 711)。"}}
  ```
- **The two notices** print as posters in 20-point sans serif, as the rule requires for posting:
  - the **Notice of Nondiscrimination**;
  - **Free Language Help and Services for People with Disabilities**, the notice of availability, which lists every tagline.

  A poster never carries the patient line.

## Word and LibreOffice

**Edit before printing.** Tick documents and click **Word (.docx)** or **LibreOffice (.odt)** instead of *Open for printing*. You get one file with the same letterhead, recipient and patient details the printed packet would have, to change before printing: a referral letter's wording, a note's restrictions. It opens in Word, LibreOffice and Google Docs, and every page carries the patient line in its header. Layout forms (Demographics and the like) print from the browser only.

**The accessible copy.** For a patient who uses a screen reader, tick documents and click **Accessible copy**: the copy this server can make that a screen reader reads, never the untagged PDF (from 0.39.0).
- With LibreOffice on the server: a PDF made by LibreOffice, tagged for PDF/UA, filled as asked. When a document filed without a name (the grievance form) is ticked beside documents filled for a patient, the Word file instead, since LibreOffice gives a file one page header.
- Without LibreOffice, for documents printed blank: the PDF/UA copies made when Clinic Forms was released, when every ticked document has one as your clinic has it (see below). Otherwise, and whenever the documents are filled for a patient, the Word file.

The button says which it will be on this server. Without LibreOffice, the PDF OpenEMR's mPDF makes is offered as **Print copy (PDF, not tagged for screen readers)**, for printing; with LibreOffice, every PDF is the tagged one, so there is no separate print copy. `format=pdf` and `format=pdf-blank` requests work as before; `format=accessible` gives the accessible copy.

**An accessible PDF.** When LibreOffice is installed on the OpenEMR server, every PDF Clinic Forms makes (the PDF download and the copy saved to the chart) is made by LibreOffice from the module's own LibreOffice file, with PDF/UA on: tagged for a screen reader, with its documents' title and language and its fonts embedded, the patient line at the top of every page and the chart number's barcode at the foot. Clinic Forms looks for LibreOffice only where LibreOffice installs itself (`/usr/bin/soffice` and the others in `LibreOfficePdf::PLACES`), never at a path from a setting or a request. On OpenEMR's official Docker image, `apk add libreoffice-writer` installs it (about 1 GB with what it needs). `"pdf_libreoffice": false` in `settings.json` turns it off. Without LibreOffice, the PDF is made by the mPDF OpenEMR ships, which writes no tags, so it doesn't meet PDF/UA, the standard for accessible PDFs. For blank documents, the **Accessible copy** then gives the PDFs made when Clinic Forms was released (the **Accessible PDF, blank** button before 0.39.0), each checked with veraPDF's PDF/UA-1 check: US Letter, with the language assistance taglines, and no letterhead, since the clinic isn't known when they're made (US pack; several come in a .zip). One is given only while the document's wording and the taglines are the ones it was made from, so a clinic's own version gets none. For a filled document without LibreOffice on the server, download the Word or LibreOffice file and, in LibreOffice, use **File › Export as PDF** with **Universal Accessibility (PDF/UA)** ticked. The HHS Section 504 rule asks health centers for accessible electronic documents (WCAG 2.1 AA) from 2027-05-11, or 2028-05-10 with fewer than 15 employees.

**Fill on screen (FHIR Questionnaire).** Tick documents and click **FHIR Questionnaire (.json)** to download them as FHIR R4 Questionnaires: one file, or a Bundle when several are ticked. They are blank forms with the clinic's details and never a patient's (a field the chart fills, such as the patient's name, is left out, since a response names its patient), so no audit event is written. Use them in another system that fills FHIR Questionnaires, or add them straight to OpenEMR's own questionnaires, as described next.

- The validated instruments carry LOINC's question and answer codes, with the answers in the published order and each answer's points. Their total line adds the points up as the questions are answered, in OpenEMR's own questionnaire runtime (OpenEMR 8.3 and later). Each coded total is marked for SDC extraction, so a form filler that extracts makes it an Observation with LOINC's code (OpenEMR doesn't extract yet).
- Questions asked only after a certain answer appear only then: ASQ's acuity question after a Yes to any of 1 to 4, and BSTAD's other drugs after any days of use. Checked in OpenEMR's runtime on 8.3, 8.4.1 and 8.5 (in development). The paper keeps its written instructions. A clinic's own documents can do the same: see [docs/DOCUMENT-FORMAT.md](docs/DOCUMENT-FORMAT.md).
- **With OpenEMR's questionnaire runtime 0.4.0 or later** (master, and 8.5.0 once its backport ships), the export says more, read from the runtime file the site serves: a scale is one answer grid, a table a grid of rows, the patient's own signature is drawn or typed and saved with the response (clinicians, witnesses and other staff sign as before), and TAPS-1 asks question 2 of men and question 3 of women. OpenEMR's pages don't yet pass the form the patient, so until they do TAPS-1 asks both, as before; a host that passes one (the SMART questionnaire app) gets the skip. On an older runtime, and on LHC-Forms, every document exports as before: those renderers show the plain forms anyway, and would drop a required signature without a line to sign.
- Every LOINC code goes with LOINC's own name for it, as the [LOINC licence](https://loinc.org/license/) asks of LOINC content passed on:
  - a document's, question's or total's code with its long common name, from `data/loinc-names.json`;
  - an answer's code with LOINC's answer text. Where that differs from the paper form, the screen shows LOINC's: AUDIT-C's "2-4 times a month" for WHO's "2 to 4 times a month". The printed form keeps the paper's wording.
- **A question writes the same field in any form.** Each LOINC question, and each instrument's total, names the item in LOINC's own FHIR questionnaire that defines it, as its `definition` (SDC's Questionnaire library reference, `http://loinc.org/q/55757-9#32742`; `data/loinc-definitions.json`, written from LOINC by `tools/loinc-definitions.php`). A question LOINC asks in several questionnaires names the smallest: the PHQ-2's first question has the same definition in the PHQ-2, the PHQ-4 and the PHQ-9.
- **Modular forms (SDC 4.0.0).** Parts that documents share are written once ([shared blocks](docs/DOCUMENT-FORMAT.md#shared-blocks)). A block of LOINC-coded questions stands on its own in FHIR: `tools/export-questionnaires.php` writes it as a Questionnaire with its own canonical url and version (the PHQ-2's questions, the Hunger Vital Sign's), and the documents that include it as modular forms that name it with a `subQuestionnaire` (`phq-9-modular.json`). What you download, and what OpenEMR gets, is the assembled form: the same items as before, with `assembledFrom` naming its blocks.
- A Spanish questionnaire, when it keeps its English instrument, has the same question codes and points. Its answers are the instrument owner's Spanish, not LOINC's wording, so they are coded in the module's own system, by position, and still add up to the same total. A clinic's own version that differs from its instrument carries no codes.
- Every shipped document and translation passes:
  - the HL7 FHIR validator, against the SDC 4.0.0 questionnaire profile each Questionnaire claims. It notes five things that are expected: SDC's preference for semantic versions, where Clinic Forms numbers its versions 1, 2, 3; a Spanish questionnaire's codes carrying LOINC's English names where LOINC has no Spanish one; the ASQ's codes, and LOINC 2.83's new names for the food security survey's, being newer than the terminology server's LOINC (2.82); and LOINC's questionnaires not being fetchable;
  - OpenEMR 8.4.1's questionnaire import check;
  - OpenEMR's questionnaire runtime, which works out each instrument's total correctly.

**Enter paper answers in OpenEMR (Add to OpenEMR questionnaires).** Tick documents and click **Add to OpenEMR questionnaires** to add them to OpenEMR's own questionnaires, as the same blank Questionnaires. Staff then enter a patient's answers from the paper form in OpenEMR: in an encounter, **Questionnaires**, then **New Questionnaire**, or, on OpenEMR 8.3 and later, the patient menu, **Assessments**, then **Questionnaires**. OpenEMR adds up the score as the answers go in, and keeps the answers in the chart as a FHIR QuestionnaireResponse, with LOINC's codes for the validated instruments.

- The button is shown to users with OpenEMR's Admin > Forms permission (`admin / forms`), the permission OpenEMR asks for before importing forms. It adds a questionnaire for everyone at the site.
- OpenEMR files questionnaires by title. Adding a document again updates the questionnaire it added before, in place, and OpenEMR raises its version.
- OpenEMR 8.5 (in development) decides where each questionnaire is offered. A newly added one is offered on the patient dashboard, and **Questionnaire Availability**, on the patient's Questionnaires page, changes where it appears. Adding it again leaves those choices as they are. Questionnaires added before a site upgrades to 8.5 keep appearing where they did. Checked against OpenEMR master a10ebfa8d3 (2026-10-22).
- **By visit type (0.39.0, OpenEMR 8.5 and later).** Under the button, tick visit types (the site's calendar categories) to list each added questionnaire as a form of its own in the **Questionnaires** menu of visits of those types. Clinic Forms registers it as an encounter form, as OpenEMR's Questionnaire Assessments form does, and adds one rule per visit type through OpenEMR's questionnaire availability. It only adds: a rule an administrator wrote is never changed, so a questionnaire already offered in every visit stays so, and the page says to narrow it under **Availability** (administrators). With nothing ticked, or on an OpenEMR without questionnaire availability, nothing changes and no choice is shown. Checked against OpenEMR master 2ee0a6a503: the rule written for one visit type is the one OpenEMR's lookup finds for that type and not another.
  - **A packet only at certain visit types**, such as the yearly adult screen at annual visits, is set up in OpenEMR itself, as its author describes it (openemr/openemr#14485):
    1. Register each questionnaire as an encounter form. That shows it at every visit.
    2. In Questionnaire Availability, add one rule per questionnaire and visit category. Rules add up, so one questionnaire can belong to several visit types.
    3. Switch off the every-visit rule and keep the visit-category ones.

    Clinic Forms doesn't do this for you. OpenEMR has no service for registering an encounter form, and Clinic Forms writes to the database only through OpenEMR's own services.
- A questionnaire with the same title that Clinic Forms didn't add (one the clinic imported itself, say) is left as it is, and so is one the clinic has turned off. The page says which.
- A document with nothing to answer, such as the notice of free language help, is left out.
- Each one is filed under Survey, the category OpenEMR's encounter form gives its own questionnaires (OpenEMR 8.0 and later; 7.0 has no categories).
- An audit event names each document, its version and the questionnaire's number in OpenEMR.
- Checked on OpenEMR 8.4.1 in a headless browser: the PHQ-9, added, is filled in OpenEMR's own Questionnaires page with the highest-scoring answers. The page shows 27, and the saved response holds the coded answers and the total. On 7.0.3 it is added and offered by the encounter form; OpenEMR works out totals in 8.3 and later.

**For OpenMRS.** `tools/export-o3.php` writes each validated instrument that CIEL, the concept dictionary most OpenMRS sites load, can carry as an OpenMRS 3 form, for the O3 Form Builder. Its questions and answers are saved as CIEL concepts (`data/ciel.json`, from CIEL's latest release). Today that is the PHQ-9, PHQ-2 and GAD-7, in English and Spanish; PHQ-9's unscored closing question, which CIEL lacks, is left out and named in the form. CIEL has no concepts yet for the other instruments' questions (AUDIT-C, PC-PTSD-5, GDS-15, the ASQ, TAPS-1, Stay Independent and USDA's food security screen), so they have no OpenMRS form; their FHIR Questionnaires carry LOINC's codes. The forms pass OpenMRS's published form schema, and each concept and answer is checked against the CIEL release.

**Your own documents, written in Word or LibreOffice.** Write a letter or form as you normally would, type placeholders where details belong, and an administrator adds it under **Add your own Word or LibreOffice documents** at the foot of the Clinic Forms page. It then appears under *Your Word and LibreOffice documents*, and staff download it filled with the letterhead, recipient and patient chosen at the top of the page.

- Placeholders are the ones in the table above (`{recipient.name}`, `{patient.medications}`, `{user.name}`…). The tags OpenEMR's own Document Templates use for the same facts also work (`{PatientName}`, `{PatientDOB}`, `{Address}`, `{Medications}`…), so an existing template can be added as it is. The page lists every placeholder and its OpenEMR equivalent.
- Formatting is kept. Word and LibreOffice often store a typed placeholder in pieces (after an edit, a spelling check, a change of font); those are found and filled too. Placeholders in the body, tables, text boxes, headers and footers are filled.
- When it's added, the page lists the placeholders the document uses, and names any that Clinic Forms won't fill (a typo, or an OpenEMR tag such as `{DOS}`), which are left as typed.
- Only real Word or LibreOffice files of up to 5 MB are accepted. They're kept in `sites/<site>/documents/clinic-forms/`, so module updates leave them alone; adding a file with the same name replaces it.
- Nothing is stored when a filled document is downloaded: it's built and sent.

OpenEMR's Document Templates (Administration › Documents) remain as they are. These add the clinic letterhead, an Address Book recipient and the person printing, which those templates can't fill, and keep `&` as `&` (OpenEMR's templates print it as "[and]").

## Paper versions of layout forms

Each group of the layout becomes a heading, and each field in use a labelled blank shaped for its type:

- lists, checkboxes and radio buttons print their options as tick boxes, marked "tick one" or "tick all that apply";
- a list of more than 24 options (language, country, religion) prints the options this clinic's patients actually have, up to 12, most used first, and an **Other** line. Only counts are read, never a patient's answer. A clinic with no patients yet gets a line to write on;
- exam results, textbox lists and checkboxes with text print a row per option, as OpenEMR's own blank forms do;
- lifestyle fields print Current / Quit (with a date) / Never / N/A;
- dates print as day, month and year boxes, text areas as ruled lines, signatures as a signature line;
- fields that pick a record (a provider, pharmacy, facility, code or insurance) print as a line.

Short answers sit two to a row. Labels and list options follow the site's translation settings, as the screens do. A field with no label on screen (middle and last name share the "Name" row) is labelled with its description.

On Demographics, the fields the clinic fills rather than the patient (chart number, providers, portal login, registry and publicity codes, deceased date and others: the list is `LayoutPaper::OFFICE_FIELDS`) print last, in a **For office use** box. Each one names the screen tab it's typed into (Who, Choices, Stats…), since the box gathers fields from several.

**The paper follows the entry screens.** Fields print in the order the Edit Demographics, New Patient and Edit History screens show them (and the Tab key moves through them). Measured on 8.4.1 with `dev/clinic_forms_entry_order.py` in the openhealthcare workspace: 107 Demographics fields, 15 on the UDS form and 35 on History. The only step back is the start of the office box, which then goes forward tab by tab. Fields with the same position number are ordered as the screens order them.

### Check and correct

For a returning patient, Demographics and History can print filled with what's on file instead of blank. The patient crosses out what's wrong, writes the correction beside it, and fills in what's missing, so staff type in only the changes.

- Text and dates are written on their lines. The options on file are ticked (X), including a long list's answer even when it isn't among the clinic's common options. Lifestyle status, quit dates and notes are filled. Exam results and the other row-per-option fields are filled row by row.
- Providers, the pharmacy, address book entries and facilities print by name.
- **Social Security and driver license numbers print as their last four characters only.**
- History prints the patient's latest History record. Encounter and transaction forms have no single record to read, so they print blank.
- The audit event for the print says which forms were filled from the chart.

The form prints whatever the site's layout has in use, so a long layout makes a long form. To shorten it, hide fields under **Administration › Forms › Layouts**.

## The chart number on every page, and a copy in the chart

- **A barcode at the foot of every page.** Printed with a patient, each page carries the patient's chart number (the "External ID", `pubpid`) as a Code 128 barcode, beside the document's id and version. OpenEMR's own patient barcode labels encode the same number. A scanned page can then be filed to the right chart by its barcode, and a loose page still says whose it is. Blank prints and posters carry none.
- **A box for a patient label.** For clinics that stick patient labels on paper, `"label_box": true` in `settings.json` prints a box the size of a standard label (2⅝ by 1 inch) at the top of each blank document's first page.
- **A copy in the chart.** With a patient chosen, **Save a PDF copy in the patient chart** files the ticked documents, filled for that patient, as one PDF in their **Documents**, under *Medical Record* (or the category named by `"documents_category"` in `settings.json`). It goes through OpenEMR's own `Document` class, as the Patient Portal's PDFs do, so it's stored and encrypted like any other document. The audit log records it with each document's version.
  - **PDF** downloads the same file without saving it.
  - Both are made by LibreOffice when the server has it (see *An accessible PDF*), and otherwise by the mPDF OpenEMR ships; the buttons are hidden where there's neither.
  - mPDF's PDF carries the patient line and the document's title at the top of every page, and its version and the barcode at the foot, as the printed page does. LibreOffice's carries the patient line at the top of every page and the barcode at the foot, and each document's version at its end, as the Word and LibreOffice files do. If LibreOffice fails, or takes over a minute, mPDF makes the PDF.

## Printing

Printing is the browser's own: no print server and nothing to install. When **Administration › Config › Logging › Printing Log Option** is on, the Print button goes through OpenEMR's print logging, like the rest of OpenEMR.

## Install

```
composer require anun333/oe-module-clinic-forms
```

Run it in the OpenEMR directory. It installs into `interface/modules/custom_modules/oe-module-clinic-forms/`. Then, under **Modules › Manage Modules**, register, install and enable **Clinic Forms**. There is no install SQL. It brings [openhealthcare-common](https://github.com/anun333/openhealthcare-common), the small library of OpenEMR compatibility code the openhealthcare modules share; Composer installs it alongside. If you copy several of our modules in by hand instead, keep their `vendor/openhealthcare-common` at the same version: OpenEMR loads the first copy it finds for all of them.

## Access

As written in the code, not verified for restricted users:

- The menu entry, the page, printing and downloading need **Patients › Demographics** (`patients/demo`), view.
- Adding and removing your own Word and LibreOffice documents needs **Administration › Superuser** (`admin/super`).
- Saving a PDF copy in a patient's chart needs **Patients › Documents** (`patients/docs`), write or add-only, as uploading a document does.
- Printing, downloading and adding accept only a form posted from the Clinic Forms page (CSRF token).
- Printing or downloading with a patient's details filled in writes a `patient-record-select` event to OpenEMR's audit log, naming the patient and the documents. Blank printing writes nothing. Adding or removing a document writes an `other` event. Saving a PDF copy to Documents writes a `patient-record-insert` event naming the file, the document's id, its category and each document's version.

[FOOTPRINT.md](FOOTPRINT.md), generated from the source, lists everything it reads, the OpenEMR functions it calls and what it hooks.

## Compatibility

**PHP security.** PHP's php2shell advisory (2026-10-21; CVE-2026-109618 and CVE-2026-109619) is remote code execution without logging in, and it is being exploited. Run OpenEMR on PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, whatever the module needs. The official OpenEMR 7.0.2 and 7.0.3 Docker images can't get that fix: they carry PHP 8.2.16 and 8.3.15 on Alpine releases that no longer get updates. Run 7.0 only on a patched PHP, or upgrade OpenEMR. OpenEMR rebuilt its 8.4.1, 8.3.0, 8.2.0 and 8.0.0.3 images with the fix on 2026-10-22 (forum t/27157); a container started before then needs pulling again.

| OpenEMR | Checked | How |
|---|---|---|
| 7.0.2, 7.0.3, 8.1.0, 8.3.0, 8.5.0 | 2026-10-23 | Official images, fresh install, with every module enabled and with it alone, on openhealthcare-common: installed through Module Manager; the picker, the print page (75 of 75 pages), a Word and a PDF export, added to OpenEMR questionnaires, saved to Documents, and the FHIR export, with no failing page. |
| 8.4.1 | 2026-10-15 | Installed through Module Manager. The picker and a packet of every document, Demographics, History and Referral printed with no PHP errors, blank and filled, and printed to PDF in Chromium to check the pages. A print without the CSRF token is refused. Local documents, valid and broken, tested. |
| 7.0.2, 7.0.3 | 2026-10-16 | Official images, fresh install: installed through Module Manager; the picker, a 32-page blank packet and a Word export, with no PHP error. On 7.0.3 also: the menu entry, a print without the CSRF token refused (403), and a Spanish packet filled from the chart with its audit event. |
| 8.1.0, 8.2.0, 8.3.0, 8.4.0, 8.5.0 | 2026-10-15 | Official images, fresh install: installed through Module Manager; the picker loads and a blank packet of all 12 documents, Demographics, History and Referral prints all 15 pages with no PHP error. |

0.1.1 was checked again on 8.1.0 and 8.5.0 the same way, and end to end on 8.4.1: layout titles, the patient line on every page, long lists against the clinic's own counts (Demographics and a synthetic encounter form, a deleted form left out, option ids in any case), clinic documents and packets valid and broken, the CSRF refusal, and one audit event for a filled print and none for a blank one.

0.9.0 runs on OpenEMR 7.0 as well. The few OpenEMR calls that changed between 7.0 and 8.x go through one small adapter (`src/Compat/OpenEmr.php`), which uses the newer call where it exists and the 7.0 way otherwise. Checked on the official 7.0.2, 7.0.3, 8.1.0 and 8.5.0 images, and on 7.0.3 in depth: the menu entry, a refused print without the CSRF token, and a Spanish packet filled from the chart with its audit event.

It needs PHP 8.2 or later (OpenEMR's 7.0.3 image runs 8.3) and PHP's zip and DOM extensions. OpenEMR 6.x and older aren't supported: their images run PHP 8.0.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
```

The tests need no database: they cover the document library (every shipped document loads and renders blank and filled with no placeholder left), the escaping, the packets and the paper version of each field type.

## Known limitations

- Only Demographics and History can be filled from the chart; encounter and transaction forms print blank. Previous names, additional addresses and related persons (kept in their own tables) print blank.
- The United States is the only country pack; elsewhere Clinic Forms offers the core documents, and a clinic adds its country's own. The pack is per site, so facilities in two countries share one (their states still each get their own region).
- Spanish is the only translation. The reading-level check measures the English only (its formula is for English). Layout forms print with the site's labels in either language.
- Word and LibreOffice downloads of posters use the normal type size; print posters from the browser.
- Adding your own documents and packets means writing JSON files on the server.
- Nothing is faxed or emailed. Scanned pages aren't filed automatically: the barcode is there for a scanning workflow, not read by the module.
- Word, LibreOffice and PDF files use plain tick boxes (☐) and no logo, and layout forms aren't included.
- A document filed without a name (the grievance form, `"anonymous": true`) never prints the patient's details, patient line or barcode. A Word or LibreOffice file has one page header for the whole file, so when a grievance form is among its documents the header's patient line is left blank, and the other documents are still filled in (from 0.38.0; before, the header named the patient). A PDF with one is made by mPDF, which gives each document its own header. Placeholders in footnotes and comments of your own documents aren't filled.
- Questionnaires and Patient Portal document templates don't print from here.

## Other modules for OpenEMR

From the same project. Each installs with `composer require anun333/<module>` and is then enabled in Modules › Manage Modules.

| Module | What it does |
|---|---|
| [UDS Report](https://github.com/anun333/oe-module-uds-report) | HRSA's Uniform Data System report for health centers: the patient, visit and diagnosis tables, eCQM readiness, a Table 9D worksheet and grant evidence |
| [Staff Worklist](https://github.com/anun333/oe-module-staff-worklist) | One queue of outstanding staff work: unsigned results, orders with no result, referrals with no reply, screening and claims follow-up |
| [Sliding Fee Discount](https://github.com/anun333/oe-module-sliding-fee) | HRSA's sliding fee discount program: the schedule checked against Chapter 9, income assessments, and the discount applied |
| [Care Management](https://github.com/anun333/oe-module-care-management) | CCM, PCM and BHI enrollment with CMS's consent elements, monthly time logging, and the codes each month meets |
| [Migration](https://github.com/anun333/oe-module-migration) | Records from another EHR, read from the FHIR export certified EHRs offer, checked before import and safe to rerun |
| [Layout Export](https://github.com/anun333/oe-module-layout-export) | Layouts and lists exported to files you can keep in version control, compared with the site or another file; reads the old Backup page's SQL files without running them |

## License

- **The code** (`src/`, `public/`, `tools/`, `tests/` and the module's wiring) is GPL-3.0-or-later, as OpenEMR is. See [LICENSE](LICENSE).
- **The documents written for Clinic Forms**, their translations, the packets and the lists of required elements (`data/`) are dedicated to the public domain under CC0 1.0 ([LICENSES/CC0-1.0.txt](LICENSES/CC0-1.0.txt)). They are forms to adapt and print: use them, change them and share them with no credit needed. Each document's own `"licence"` says which terms it is under.
- **Except what other people own**, which keeps its owners' terms. Each document names its holder and terms:
  - the screening instruments: PHQ-9, PHQ-4, PHQ-2, PHQ-15 and GAD-7 from Pfizer ("no permission required to reproduce, translate, display or distribute"); AUDIT-C from the WHO manual (not for sale); the Hunger Vital Sign™ from Children's HealthWatch (no fee or license, with its citation; the name is their trademark); HARK under CC BY 2.0 from its authors' open-access article (Sohal, Eldridge and Feder 2007), with that citation and licence on the page; the Everyday Discrimination Scale from David Williams (publicly available, no permission needed); the rest free to use: the VA's PC-PTSD-5 and PCL-5, CDC's Healthy Days, NIMH's ASQ, NIDA's TAPS and USDA's food security survey (US government works); NIDA's BSTAD, included unchanged with its citation (Kelly et al. 2014) by NIH's permission (Ask NIH, 2026-10-19); CDC's Stay Independent, which the Greater Los Angeles VA Geriatric Research Education Clinical Center developed (Rubenstein et al. 2011) and CDC adapted with the authors' permission, a credit it keeps on the page; and the GDS-15, which its developers placed in the public domain;
  - LOINC's codes, names and questionnaires in `data/instruments/`, under the [LOINC licence](https://loinc.org/license/) (Regenstrief Institute);

    > This material contains content from LOINC (http://loinc.org). LOINC is copyright © Regenstrief Institute, Inc. and the Logical Observation Identifiers Names and Codes (LOINC) Committee and is available at no cost under the license at http://loinc.org/license. LOINC® is a registered United States trademark of Regenstrief Institute, Inc.

  - the CIEL concept numbers in `data/ciel.json`, under CC BY 4.0 (Columbia International eHealth Laboratory, via Open Concept Lab).
