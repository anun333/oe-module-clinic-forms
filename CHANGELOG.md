# Changelog

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
