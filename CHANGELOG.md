# Changelog

## 0.3.0 (2026-10-15)

- **Check and correct.** A second box, *Also fill Demographics and History*, prints those forms with what the patient has on file: text and dates written in, options ticked, lifestyle status and notes, exam rows, providers, pharmacy and facility by name. A returning patient crosses out and corrects instead of filling everything in again. Social Security and driver license numbers print as their last four characters. History uses the latest History record. The audit event says which forms were filled.
- Stored values are read as OpenEMR's own screens read them (lists, multiple choice, list with comment, lifestyle, smoking status, textbox lists, exam results, checkboxes with text), option ids without regard to case.
- Checked end to end on 8.4.1.

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
