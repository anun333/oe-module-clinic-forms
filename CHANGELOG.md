# Changelog

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
