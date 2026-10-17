# Changelog

## 0.21.0 (2026-10-16)

**Documents as FHIR Questionnaires, to fill on screen.**

- **FHIR Questionnaire (.json)**, beside Word, LibreOffice and PDF. The ticked documents download as FHIR R4 Questionnaires: one file, or a Bundle of several.
  - **Blank forms.** They carry the clinic's details and never a patient's, even with a patient chosen and filling ticked: a field the chart fills is left out, since a response names its patient. No audit event is written.
  - **Coded instruments.** The validated instruments carry LOINC's question and answer codes, the published answer order, and each answer's points. Their total line adds the points up as the patient answers, in the FHIRPath that OpenEMR's own questionnaire runtime (8.3 and later) evaluates. LOINC's own questionnaires carry no points, and list PHQ-9's answers out of order.
  - **Translations.** A Spanish questionnaire shows its Spanish wording with the same codes, when it keeps its English instrument.
  - **Clinic versions.** A clinic's version that differs from its instrument carries no codes.
  - **The rest of a document:** paragraphs are text; headings are groups; fields, lines, tables and signature blocks are things to fill in; ticked statements are yes or no.
- **`tools/export-questionnaires.php`** writes every shipped document and translation as a Questionnaire.
- **How the questionnaires were checked.** All 68 (46 documents, 22 translations) pass:
  - **the HL7 FHIR validator**, with the SDC guide. It notes three things that are expected: answers show the form's wording rather than LOINC's display (a translation, or AUDIT-C's "2 to 4 times a month"); the ASQ's codes are newer than the terminology server's LOINC; and LOINC's questionnaires, named as each instrument's source, can't be fetched;
  - **OpenEMR 8.4.1's questionnaire import check**, the same strict one its import uses;
  - **OpenEMR's questionnaire runtime**, where every one compiles, and each of the 12 totals comes out right with the lowest-scoring answers and with the highest.

  The end-to-end check downloads them from the page, finds no patient details, and has OpenEMR's runtime work out the PHQ-9 total.
- **Documents changed in this release:** none.

## 0.20.0 (2026-10-16)

**USDA's six-item food security screen, and two screening packets.**

- **Food Security Screen (USDA Six-Item)**, in the US pack: USDA's six questions on whether the household could afford the food it needed in the last 12 months (public domain, May 2024 module). One point for each affirmative answer, as USDA's user notes code them, scored 0 to 6. It prints USDA's ranges: 2 or more is low food security (food insecure), 5 or more very low. It is self-administered, so "Don't know" is shown as an answer, as USDA's notes allow for paper.
- **A reference written by hand where LOINC's is incomplete.** LOINC's questionnaire for the six-item module leaves out the follow-up question on how often meals were cut, which the six items count. This reference is written from USDA's own module, with LOINC's codes (the follow-up's is in LOINC's 18-item form), and says whose instrument it is and why under `"questions_from"`. The reference tools leave it alone rather than overwrite it from LOINC.
- **Two screening packets:**
  - **Behavioral health screening:** PHQ-9, GAD-7, PC-PTSD-5 and TAPS-1.
  - **Older adult screening:** Stay Independent and GDS-15.

  The ASQ isn't in a packet, because a nurse or clinician asks it.
- **Documents changed in this release:** one new (version 1). No existing document changed.

## 0.19.0 (2026-10-16)

**Two screens read by rule rather than by a total, and a fix for the PDF's field labels.**

- **PDF field labels fixed.** Since 0.16.0, the PDF download and the copy saved to the patient's Documents printed each field label one letter to a line ("N a m e"), because mPDF lets a table cell's width override its no-wrap. Labels now sit on one line beside their value, with room to write. Checked on OpenEMR 8.4.1, whose mPDF (8.3.1) showed the fault. The browser print and the Word and LibreOffice files were not affected. A test and the end-to-end check now fail if a label and its value aren't side by side.
- **Ask Suicide-Screening Questions (ASQ)**, NIMH's suicide risk screen (the 2025 tool, public domain), for a nurse or clinician to ask patients aged 8 and over:
  - the four yes or no questions, the follow-up on a past attempt, and the acuity question;
  - NIMH's next steps for a negative, non-acute positive or acute positive screen, with its exceptions and what a refusal means for youth and for adults;
  - the 988 Lifeline and Crisis Text Line for every patient.

  A clinic's own version is warned if it loses "cannot leave until evaluated for safety", the brief suicide safety assessment, or the 988 line.
- **Substance Use Screen (TAPS-1)**, the first part of NIDA's TAPS Tool (public domain): how often in the past 12 months the patient used tobacco, alcohol (5 or more drinks in a day for men, 4 for women), drugs, and prescription medications not as prescribed. Any answer but Never is a positive screen for that substance (McNeely 2016). Monthly or more for tobacco or alcohol, or any drug use or prescription misuse, is the published cut-point for a possible substance use disorder (Gryczynski 2017).
- **Rules in the instrument check.** A reference can hold its instrument's published rules in place of cut-offs (`"rules"`: which questions, which answers, any or all, and what the result means), with their source. The document must state each rule's meaning in a paragraph that names its questions by the numbers the page prints. Dropping the ASQ's acute line, or saying "questions 1 to 3", is reported.
- **More of LOINC's faults, handled.** LOINC has TAPS-1's prescription question as free text with no answers, gives three of its questions another instrument's answer list ("Once or twice"), and lists the drugs question's answers backwards. A reference can now give a question another's answers (`"answers_from"`) and put answers in the published order (`"order"`), with the source. LOINC's free-text items (the ASQ's "describe briefly") are kept apart from the questions, and the importer gives them lines. `--audience staff` imports an instrument a clinician asks.
- **`tools/instrument-references.php` keeps every hand-written field.** It had been dropping the title, description and instructions that the importer writes.
- **Documents changed in this release:** two new, each at version 1. No existing document changed.

## 0.18.0 (2026-10-16)

**Three more screening instruments, all public domain, each scored as published and checked against its reference.**

- **Primary Care PTSD Screen (PC-PTSD-5)**, from the National Center for PTSD's 2022 form: a question about a traumatic event, then five yes or no questions, scored 0 to 5. It prints the National Center's cut-point of 4, with its note about women.
- **Geriatric Depression Scale, Short Form (GDS-15)**, in its developers' wording, scored as their key marks it (No scores on questions 1, 5, 7, 11 and 13, Yes on the rest). It prints their cut-offs: more than 5 suggests depression, more than 10 is almost always depression.
- **Check Your Risk for Falling (Stay Independent)**, CDC's STEADI checklist, word for word from the brochure, with its 2-point first two statements. It prints its cut-off of 4 points or more.
- **Points per question in a scale** (`"item_scores"`), for instruments whose questions score their answers differently. The page shows each box's points, and so do the Word, LibreOffice and PDF files. Validation, translations and the instrument check all follow it.
- **The published scoring, where LOINC's differs.** A reference can now correct LOINC's answer scores under `"scoring"`, with the source and the sentence that states it.
  - LOINC scores PC-PTSD-5's trauma question, leaves STEADI's first statement unscored, and scores no GDS-15 answer at all.
  - The tools refuse an instrument with unscored answers until its scoring is written.
  - A cut-off may now cite the instrument owner's own document (CDC's brochure, the developers' scoring sheet) as well as a paper.
- **`tools/import-instrument.php`** brings an instrument in from LOINC: its reference, and a draft document built from LOINC's questions. Shared answer lists become a scale, others checklists, followed by the total line and the published cut-offs. The draft is compared with the instrument's printed form before it is made active. All three here were compared, and two of PC-PTSD-5's questions follow the VA's form rather than LOINC's (recorded, with the reason).
- **Documents changed in this release:** three new, each at version 1. No existing document changed.

## 0.17.0 (2026-10-16)

**A review of all 40 documents before 1.0.** Every document was read for errors of fact or law, patient safety, consistency and clarity. This release fixes what that review found to be wrong or unsafe, and most of what it found inconsistent or unclear. It is not a lawyer's or clinician's sign-off: have the documents reviewed for your state before relying on them, as before.

- **Patient safety:**
  - **PHQ-9.** The office note now covers question 9 left blank, and a patient who has already left: a clinician asks the same day, or contacts them today. Under the questions, the patient is told to tell staff now if they are thinking about hurting themselves, and to call their local emergency number if in danger.
  - **Controlled Medicine Agreement.** Not mixing the medicine with alcohol, sleeping pills or anxiety medicines; what to do for someone who can't be woken or is breathing very slowly (naloxone, and the emergency number); how early to ask for refills; and the representative fields.
  - **Telehealth.** The patient says where they are at each visit, and the clinic may send help there in an emergency. For an emergency, call the local emergency number rather than wait. Added a person to call in an emergency. The location field no longer assumes a US state. "The visit is private" is now "The clinic uses a private connection".
  - **Downtime visit note.** The date is left to fill in (packets are printed before an outage), and allergies are asked.
- **What a clinic could be held to:**
  - **Financial Policy.** Payment for uncovered care is "as my insurance contract and the law allow" (Medicaid and network rules limit it), and a discount may lower it.
  - **Request for Records.** It no longer calls a bare signature an authorization: the patient agrees, and a signed authorization is attached when the law requires one. Added who is asking, and the representative fields.
  - **Sliding Fee Discount Application.** No longer says the facts are "used only" to decide the discount: they also feed the clinic's required reports, without the name.
  - **Notice of Privacy Practices.** Says when more time may be needed for a copy or a correction. The free accounting is "the first list in any 12 months", as the request form says. The Office for Civil Rights contact is now its complaint portal and current number. The Part 2 paragraph covers records the clinic creates as well as receives.
  - **Request for a List of Disclosures.** The 30-day extension.
  - **Part 2 notice.** The self-pay restriction is limited to payment and operations, unless a law requires sharing. The court-case sentence no longer says "never" and then "only".
  - **Personal Representative.** Speaks of "the patient", not "you", and treats the representative as the patient within their authority. "A minor child" replaces "under 18", since the age of majority differs by state. It points to the right forms for talking with family and for sending records.
- **The grievance form can now be anonymous.**
  - A new document flag, `"anonymous": true`, prints a document with no patient details, no patient line and no barcode, even when printed for a patient: in the browser and as a PDF.
  - The grievance form uses it, so its "you may leave your name off" is true. It also names the Section 1557 Coordinator and gives the Office for Civil Rights TDD line and its usual 180 days.
- **AUDIT-C follows the WHO AUDIT it cites.**
  - Question 2 is WHO's "on a typical day when you are drinking", as the Spanish version (WHO's Spanish manual) already was.
  - A line says what one drink is, and a patient who answers Never stops after question 1.
  - The instrument reference records the difference from LOINC's wording, with its reason.
- **Clearer:**
  - **Results letter.** "Nothing more to do about these results". For follow-up, the clinic calls if it hasn't heard back.
  - **Work or School Note.** The parent or caregiver who came is named. Dates are "from" and "to".
  - **Interpreter Request.** Help is "free of charge", and the staff choice is "qualified bilingual staff member".
  - **Communication Preferences.** Mail to the home address, or another address.
  - **Procedure consent.** Filed with the front-desk consents. The clinician is no longer prefilled. Representative fields added.
  - **Visit summary.** The medicine list says it was "on file when this was printed".
  - **Sliding fee notice.** Defines the household as the application does, and allows a small set fee at full discount.
- **Spanish brought current** for every changed document that has a Spanish version: PHQ-9, AUDIT-C, telehealth, financial policy, communication preferences, work or school note, sliding fee application and notice, Notice of Privacy Practices, request for a list of disclosures. The work or school note's Spanish, out of date since 0.13.0, is current again.
- **Documents changed in this release:** 20, each one version up. PHQ-9 is now version 3, the rest version 2.

## 0.16.1 (2026-10-16)

- `FOOTPRINT.md` regenerated: 0.16.0's was made before its last test was added, so its line count was 5 short and the CI check failed. No change to what prints or saves.

## 0.16.0 (2026-10-16)

Two of the items left before 1.0: a way back from paper to the chart, and a copy in the chart.

- **The chart number at the foot of every printed page.**
  - Printed with a patient, each page carries the patient's chart number (`pubpid`, the number OpenEMR's own patient barcode labels encode) as a Code 128 barcode, beside the document's id and version.
  - The encoder is written here, with no new dependency. Its test reads the barcodes back with zbar, an independent reader, and CI installs zbar for it.
  - End to end on OpenEMR 8.4.1, a filled print of every document, printed to PDF by Chromium and read at 300 dpi, carried the chart number on every page except the two posters (53 of 56).
- **A box for a patient label** (`"label_box": true` in `settings.json`), the size of a standard label, at the top of a blank document's first page. Off unless set.
- **PDF, and Save a PDF copy in the patient chart.**
  - The ticked documents download as one PDF, or are filed, filled for the patient, in the patient's Documents. They go under *Medical Record* (or `"documents_category"` in `settings.json`), through OpenEMR's own `Document` class, as the Patient Portal's PDFs are.
  - The PDF repeats the patient line and title at the top of every page, and the version and barcode at the foot.
  - It's written with the mPDF that OpenEMR ships: the printed page's flexbox-and-table layout doesn't suit mPDF, so it has its own writer, beside the Word and LibreOffice ones.
  - Saving needs `patients/docs` write or add-only, and writes a `patient-record-insert` audit event naming each document's version.
  - Checked on OpenEMR 7.0.3, 8.4.1 and 8.5.0. On 8.4.1, the stored copy, read back through OpenEMR's document viewer (OpenEMR encrypts it on disk), had the documents and a barcode a scanner reads.
- **`FOOTPRINT.md`:**
  - It now names writes made through OpenEMR's own classes (`documents` and `categories_to_documents`, through `Document::createDocument()`), not only the module's own SQL.
  - It reads a permission check that accepts a list of levels.
- **Documents changed in this release:** none.

## 0.15.0 (2026-10-16)

- **The PHQ-9 and GAD-7 print what a score means.** Their office-use section gives the published severity bands, in English and Spanish:
  - PHQ-9: 5, 10, 15 and 20 for mild, moderate, moderately severe and severe depression, and 10 or more finding major depression with 88% sensitivity and specificity (Kroenke 2001);
  - GAD-7: 5, 10 and 15 for mild, moderate and severe anxiety, with 10 as the usual point to assess for generalized anxiety disorder (Spitzer 2006).

  The instrument check holds the printed numbers to the papers.
- **A clinic's own version of a validated instrument is checked.** This covers its own file with a shipped instrument's id, even without "validated", and its own validated document with a LOINC code there is a reference for. The Clinic Forms page names what differs: questions, answers, points, the total range, or a printed cut-off. A clinic's own Spanish version is held to its English.
  - A changed column of a scale is named once, for the questions it affects ("questions 1 to 9, answer 2 is…").
- **Documents changed in this release:** PHQ-9 and GAD-7 (version 2, English and Spanish).

## 0.14.0 (2026-10-16)

**Validated instruments are now checked for what makes their scores valid, not only their wording.**

- **`data/instruments/<loinc>.json`** holds a reference for PHQ-9 (44249-1), PHQ-2 (55757-9), GAD-7 (69737-5) and AUDIT-C (72109-2):
  - their questions and answers from LOINC's own questionnaire (LOINC 2.83), with each answer's points from LOINC's answer lists;
  - the total range;
  - the published cut-offs, with their paper and the sentence that states them. These are Kroenke 2001 (PHQ-9), Spitzer 2006 (GAD-7), Kroenke 2003 (PHQ-2) and Bradley 2007 (AUDIT-C).
- **The tests fail** when a validated document's questions, their order, its answers, their points, its total range, its stated range ("scored 0 to 27") or a cut-off it prints differ from the reference, or when a translation changes the number of questions, the answers, the points or the numbers in its text.
  - They also show the check catches the faults found in other projects' copies: another instrument's question (KenyaEMR's GAD-7 asks PHQ-9's item 6), an added answer (AMRS's "0 drinks" on AUDIT-C), a changed point, a wrong cut-off, and a translation that rescores.
- **Two deliberate differences** are recorded with their reasons. GAD-7's question 2 follows Pfizer's printed form, where LOINC writes the time frame into that item. PHQ-9 and GAD-7 end with Pfizer's unscored "If you checked off any problems, how difficult…", which LOINC words differently for PHQ-9 and leaves out of its GAD-7 panel.
- **`tools/instrument-references.php`** refreshes the references from fhir.loinc.org and NLM's Clinical Tables, keeping what was written by hand; `--check` reports whether LOINC has changed.
  - Where a question's own answer list differs from the panel's (GAD-7's 68509-9 defaults to "More days than not"), the panel's answer is the instrument's, scored as it is everywhere else in the panel.
- **Documents changed in this release:** none. All four instruments and their Spanish versions already matched.

## 0.13.1 (2026-10-16)

- `FOOTPRINT.md` regenerated: the 0.13.0 tag carried one made before its last edits, so its CI check failed. No change to what prints.

## 0.13.0 (2026-10-16)

12 new documents, completing the documents planned for 1.0. 40 documents in all: 22 core, 18 in the US pack.

- **US pack:**
  - **Notice of Privacy Practices for Substance Use Disorder Records**, with every element 42 CFR 2.22 requires since the 2024 revision: the required header, each use allowed without consent, the court-order limits, the patient's rights and the program's duties.
  - **Revoking a Part 2 consent** (2.31(a)(6)).
  - **Personal Representative** (45 CFR 164.502(g), with the identity and authority check of 164.514(h)).
  - **Patient Concern or Grievance** (what 45 CFR 92.8(c) asks the record to hold).
  - **Encounter Form (Superbill)**.
  - Each rule-based document is held to its elements in `requirements.json`.
- **Core:**
  - Your Rights and Responsibilities;
  - Missed Appointments;
  - Language Help and Interpreter Request;
  - Consent for a Procedure (LOINC 64293-4, held to its informed-consent elements);
  - Controlled Medicine Agreement (94136-9);
  - Your Test Results;
  - Visit Summary and Instructions (103796-9).
- LOINC codes were checked as active on LOINC's own terminology server.
- **Packets:** patient rights and the missed appointment policy join the new-patient packets. The US pack adds a substance use treatment intake packet.
- **No Spanish yet** for the 11 new patient documents: they print in English until the language pass. The tests list them as incomplete rather than failing.
- **Documents changed in this release:** 12 new, each at version 1. No existing document's version moved.

## 0.12.0 (2026-10-16)

- **Every document has a version, status, source and licence.** The version prints small at the foot of every page with the document's id (`consent-to-treat v1`), in Word and LibreOffice downloads too, and goes in the audit event, so a signed copy names the wording signed. `data/document-versions.json` records each version's wording, and the tests fail when a document's wording changes without a new version (`tools/document-versions.php`). The source says whether the wording is ours, written to a rule (linked), or a published instrument; the licence names its holder, terms and the permission they give.
- **Status:** `draft` documents are offered with a Draft badge and print "draft"; `retired` ones are no longer offered and leave their packets.
- **A clinic's own versions say what they were copied from** (`based_on`). The page warns when the module's version has changed since, names the file to compare, and gives the new value; for versions that don't say, it shows the line to add.
- **Hide a shipped document** in `settings.json` (`"hide"`); it leaves the picker and the packets.
- **Packs carry their ISO 3166 code** (`jurisdiction`), and the page shows the region's (US-TX).
- New checks: no Spanish string left in English, no phone, email or street address in shipped files other than HHS's official ones, and no quotes inside strings passed to OpenEMR's translation (it prints them as backticks).
- A `docs/` folder: principles, the document format, writing a country pack, releasing.
- Documents changed in this release: all 28 are at version 1, the first versioned release.

## 0.11.0 (2026-10-16)

- **Country packs.** The documents that apply anywhere stay in `data/documents/` (the core); the United States' move to a **US pack** in `data/packs/us/`: the HIPAA privacy set, the Part 2 consent, the Section 1557 notices and taglines, the HRSA sliding fee documents and the UDS form, with their legal checks (`requirements.json`) and the packets that use them. Document ids are unchanged, so a clinic's own versions still replace them.
- **Which pack:** `settings.json` in the Clinic Forms folder (`{"country": "us"}` or `{"country": "none"}`), else the first facility's country. A blank country, as a default install leaves it, is the US, so nothing changes for existing sites. Another country gets the core documents and a note on the page.
- **Regions:** the facility's State picks its region. The page now lists, for each facility's state, which of the 15 languages most spoken there by people with limited English (Census, 2019–2023) have no tagline yet. A region can also carry its own documents (none ship yet).
- Layers apply in order (core, pack, region, the clinic's own), each replacing the one before by id; a translation of a replaced document is out of date and prints in English.
- The paper size starts on the pack's (Letter for the US, A4 without a pack).
- The page's help text no longer passes quotes through OpenEMR's translation, which printed them as backticks.

## 0.10.0 (2026-10-16)

- **Old Spanish is never printed for new English.** Each translation records the English it was made from (`translates`, a fingerprint). When the English changes, the document prints in English and the page lists it as out of date until its Spanish is updated. This also fixes a clinic's own English version of a document printing with the shipped Spanish of the original.
- **Letters carry the signing provider.** New placeholders `{provider.name}`, `{provider.credentials}`, `{provider.npi}` and `{provider.specialty}`, and a **Signing provider** choice that defaults to the clinician printing. The referral letter prints them under the signature, and the work or school note prints the clinician's name and credentials. Its Spanish is out of date until the next language update, so it prints in English meanwhile.
- **Paper in entry-screen order.** Fields sharing a position number now print in the order the screens show them (two UDS fields had printed one place early). Fields in the For office use box name the screen tab they're typed into. An apostrophe that OpenEMR's translation shows as a backtick ("Mother`s Name") prints as an apostrophe.
- Checked end to end on 8.4.1, including that a user who isn't a provider can't be chosen as the signer.

## 0.9.0 (2026-10-16)

- **Runs on OpenEMR 7.0.** The calls that changed between 7.0 and 8.x (settings and web root, the session, CSRF tokens, the access-denied page, the audit logger) go through one adapter, `src/Compat/OpenEmr.php`, which uses the newer call when it exists. A print without the CSRF token is refused with 403 on every release (7.0 answered 200).
- **PHP 8.2 or later**, down from 8.3; CI now tests 8.2 to 8.5.
- **Fewer audit rows.** The long-list counts for a form are one query instead of one per list, so printing Demographics adds one row to OpenEMR's query audit log instead of about ten.
- Checked on the official 7.0.2, 7.0.3, 8.1.0 and 8.5.0 images, and end to end on 8.4.1.

## 0.8.0 (2026-10-16)

- **Spanish.** A language choice on the page; all 22 patient documents have Spanish versions, and the words Clinic Forms prints follow the paper's language. PHQ-9, PHQ-2 and GAD-7 use the official "Spanish for the USA" wording (phqscreeners.com), and AUDIT-C uses the WHO's Spanish AUDIT (its misprinted score for "10 o más" corrected to 4). The rest are our translations, for review. Translations are checked against their English documents (blocks, fields, items, signers, placeholders), and key legal wording is checked in Spanish. Clinics can add their own in `es/`.
- **Section 1557.** A Notice of Nondiscrimination (45 CFR 92.10) and the notice of availability (92.11), in English and Spanish, print as 20-point posters with no patient line. Patient documents and intake forms carry the language assistance taglines, English and Spanish shipped, with the state's other languages added in `taglines.json`.
- Not used: OpenEMR's own Spanish layout labels, which translate "Ethnicity" as "Raza".
- Checked end to end on 8.4.1 (Spanish print and Word export, taglines, posters), and on 8.1.0 and 8.5.0 (official images).

## 0.7.0 (2026-10-16)

- **A short UDS form**: the Demographics fields UDS Tables 3A, 3B and 4 read, and nothing else, with the site's own lists, so the paper matches what staff type in. It uses the UDS Report module's fields when the site has them (and drops OpenEMR's older free-text housing and farmworker fields then), and "check and correct" fills it too. New packet: Annual update (short, with UDS questions).
- **We Offer Discounts Based on Income**: the sliding fee notice health centers must give, with a table for the clinic's own schedule and the statement that no one is denied care for inability to pay.
- **PHQ-2 and AUDIT-C**, validated wording and scoring; new packet: Quick screening.
- Checked end to end on 8.4.1, and on 8.1.0 and 8.5.0 (official images).

## 0.6.0 (2026-10-16)

- **Notice of Privacy Practices**, element by element from 45 CFR 164.520(b)(1), with the 42 CFR Part 2 statements notices need from February 16, 2026. The 2024 reproductive health statements, vacated in *Purl v. HHS* (2025), are left out, and a test keeps them out. It is part of New patient intake, ahead of the acknowledgment.
- **Patient rights requests:** see or get a copy of my record (164.524, including sending it to someone the patient names), correct my record (164.526), a list of disclosures (164.528), limit sharing (164.522(a), including self-paid care kept from the health plan), and revoking an authorization (164.508(b)(5)). Each states the response time and fees the rule allows.
- **42 CFR Part 2 consent** with every element of 2.31 as amended in 2024, including the single consent for treatment, payment and health care operations, the redisclosure statements, the consequences of not signing, and separate consent for counseling notes and legal proceedings.
- Each is held to its rule in `data/requirements.json`; every element was written from the current regulation text at eCFR, read on 2026-10-16. All read at 8th grade or below.
- Checked end to end on 8.4.1, and on 8.1.0 and 8.5.0 (official images).

## 0.5.0 (2026-10-16)

- **Standards.** Documents carry a LOINC document code where an exact one exists (checked by its check digit), who reads them (patient, staff, provider), and whether their wording is a validated instrument's.
- **Required elements checked.** `data/requirements.json` holds each regulated document to its rule (HIPAA authorization 164.508(c), privacy acknowledgment 164.520, sliding fee application, minimum necessary on the work note, PHQ-9 and GAD-7 wording); tests fail if a shipped document loses an element, and a clinic's own version gets a warning naming what wasn't found.
- **Plainer wording.** Consent to Treatment (grade 11.1 → 4.6), the release of information (8.6 → 7.9) and the financial policy (8.3 → 5.6) were rewritten; every patient document now reads at 8th grade or below, and tests measure it. The release now tells the patient they'll get a copy (164.508(c)(4)).
- Checked end to end on 8.4.1 (including the warning for a clinic's release form missing its expiration), and on 8.1.0 and 8.5.0 (official images).

## 0.4.0 (2026-10-15)

- **Word and LibreOffice.** The ticked documents download as one Word (.docx) or LibreOffice (.odt) file, filled as the printed packet would be, to edit before printing; the patient line is in each page's header. Written directly by the module: nothing is installed on the server.
- **Your own documents in Word or LibreOffice.** Administrators add .docx and .odt files with placeholders; staff download them filled with the letterhead, Address Book recipient and patient. OpenEMR's Document Templates tags (`{PatientName}`…) work too. Placeholders that Word or LibreOffice stored in pieces are filled, formatting kept; unknown ones are listed when the file is added and left as typed. Files are checked (a real document of its type, at most 5 MB) and kept in the site folder.
- **New placeholders:** the patient's street, city, state, ZIP and sex; current allergies, medications and problems; `{user.name}`, the person printing.
- FOOTPRINT.md lists the files the module writes. Needs PHP's zip and DOM extensions, which OpenEMR's images have.
- Checked end to end on 8.4.1 (exports and filled templates opened and rendered by LibreOffice 24.2), and on 8.1.0 and 8.5.0 (official images).

## 0.3.0 (2026-10-15)

- **Check and correct.** A second box, *Also fill Demographics and History*, prints those forms with what the patient has on file: text and dates written in, options ticked, lifestyle status and notes, exam rows, providers, pharmacy and facility by name. A returning patient crosses out and corrects instead of filling everything in again. Social Security and driver license numbers print as their last four characters. History uses the latest History record. The audit event says which forms were filled.
- Stored values are read as OpenEMR's own screens read them (lists, multiple choice, list with comment, lifestyle, smoking status, textbox lists, exam results, checkboxes with text), option ids without regard to case.
- Checked end to end on 8.4.1, and on 8.1.0 and 8.5.0 (official images).

## 0.2.0 (2026-10-15)

- **Four documents:** a Sliding Fee Discount Application (household members, income and proof, a clinic section for the poverty-guideline percentage and discount level); the PHQ-9 and GAD-7 screening questionnaires; and a Work or School Note that doesn't give the reason for the visit.
- **Two block types** for your own documents: `scale` (a question per row, a box per answer, optional scores and total) and `table` (column headings over blank rows).
- **Packets:** the sliding fee application joins New patient intake and Annual update; a new Depression and anxiety screening packet.
- A document that ended at the foot of a page no longer adds an empty page.
- Checked end to end on 8.4.1.

## 0.1.1 (2026-10-15)

- **Every printed page names the patient.** A line for name, date of birth and ID (filled when a patient is chosen) repeats at the top of each page, so a loose page of a multi-page form can be matched to a chart.
- **Long lists print useful choices.** A list of more than 24 options (language, country, religion) printed as a blank line. It now prints the options this clinic's patients have, most used first, and an Other line. Counts only; no patient's answer is read.
- **Clinic packets.** `packets.json` in `sites/<site>/documents/clinic-forms/` adds packets or replaces shipped ones; a file with a mistake is reported and the shipped packets stay.
- Checked on OpenEMR 8.1.0 and 8.5.0 (official images) and end to end on 8.4.1.

## 0.1.0 (2026-10-15)

First version.

- Twelve printable documents (front desk consents and acknowledgments, a HIPAA release, a referral letter and records request, office workflow slips and a downtime visit note), filled with the facility's details and, optionally, the open patient and an Address Book recipient.
- Paper versions of the Demographics, History, encounter (LBF) and transaction (LBT) layouts, with a blank shaped for each field type, and the clinic's own Demographics fields in a "For office use" box.
- Ready-made packets: new patient intake, annual update, telehealth, referral and a downtime kit.
- A site folder for the clinic's own documents (`sites/<site>/documents/clinic-forms/`), which can add to or replace the shipped ones and survives module updates.
- Printing through OpenEMR's print log; an audit event when patient details are printed.
- `FOOTPRINT.md`, generated from the source and checked in CI.
- Checked on OpenEMR 8.1.0 to 8.5.0 (official images) and 8.4.1 in depth.
