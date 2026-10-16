# Clinic Forms for OpenEMR

Printable clinic paperwork, with the clinic's own name, address, phone, fax and logo filled in. Each piece can be printed blank to fill in by hand, or with the current patient's details filled in.

**Status: 0.13.0, a pre-release.** Checked on OpenEMR 7.0.2 to 8.5.0, as an administrator, with synthetic data (see [Compatibility](#compatibility)). The consents and releases are starting points: have them reviewed for your state before you use them (see [The documents are starting points](#the-documents-are-starting-points)).

It does three things:

- **Paperwork.** Consents, releases, referral letters and office slips, filled from the facility record and, optionally, the open patient and an Address Book entry.
- **Paper versions of layout forms.** Demographics, History, any active encounter form (LBF) and any transaction form (Referral and the like), printed as a blank form with tick boxes, date boxes and ruled lines. With the US pack, a short **UDS form** prints only the Demographics fields health center reporting reads (sex, gender identity and sexual orientation, race, ethnicity, language, household size and income, housing, agricultural work, military service), with the site's own answer lists. Clinics without a computer in every room can do intake on paper and type it in later, in the same order.
- **Ready-made packets.** New patient intake, annual update, telehealth, referral, depression and anxiety screening, and a downtime kit, each opened as one document to print.
- **Spanish.** Every patient document prints in Spanish too, with the language assistance notices Section 1557 requires (see [Spanish and language assistance](#spanish-and-language-assistance)).
- **Word and LibreOffice.** Any document downloads as a filled Word (.docx) or LibreOffice (.odt) file to edit before printing, and a clinic can write its own letters and forms in Word or LibreOffice and have them filled in (see [Word and LibreOffice](#word-and-libreoffice)).

It stores nothing and changes nothing in the database. Every page is built when it's opened, from the site's own facility, Address Book and layout settings.

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
| Front desk | Authorization to Release Health Information | A HIPAA authorization with the elements 45 CFR 164.508 requires, separate initials for sensitive records, and a 42 CFR Part 2 note | 64292-6 | US |
| Front desk | Your Rights and Responsibilities | What patients can expect, and what the clinic asks of them | — | any |
| Front desk | Missed Appointments | The missed and late appointment policy, with help offered (rides, child care) | — | any |
| Front desk | Language Help and Interpreter Request | The patient's languages and the help they want, with a staff section for what was arranged | — | any |
| Front desk | Communication Preferences | How the clinic may contact the patient: phone, voicemail, text, email, portal | 59284-0 | any |
| Front desk | Consent for Telehealth Visits | Consent to visits by video or telephone | 59284-0 | any |
| Front desk | We Offer Discounts Based on Income | A notice to post or hand out: the sliding fee discount, who qualifies, and that no one is turned away; the clinic writes in its own schedule | — | US |
| Front desk | Sliding Fee Discount Application | Household members and income, proof attached, and a clinic section for the poverty-guideline percentage and discount level (the facts UDS Table 4 reports) | — | US |
| Screening | Patient Health Questionnaire (PHQ-2) | The two-question depression screen, scored 0 to 6 | 55757-9 | any |
| Screening | Alcohol Use Screen (AUDIT-C) | The WHO AUDIT's first three questions, scored 0 to 12 | 72109-2 | any |
| Screening | Patient Health Questionnaire (PHQ-9) | Depression screening, scored 0 to 27, with an office prompt to follow the clinic's safety protocol on question 9 | 44249-1 | any |
| Screening | Generalized Anxiety Disorder Scale (GAD-7) | Anxiety screening, scored 0 to 21 | 69737-5 | any |
| Care coordination | Referral letter | To the Address Book recipient, with urgency, the question to answer and what is enclosed | 57133-1 | any |
| Care coordination | Request for Records | A request to another provider for a patient's records | — | any |
| Office workflow | Patient Routing Slip | Travels with the patient: check-in, rooming, provider, checkout | — | any |
| Office workflow | Staff Handoff (SBAR) | Handing a patient from one staff member, shift or site to another | — | any |
| Office workflow | Internal Referral | To behavioral health, care management, nutrition, social work or another of the clinic's sites | 57133-1 | any |
| Downtime | Encounter Form (Superbill) | One visit's charges on paper: CPT or HCPCS services, ICD-10-CM diagnoses, sliding fee level, payment | — | US |
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
| After the visit | Your Test Results | A results letter: each result and what it means, what to do next, signed by the clinician | — | any |
| After the visit | Visit Summary and Instructions | What was found, medicines on file and changed today, what to do at home, tests ordered, the next visit | 103796-9 | any |
| After the visit | Consent for a Procedure | Informed consent for one procedure: why, benefits, risks, other choices, questions answered | 64293-4 | any |
| After the visit | Controlled Medicine Agreement | Patient and clinician agree how a controlled medicine is taken, kept and refilled, and what the clinic will do (taper plans, help with addiction, naloxone) | 94136-9 | any |
| After the visit | Work or School Note | Confirms the visit and when the patient may return, without giving the reason for the visit | — | any |

The format, and the rules every document follows, are in [docs/](docs/README.md). Each is a JSON file, the same on every OpenEMR release: in `data/documents/` if it applies anywhere, or in `data/packs/us/documents/` if it belongs to United States law or programmes (the **Pack** column; see [Country packs](#country-packs)). The short UDS form is the US pack's too. The PHQ-9 and GAD-7 are printed with their authors' statement that no permission is needed to reproduce them. Their answers can be typed into OpenEMR's own PHQ-9 and GAD-7 encounter forms, which ship with OpenEMR but have to be enabled under **Administration › Forms › Forms Administration**.

### Standards the documents follow

- **LOINC document codes** where an exact one exists (the table above), as OpenEMR's own C-CDA referral uses 57133-1. Each code is checked by its LOINC check digit; a document with no exact code is left uncoded rather than mislabeled.
- **What the law says goes in.** `data/requirements.json` (the instruments' wording) and the US pack's `data/packs/us/requirements.json` list, for the documents a rule governs, each required element and its citation: the Notice of Privacy Practices (164.520(b)(1), element by element), the patient-rights requests (164.522, 164.524, 164.526, 164.528, 164.508(b)(5)), the Part 2 consent (42 CFR 2.31), the HIPAA authorization (45 CFR 164.508(c)), the privacy notice acknowledgment (164.520(c)(2)(ii)), the sliding fee application (HRSA Compliance Manual, chapter 9), the work or school note's minimum necessary (164.502(b)), and the PHQ-9 and GAD-7 wording. The tests fail if a shipped document loses one. A clinic's own version of one of these documents is checked too, and the Clinic Forms page names anything it can't find (by wording, so check before relying on the warning).
- **Plain language.** Every patient document reads at an 8th-grade level or below (Flesch-Kincaid), as AHRQ's health literacy toolkit advises; the tests measure it. Validated instruments (PHQ-9, GAD-7) keep their published wording and are exempt.
- Each document says who reads it (patient, staff or provider) and whether its wording is a validated instrument's.
- **Each document has a version**, printed small at the foot of every page with its id (`consent-to-treat v1`) and recorded in the audit event, so a signed copy names the wording the patient signed. A version means one wording: the tests fail if a document's wording changes without its version being raised.
- **Each document says where its wording comes from and on what terms:** written for Clinic Forms, written to a rule's elements (with a link to the rule), or a published instrument (with its holder, terms and the permission they give: PHQ-9, PHQ-2 and GAD-7 from Pfizer, "no permission required"; AUDIT-C from the WHO manual).

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
- `settings.json` in the same folder chooses the country pack and hides shipped documents: `{"country": "us", "hide": ["financial-policy"]}` (both optional).
- Your own packets go in `packets.json` in the same folder, in the format of `data/packets.json`. A packet with the id of a shipped one replaces it. A file with a mistake is left out and reported; the shipped packets stay.

The format is in `src/Paper/Document.php`. Blocks are headings, paragraphs, fields, checklists, ruled lines, signature lines, scales (a question per row and a box per answer, optionally scored, as the PHQ-9 is) and tables (column headings over blank rows, as the household list is). Text can use these placeholders, which print as a blank line when the value isn't known:

| Group | Placeholders |
|---|---|
| `facility` | `{facility.name}` `{facility.address}` `{facility.phone}` `{facility.fax}` `{facility.email}` `{facility.website}` `{facility.npi}` |
| `patient` | `{patient.name}` `{patient.dob}` `{patient.id}` `{patient.address}` `{patient.street}` `{patient.city}` `{patient.state}` `{patient.zip}` `{patient.phone}` `{patient.sex}` `{patient.allergies}` `{patient.medications}` `{patient.problems}` (current allergies, medications and problems, as OpenEMR's issue lists hold them) |
| `recipient` | `{recipient.name}` `{recipient.organization}` `{recipient.specialty}` `{recipient.address}` `{recipient.phone}` `{recipient.fax}` `{recipient.email}` |
| `user` | `{user.name}` (the person printing) |
| `provider` | `{provider.name}` `{provider.credentials}` `{provider.npi}` `{provider.specialty}`: the **Signing provider** chosen at the top of the page. It defaults to the clinician printing, or else the patient's own provider. Credentials are the user's *Valedictory* (MD, NP) under Administration › Users |
| `clinic` | `{clinic.today}` |

## Spanish and language assistance

Choose **Español** as the language at the top of the Clinic Forms page. Documents marked **ES** print in Spanish; staff documents and the rest stay in English. The words Clinic Forms prints itself (Phone, Date, the patient line) follow the paper's language, not the language you use OpenEMR in. Word and LibreOffice downloads follow it too.

- **22 of the 33 patient documents** have Spanish versions, in the `es/` folder beside each (`data/documents/es/`, `data/packs/us/documents/es/`). The 11 added in 0.13.0, and the work or school note changed in 0.10.0, print in English until the next language update.
- **The validated questionnaires use their official Spanish wording.** PHQ-9, PHQ-2 and GAD-7 are the "Spanish for the USA" versions from phqscreeners.com. AUDIT-C is from the WHO's Spanish AUDIT manual.
- **The other translations are ours.** Have a qualified translator review them before use, as you would the English.
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

## Printing

Printing is the browser's own: no print server and nothing to install. When **Administration › Config › Logging › Printing Log Option** is on, the Print button goes through OpenEMR's print logging, like the rest of OpenEMR.

## Install

```
composer require anun333/oe-module-clinic-forms
```

Run it in the OpenEMR directory. It installs into `interface/modules/custom_modules/oe-module-clinic-forms/`. Then, under **Modules › Manage Modules**, register, install and enable **Clinic Forms**. There is no install SQL.

## Access

As written in the code, not verified for restricted users:

- The menu entry, the page, printing and downloading need **Patients › Demographics** (`patients/demo`), view.
- Adding and removing your own Word and LibreOffice documents needs **Administration › Superuser** (`admin/super`).
- Printing, downloading and adding accept only a form posted from the Clinic Forms page (CSRF token).
- Printing or downloading with a patient's details filled in writes a `patient-record-select` event to OpenEMR's audit log, naming the patient and the documents. Blank printing writes nothing. Adding or removing a document writes an `other` event.

[FOOTPRINT.md](FOOTPRINT.md), generated from the source, lists everything it reads, the OpenEMR functions it calls and what it hooks.

## Compatibility

| OpenEMR | Checked | How |
|---|---|---|
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
- Nothing is faxed, emailed or saved to the patient's Documents, and nothing is scanned back.
- Word and LibreOffice downloads use plain tick boxes (☐) and no logo, and layout forms aren't included. Placeholders in footnotes and comments of your own documents aren't filled.
- Questionnaires and Patient Portal document templates don't print from here.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
