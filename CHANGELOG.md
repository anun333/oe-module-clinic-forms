# Changelog

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
